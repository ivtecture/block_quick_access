<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Data service for the "Task analytics" block.
 *
 * Collects mod_assign + mod_quiz entries across all enrolled courses of the
 * current user. Students see their own submission state, teachers see
 * aggregates waiting for grading.
 *
 * Item structure returned by get_overview() / get_assign_data() / get_quiz_data():
 * [
 *   'courseid'       => int,
 *   'courseshortname'=> string,
 *   'coursefullname' => string,
 *   'cmid'           => int,
 *   'modname'        => 'assign'|'quiz',
 *   'instanceid'     => int,
 *   'name'           => string,
 *   'duedate'        => int (0 = none),
 *   'status'         => 'notsubmitted'|'inreview'|'graded',
 *   'grade'          => float|null (student grade, null for teachers / ungraded),
 *   'grademax'       => float,
 *   'gradedisplay'   => string|null (e.g. "85 / 100"),
 *   'pending'        => int (teacher mode: submissions waiting for grading),
 *   'isteacher'      => bool,
 *   'url'            => moodle_url (student view / teacher view),
 *   'actionurl'      => moodle_url|null (feedback / grading / report / review),
 *   'actionlabel'    => string (lang key suffix: 'viewlink'|'reviewlink'|'checklink'),
 * ]
 *
 * Performance notes (review fix):
 * - All module records are fetched with batched get_records_list() grouped by
 *   instanceid instead of per-activity record_exists()/get_record() (was N+1,
 *   ~150-200 queries per dashboard render).
 * - Student submissions/grades/attempts are fetched with batched
 *   get_records_select() + IN (...) grouped in PHP (2-4 queries total).
 * - Teacher aggregates (pending / graded counts) are fetched with batched
 *   GROUP BY queries (one query per metric, not per activity).
 * - Full sorted overview is cached in MUC (block_task_analytics/overview,
 *   TTL 5 min); BLOCK_LIMIT slices the cached list, so repeated block renders
 *   do not hit the DB at all. See db/caches.php.
 *
 * @package    block_task_analytics
 * @copyright  2026 Spada1557
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace block_task_analytics;

defined('MOODLE_INTERNAL') || die();

/**
 * Aggregates assign/quiz data for the block and the full view page.
 */
class service {

    /** @var string Task was never submitted (no record, new, draft, reopened). */
    public const STATUS_NOTSUBMITTED = 'notsubmitted';
    /** @var string Task is submitted / attempted and waits for grading. */
    public const STATUS_INREVIEW = 'inreview';
    /** @var string Task has a grade. */
    public const STATUS_GRADED = 'graded';
    /** @var int Max items rendered inside the block (full list in view.php). */
    public const BLOCK_LIMIT = 7;
    /** @var int MUC TTL for the full overview (seconds). */
    public const CACHE_TTL = 300;

    /**
     * Build a sorted overview of all tasks visible to the current user.
     *
     * Sort order: "In review" first, then "Not submitted" ordered by duedate
     * (tasks without a due date go last), then "Graded". Ties fall back to
     * the task name.
     *
     * Batching: module records, submissions and aggregates are loaded with
     * a constant number of queries; see file docblock. The full sorted list
     * is cached in MUC, $limit only slices the result (cheap).
     *
     * @param int|null $limit max items to return; null or <= 0 means no limit.
     * @return array list of item arrays (see file docblock).
     */
    public static function get_overview(?int $limit = self::BLOCK_LIMIT): array {
        global $USER, $DB;

        if (empty($USER->id) || isguestuser()) {
            return [];
        }
        $userid = (int)$USER->id;

        // Try MUC cache first: full sorted list (urls stored as strings).
        $cached = self::cache_get($userid);
        if (is_array($cached)) {
            $items = self::restore_urls($cached);
            if (!empty($limit) && $limit > 0) {
                $items = array_slice($items, 0, $limit);
            }
            return $items;
        }

        // All courses where the user is enrolled (includes staff roles).
        $courses = enrol_get_my_courses('*', 'visible DESC, sortorder ASC', 0);
        if (empty($courses)) {
            return [];
        }

        // Pass 1: collect candidate course modules without any per-activity DB hit.
        $candidates = []; // Each: ['cm'=>cm_info, 'course'=>stdClass, 'isteacher'=>bool].
        $assigninstanceids = [];
        $quizinstanceids = [];
        foreach ($courses as $course) {
            // Skip the front page course; it never holds real assignments.
            if ((int)$course->id === (int)SITEID) {
                continue;
            }
            $coursecontext = \context_course::instance((int)$course->id);
            // Teacher mode is decided per course: anyone who can grade either
            // module type sees aggregates instead of personal submissions.
            $isteacher = has_capability('mod/assign:grade', $coursecontext)
                || has_capability('mod/quiz:grade', $coursecontext);

            try {
                $modinfo = get_fast_modinfo($course);
            } catch (\Exception $e) {
                continue;
            } catch (\Throwable $e) {
                continue;
            }

            $cms = $modinfo->get_cms();
            foreach ($cms as $cm) {
                if ($cm->modname !== 'assign' && $cm->modname !== 'quiz') {
                    continue;
                }
                // Skip deleted / orphaned course modules.
                if ($cm->deletioninprogress) {
                    continue;
                }
                // Students only see visible activities; teachers with
                // moodle/course:viewhiddenactivities also see hidden ones.
                if (!$cm->uservisible) {
                    if (!$isteacher || !has_capability('moodle/course:viewhiddenactivities', $coursecontext)) {
                        continue;
                    }
                }
                $candidates[] = ['cm' => $cm, 'course' => $course, 'isteacher' => $isteacher];
                if ($cm->modname === 'assign') {
                    $assigninstanceids[(int)$cm->instance] = (int)$cm->instance;
                } else {
                    $quizinstanceids[(int)$cm->instance] = (int)$cm->instance;
                }
            }
        }

        if (empty($candidates)) {
            return [];
        }

        // Pass 2: batch-load module records (replaces per-cm record_exists + get_record).
        $assignrecords = self::get_records_list_safe('assign', array_values($assigninstanceids));
        $quizrecords = self::get_records_list_safe('quiz', array_values($quizinstanceids));

        // Keep only candidates whose module record really exists (stale modinfo guard).
        $valid = [];
        foreach ($candidates as $cand) {
            /** @var \cm_info $cm */
            $cm = $cand['cm'];
            if ($cm->modname === 'assign') {
                if (!isset($assignrecords[(int)$cm->instance])) {
                    continue;
                }
            } else {
                if (!isset($quizrecords[(int)$cm->instance])) {
                    continue;
                }
            }
            $valid[] = $cand;
        }
        if (empty($valid)) {
            return [];
        }

        // Pass 3: batch-load student data and teacher aggregates.
        $preload = self::preload_user_data($valid, $assignrecords, $quizrecords, $userid);

        $items = [];
        foreach ($valid as $cand) {
            /** @var \cm_info $cm */
            $cm = $cand['cm'];
            $course = $cand['course'];
            $isteacher = $cand['isteacher'];
            if ($cm->modname === 'assign') {
                $item = self::build_assign_item($cm, $course, $isteacher, $userid, $assignrecords, $preload);
            } else {
                $item = self::build_quiz_item($cm, $course, $isteacher, $userid, $quizrecords, $preload);
            }
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        self::sort_items($items);

        // Cache the FULL sorted list (urls as strings for safe serialization),
        // then slice to $limit. Repeated block renders hit the cache and
        // perform zero DB queries.
        self::cache_set($userid, $items);

        if (!empty($limit) && $limit > 0) {
            $items = array_slice($items, 0, $limit);
        }

        return $items;
    }

    /**
     * Invalidate the cached overview for a user (call after grading/submission changes if needed).
     *
     * @param int $userid user id.
     */
    public static function invalidate_cache(int $userid): void {
        try {
            $cache = \cache::make('block_task_analytics', 'overview');
            $cache->delete((string)$userid);
        } catch (\Exception $e) {
            // Cache not configured yet (e.g. unit tests) — ignore.
        } catch (\Throwable $e) {
            // Ignore.
        }
    }

    /**
     * Safe wrapper around get_records_list (returns [] for empty id list).
     *
     * @param string $table table name.
     * @param int[] $ids record ids.
     * @return array id => record.
     */
    protected static function get_records_list_safe(string $table, array $ids): array {
        global $DB;
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (empty($ids)) {
            return [];
        }
        return $DB->get_records_list($table, 'id', $ids);
    }

    /**
     * Batch-load all per-user data needed to build items.
     *
     * Returns array with keys:
     * - submissions: [assignmentid => submission record (latest=1)]
     * - grades: [assignmentid => latest grade record by attemptnumber]
     * - quizattempts: [quizid => list of attempts]
     * - quizgrades: [quizid => grade record]
     * - assignpending: [assignmentid => pending count]
     * - assigngraded: [assignmentid => distinct graded users count]
     * - quizpendingactive: [quizid => count]
     * - quizpendingungraded: [quizid => count]
     * - quizgraded: [quizid => count]
     *
     * @param array $valid valid candidates.
     * @param array $assignrecords batched assign records.
     * @param array $quizrecords batched quiz records.
     * @param int $userid current user id.
     * @return array preload data.
     */
    protected static function preload_user_data(array $valid, array $assignrecords, array $quizrecords, int $userid): array {
        global $DB;
        $result = [
            'submissions' => [],
            'grades' => [],
            'quizattempts' => [],
            'quizgrades' => [],
            'assignpending' => [],
            'assigngraded' => [],
            'quizpendingactive' => [],
            'quizpendingungraded' => [],
            'quizgraded' => [],
        ];

        $studentassignids = [];
        $teacherassignids = [];
        $studentquizids = [];
        $teacherquizids = [];
        foreach ($valid as $cand) {
            /** @var \cm_info $cm */
            $cm = $cand['cm'];
            $isteacher = $cand['isteacher'];
            if ($cm->modname === 'assign') {
                if (!isset($assignrecords[(int)$cm->instance])) {
                    continue;
                }
                $aid = (int)$assignrecords[(int)$cm->instance]->id;
                if ($isteacher) {
                    $teacherassignids[$aid] = $aid;
                } else {
                    $studentassignids[$aid] = $aid;
                }
            } else {
                if (!isset($quizrecords[(int)$cm->instance])) {
                    continue;
                }
                $qid = (int)$quizrecords[(int)$cm->instance]->id;
                if ($isteacher) {
                    $teacherquizids[$qid] = $qid;
                } else {
                    $studentquizids[$qid] = $qid;
                }
            }
        }

        // Student assign submissions (latest=1 only).
        if (!empty($studentassignids)) {
            [$insql, $params] = $DB->get_in_or_equal(array_values($studentassignids), SQL_PARAMS_NAMED);
            $params['userid'] = $userid;
            $rows = $DB->get_records_select(
                'assign_submission',
                "assignment $insql AND userid = :userid AND latest = 1",
                $params
            );
            foreach ($rows as $row) {
                $result['submissions'][(int)$row->assignment] = $row;
            }
            // Student grades: may be MULTIPLE rows per user (regrading iterations,
            // group submissions). Fetch all and keep the latest attemptnumber.
            // Fixes dml_multiple_records_exception from get_record().
            $rows = $DB->get_records_select(
                'assign_grades',
                "assignment $insql AND userid = :userid",
                $params
            );
            $grouped = [];
            foreach ($rows as $row) {
                $grouped[(int)$row->assignment][] = $row;
            }
            foreach ($grouped as $aid => $list) {
                $result['grades'][$aid] = self::pick_latest_grade($list);
            }
        }

        // Student quiz attempts + grades.
        if (!empty($studentquizids)) {
            [$insql, $params] = $DB->get_in_or_equal(array_values($studentquizids), SQL_PARAMS_NAMED);
            $params['userid'] = $userid;
            $params['preview'] = 0;
            $rows = $DB->get_records_select(
                'quiz_attempts',
                "quiz $insql AND userid = :userid AND preview = :preview",
                $params,
                'attempt DESC'
            );
            foreach ($rows as $row) {
                $result['quizattempts'][(int)$row->quiz][] = $row;
            }
            $rows = $DB->get_records_select(
                'quiz_grades',
                "quiz $insql AND userid = :userid",
                $params
            );
            foreach ($rows as $row) {
                $result['quizgrades'][(int)$row->quiz] = $row;
            }
        }

        // Teacher assign aggregates: single GROUP BY query per metric.
        if (!empty($teacherassignids)) {
            [$insql, $params] = $DB->get_in_or_equal(array_values($teacherassignids), SQL_PARAMS_NAMED);
            $params['submitted'] = 'submitted';
            // Pending: submitted latest submissions without a newer valid grade.
            // Uses COUNT(DISTINCT s.userid) to stay correct with multiple grade rows.
            $sql = "SELECT s.assignment AS assignid, COUNT(DISTINCT s.userid) AS cnt
                      FROM {assign_submission} s
                      LEFT JOIN {assign_grades} g
                        ON g.assignment = s.assignment AND g.userid = s.userid
                           AND g.grade IS NOT NULL AND g.grade >= 0 AND g.timemodified >= s.timemodified
                     WHERE s.assignment $insql AND s.latest = 1 AND s.status = :submitted
                       AND g.id IS NULL
                     GROUP BY s.assignment";
            $rows = $DB->get_records_sql($sql, $params);
            foreach ($rows as $row) {
                $result['assignpending'][(int)$row->assignid] = (int)$row->cnt;
            }
            // Graded: distinct users with a valid grade.
            $sql2 = "SELECT assignment AS assignid, COUNT(DISTINCT userid) AS cnt
                       FROM {assign_grades}
                      WHERE assignment $insql AND grade IS NOT NULL AND grade >= 0
                      GROUP BY assignment";
            // Rebuild params (get_in_or_equal placeholders reused).
            [$insql2, $params2] = $DB->get_in_or_equal(array_values($teacherassignids), SQL_PARAMS_NAMED);
            $rows = $DB->get_records_sql(
                "SELECT assignment AS assignid, COUNT(DISTINCT userid) AS cnt
                   FROM {assign_grades}
                  WHERE assignment $insql2 AND grade IS NOT NULL AND grade >= 0
                  GROUP BY assignment",
                $params2
            );
            foreach ($rows as $row) {
                $result['assigngraded'][(int)$row->assignid] = (int)$row->cnt;
            }
        }

        // Teacher quiz aggregates.
        if (!empty($teacherquizids)) {
            [$insql, $params] = $DB->get_in_or_equal(array_values($teacherquizids), SQL_PARAMS_NAMED);
            $params['inprogress'] = 'inprogress';
            $params['overdue'] = 'overdue';
            $rows = $DB->get_records_sql(
                "SELECT quiz AS quizid, COUNT(*) AS cnt
                   FROM {quiz_attempts}
                  WHERE quiz $insql AND preview = 0 AND state IN (:inprogress, :overdue)
                  GROUP BY quiz",
                $params
            );
            foreach ($rows as $row) {
                $result['quizpendingactive'][(int)$row->quizid] = (int)$row->cnt;
            }
            [$insql2, $params2] = $DB->get_in_or_equal(array_values($teacherquizids), SQL_PARAMS_NAMED);
            $params2['finished'] = 'finished';
            $rows = $DB->get_records_sql(
                "SELECT a.quiz AS quizid, COUNT(a.id) AS cnt
                   FROM {quiz_attempts} a
                   LEFT JOIN {quiz_grades} g ON g.quiz = a.quiz AND g.userid = a.userid
                  WHERE a.quiz $insql2 AND a.preview = 0 AND a.state = :finished
                    AND (g.id IS NULL OR g.grade IS NULL)
                  GROUP BY a.quiz",
                $params2
            );
            foreach ($rows as $row) {
                $result['quizpendingungraded'][(int)$row->quizid] = (int)$row->cnt;
            }
            [$insql3, $params3] = $DB->get_in_or_equal(array_values($teacherquizids), SQL_PARAMS_NAMED);
            $rows = $DB->get_records_sql(
                "SELECT quiz AS quizid, COUNT(*) AS cnt
                   FROM {quiz_grades}
                  WHERE quiz $insql3 AND grade IS NOT NULL
                  GROUP BY quiz",
                $params3
            );
            foreach ($rows as $row) {
                $result['quizgraded'][(int)$row->quizid] = (int)$row->cnt;
            }
        }

        return $result;
    }

    /**
     * Pick the latest grade row by attemptnumber (fallback: timemodified, id).
     *
     * @param array $rows grade records for one assignment+user.
     * @return \stdClass latest grade record.
     */
    public static function pick_latest_grade(array $rows): \stdClass {
        $best = null;
        foreach ($rows as $row) {
            if ($best === null) {
                $best = $row;
                continue;
            }
            $aattempt = isset($row->attemptnumber) ? (int)$row->attemptnumber : -1;
            $battempt = isset($best->attemptnumber) ? (int)$best->attemptnumber : -1;
            if ($aattempt !== $battempt) {
                if ($aattempt > $battempt) {
                    $best = $row;
                }
                continue;
            }
            $atime = isset($row->timemodified) ? (int)$row->timemodified : 0;
            $btime = isset($best->timemodified) ? (int)$best->timemodified : 0;
            if ($atime !== $btime) {
                if ($atime > $btime) {
                    $best = $row;
                }
                continue;
            }
            if ((int)$row->id > (int)$best->id) {
                $best = $row;
            }
        }
        return $best;
    }

    /**
     * Build a single overview item for an assign activity.
     *
     * Student mapping:
     * - grade record with grade >= 0        => graded (+ formatted grade).
     * - latest submission status=submitted  => in review.
     * - otherwise (none/new/draft/reopened) => not submitted.
     *
     * Teacher mapping (aggregates over the whole course group):
     * - pending submissions (> 0)           => in review.
     * - else any graded submission          => graded.
     * - else                                => not submitted.
     *
     * @param \cm_info $cm course module info.
     * @param \stdClass $course course record (needs id, shortname, fullname).
     * @param bool $isteacher whether to compute teacher aggregates.
     * @param int $userid current user id (student submissions lookup).
     * @return array|null item array or null when the user may not see it.
     */
    public static function get_assign_data(\cm_info $cm, \stdClass $course, bool $isteacher, int $userid): ?array {
        global $DB;

        $modcontext = \context_module::instance((int)$cm->id);

        $assign = $DB->get_record('assign', ['id' => $cm->instance]);
        if (!$assign) {
            return null;
        }

        $duedate = isset($assign->duedate) ? (int)$assign->duedate : 0;
        $grademax = isset($assign->grade) ? (float)$assign->grade : 0.0;
        $url = new \moodle_url('/mod/assign/view.php', ['id' => (int)$cm->id]);

        $base = [
            'courseid' => (int)$course->id,
            'courseshortname' => $course->shortname ?? '',
            'coursefullname' => $course->fullname ?? '',
            'cmid' => (int)$cm->id,
            'modname' => 'assign',
            'instanceid' => (int)$assign->id,
            'name' => $cm->name,
            'duedate' => $duedate,
            'grademax' => $grademax,
            'isteacher' => $isteacher,
            'url' => $url,
        ];

        if (!$isteacher) {
            if (!has_capability('mod/assign:view', $modcontext)) {
                return null;
            }
            $submission = $DB->get_record('assign_submission', [
                'assignment' => $assign->id,
                'userid' => $userid,
                'latest' => 1,
            ]);
            // FIX (review): get_record() throws dml_multiple_records_exception
            // when several grade rows exist for one user (regrading iterations,
            // group submissions). Use get_records() + latest attemptnumber.
            $grade = self::get_latest_assign_grade((int)$assign->id, $userid);
            // assign_grades.grade = -1 (or NULL) means "no grade yet".
            $hasgrade = $grade && $grade->grade !== null && (float)$grade->grade >= 0;

            if ($hasgrade) {
                return $base + [
                    'status' => self::STATUS_GRADED,
                    'grade' => (float)$grade->grade,
                    'gradedisplay' => self::format_grade((float)$grade->grade, $grademax),
                    'pending' => 0,
                    // Feedback (grade + review comments) lives on the same page.
                    'actionurl' => $url,
                    'actionlabel' => 'reviewlink',
                ];
            }
            if ($submission && $submission->status === 'submitted') {
                return $base + [
                    'status' => self::STATUS_INREVIEW,
                    'grade' => null,
                    'gradedisplay' => null,
                    'pending' => 0,
                    'actionurl' => $url,
                    'actionlabel' => 'viewlink',
                ];
            }
            return $base + [
                'status' => self::STATUS_NOTSUBMITTED,
                'grade' => null,
                'gradedisplay' => null,
                'pending' => 0,
                'actionurl' => $url,
                'actionlabel' => 'viewlink',
            ];
        }

        // Teacher mode: count submissions waiting for grading. A submission
        // needs attention when it is submitted and has no grade yet, or the
        // grade is older than the submission (student resubmitted).
        $pending = (int)$DB->get_field_sql(
            "SELECT COUNT(DISTINCT s.userid)
               FROM {assign_submission} s
               LEFT JOIN {assign_grades} g
                 ON g.assignment = s.assignment AND g.userid = s.userid
                    AND g.grade IS NOT NULL AND g.grade >= 0 AND g.timemodified >= s.timemodified
              WHERE s.assignment = :assignid
                AND s.latest = 1
                AND s.status = :submitted
                AND g.id IS NULL",
            ['assignid' => $assign->id, 'submitted' => 'submitted']
        );
        $gradedcount = (int)$DB->count_records_select(
            'assign_grades',
            'assignment = :assignid AND grade IS NOT NULL AND grade >= 0',
            ['assignid' => $assign->id]
        );

        $gradingurl = new \moodle_url('/mod/assign/view.php', ['id' => (int)$cm->id, 'action' => 'grading']);
        if ($pending > 0) {
            $status = self::STATUS_INREVIEW;
        } else if ($gradedcount > 0) {
            $status = self::STATUS_GRADED;
        } else {
            $status = self::STATUS_NOTSUBMITTED;
        }

        return $base + [
            'status' => $status,
            'grade' => null,
            'gradedisplay' => null,
            'pending' => $pending,
            'actionurl' => $gradingurl,
            'actionlabel' => 'checklink',
        ];
    }

    /**
     * Safely fetch the latest assign grade for a user (no exception on multiples).
     *
     * @param int $assignmentid assign instance id.
     * @param int $userid user id.
     * @return \stdClass|false latest grade record or false.
     */
    public static function get_latest_assign_grade(int $assignmentid, int $userid) {
        global $DB;
        $grades = $DB->get_records('assign_grades', [
            'assignment' => $assignmentid,
            'userid' => $userid,
        ]);
        if (empty($grades)) {
            return false;
        }
        if (count($grades) === 1) {
            return reset($grades);
        }
        return self::pick_latest_grade(array_values($grades));
    }

    /**
     * Build a single overview item for a quiz activity.
     *
     * Student mapping:
     * - quiz_grades record with non-NULL grade => graded.
     * - active attempt (inprogress/overdue, preview=0) or a finished attempt
     *   without a grade yet (e.g. essay waits for manual grading) => in review.
     * - otherwise => not submitted.
     *
     * @param \cm_info $cm course module info.
     * @param \stdClass $course course record.
     * @param bool $isteacher whether to compute teacher aggregates.
     * @param int $userid current user id.
     * @return array|null item array or null when the user may not see it.
     */
    public static function get_quiz_data(\cm_info $cm, \stdClass $course, bool $isteacher, int $userid): ?array {
        global $DB;

        $modcontext = \context_module::instance((int)$cm->id);

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance]);
        if (!$quiz) {
            return null;
        }

        $duedate = isset($quiz->timeclose) ? (int)$quiz->timeclose : 0;
        $grademax = isset($quiz->grade) ? (float)$quiz->grade : 0.0;
        $url = new \moodle_url('/mod/quiz/view.php', ['id' => (int)$cm->id]);

        $base = [
            'courseid' => (int)$course->id,
            'courseshortname' => $course->shortname ?? '',
            'coursefullname' => $course->fullname ?? '',
            'cmid' => (int)$cm->id,
            'modname' => 'quiz',
            'instanceid' => (int)$quiz->id,
            'name' => $cm->name,
            'duedate' => $duedate,
            'grademax' => $grademax,
            'isteacher' => $isteacher,
            'url' => $url,
        ];

        if (!$isteacher) {
            if (!has_capability('mod/quiz:view', $modcontext)) {
                return null;
            }
            // Preview attempts (teacher previews) must not affect the status.
            $attempts = $DB->get_records(
                'quiz_attempts',
                ['quiz' => $quiz->id, 'userid' => $userid, 'preview' => 0],
                'attempt DESC'
            );
            $quizgrade = $DB->get_record('quiz_grades', ['quiz' => $quiz->id, 'userid' => $userid]);
            $hasgrade = $quizgrade && $quizgrade->grade !== null;

            if ($hasgrade) {
                $latestreview = null;
                foreach ($attempts as $attempt) {
                    if ($attempt->state === 'finished') {
                        $latestreview = new \moodle_url('/mod/quiz/review.php', ['attempt' => (int)$attempt->id]);
                        break;
                    }
                }
                return $base + [
                    'status' => self::STATUS_GRADED,
                    'grade' => (float)$quizgrade->grade,
                    'gradedisplay' => self::format_grade((float)$quizgrade->grade, $grademax),
                    'pending' => 0,
                    'actionurl' => $latestreview ?? $url,
                    'actionlabel' => 'reviewlink',
                ];
            }

            $hasactive = false;
            $hasfinished = false;
            foreach ($attempts as $attempt) {
                if ($attempt->state === 'inprogress' || $attempt->state === 'overdue') {
                    $hasactive = true;
                    break;
                }
                if ($attempt->state === 'finished') {
                    $hasfinished = true;
                }
            }
            if ($hasactive || $hasfinished) {
                // Finished without a grade: e.g. essay questions wait for
                // manual grading; in-progress: attempt is open.
                return $base + [
                    'status' => self::STATUS_INREVIEW,
                    'grade' => null,
                    'gradedisplay' => null,
                    'pending' => 0,
                    'actionurl' => $url,
                    'actionlabel' => 'viewlink',
                ];
            }
            return $base + [
                'status' => self::STATUS_NOTSUBMITTED,
                'grade' => null,
                'gradedisplay' => null,
                'pending' => 0,
                'actionurl' => $url,
                'actionlabel' => 'viewlink',
            ];
        }

        // Teacher mode: active attempts + finished attempts without a grade.
        $pendingactive = (int)$DB->count_records_select(
            'quiz_attempts',
            'quiz = :quizid AND preview = 0 AND state IN (:inprogress, :overdue)',
            ['quizid' => $quiz->id, 'inprogress' => 'inprogress', 'overdue' => 'overdue']
        );
        $pendingungraded = (int)$DB->get_field_sql(
            "SELECT COUNT(a.id)
               FROM {quiz_attempts} a
               LEFT JOIN {quiz_grades} g
                 ON g.quiz = a.quiz AND g.userid = a.userid
              WHERE a.quiz = :quizid
                AND a.preview = 0
                AND a.state = :finished
                AND (g.id IS NULL OR g.grade IS NULL)",
            ['quizid' => $quiz->id, 'finished' => 'finished']
        );
        $pending = $pendingactive + $pendingungraded;
        $gradedcount = (int)$DB->count_records_select(
            'quiz_grades',
            'quiz = :quizid AND grade IS NOT NULL',
            ['quizid' => $quiz->id]
        );

        $reporturl = new \moodle_url('/mod/quiz/report.php', ['id' => (int)$cm->id, 'mode' => 'grading']);
        if ($pending > 0) {
            $status = self::STATUS_INREVIEW;
        } else if ($gradedcount > 0) {
            $status = self::STATUS_GRADED;
        } else {
            $status = self::STATUS_NOTSUBMITTED;
        }

        return $base + [
            'status' => $status,
            'grade' => null,
            'gradedisplay' => null,
            'pending' => $pending,
            'actionurl' => $reporturl,
            'actionlabel' => 'checklink',
        ];
    }

    /**
     * Batched assign item builder (uses preloaded records, no extra queries).
     *
     * @param \cm_info $cm course module.
     * @param \stdClass $course course record.
     * @param bool $isteacher teacher mode.
     * @param int $userid user id.
     * @param array $assignrecords batched assign table rows.
     * @param array $preload batched user data from preload_user_data().
     * @return array|null
     */
    protected static function build_assign_item(
        \cm_info $cm,
        \stdClass $course,
        bool $isteacher,
        int $userid,
        array $assignrecords,
        array $preload
    ): ?array {
        $assign = $assignrecords[(int)$cm->instance] ?? null;
        if (!$assign) {
            return null;
        }
        if (!$isteacher) {
            $modcontext = \context_module::instance((int)$cm->id);
            if (!has_capability('mod/assign:view', $modcontext)) {
                return null;
            }
        }
        $duedate = isset($assign->duedate) ? (int)$assign->duedate : 0;
        $grademax = isset($assign->grade) ? (float)$assign->grade : 0.0;
        $url = new \moodle_url('/mod/assign/view.php', ['id' => (int)$cm->id]);
        $base = [
            'courseid' => (int)$course->id,
            'courseshortname' => $course->shortname ?? '',
            'coursefullname' => $course->fullname ?? '',
            'cmid' => (int)$cm->id,
            'modname' => 'assign',
            'instanceid' => (int)$assign->id,
            'name' => $cm->name,
            'duedate' => $duedate,
            'grademax' => $grademax,
            'isteacher' => $isteacher,
            'url' => $url,
        ];
        $aid = (int)$assign->id;
        if (!$isteacher) {
            $submission = $preload['submissions'][$aid] ?? null;
            $grade = $preload['grades'][$aid] ?? null;
            $hasgrade = $grade && $grade->grade !== null && (float)$grade->grade >= 0;
            if ($hasgrade) {
                return $base + [
                    'status' => self::STATUS_GRADED,
                    'grade' => (float)$grade->grade,
                    'gradedisplay' => self::format_grade((float)$grade->grade, $grademax),
                    'pending' => 0,
                    'actionurl' => $url,
                    'actionlabel' => 'reviewlink',
                ];
            }
            if ($submission && $submission->status === 'submitted') {
                return $base + [
                    'status' => self::STATUS_INREVIEW,
                    'grade' => null,
                    'gradedisplay' => null,
                    'pending' => 0,
                    'actionurl' => $url,
                    'actionlabel' => 'viewlink',
                ];
            }
            return $base + [
                'status' => self::STATUS_NOTSUBMITTED,
                'grade' => null,
                'gradedisplay' => null,
                'pending' => 0,
                'actionurl' => $url,
                'actionlabel' => 'viewlink',
            ];
        }
        $pending = (int)($preload['assignpending'][$aid] ?? 0);
        $gradedcount = (int)($preload['assigngraded'][$aid] ?? 0);
        $gradingurl = new \moodle_url('/mod/assign/view.php', ['id' => (int)$cm->id, 'action' => 'grading']);
        if ($pending > 0) {
            $status = self::STATUS_INREVIEW;
        } else if ($gradedcount > 0) {
            $status = self::STATUS_GRADED;
        } else {
            $status = self::STATUS_NOTSUBMITTED;
        }
        return $base + [
            'status' => $status,
            'grade' => null,
            'gradedisplay' => null,
            'pending' => $pending,
            'actionurl' => $gradingurl,
            'actionlabel' => 'checklink',
        ];
    }

    /**
     * Batched quiz item builder (uses preloaded records, no extra queries).
     *
     * @param \cm_info $cm course module.
     * @param \stdClass $course course record.
     * @param bool $isteacher teacher mode.
     * @param int $userid user id.
     * @param array $quizrecords batched quiz table rows.
     * @param array $preload batched user data.
     * @return array|null
     */
    protected static function build_quiz_item(
        \cm_info $cm,
        \stdClass $course,
        bool $isteacher,
        int $userid,
        array $quizrecords,
        array $preload
    ): ?array {
        $quiz = $quizrecords[(int)$cm->instance] ?? null;
        if (!$quiz) {
            return null;
        }
        if (!$isteacher) {
            $modcontext = \context_module::instance((int)$cm->id);
            if (!has_capability('mod/quiz:view', $modcontext)) {
                return null;
            }
        }
        $duedate = isset($quiz->timeclose) ? (int)$quiz->timeclose : 0;
        $grademax = isset($quiz->grade) ? (float)$quiz->grade : 0.0;
        $url = new \moodle_url('/mod/quiz/view.php', ['id' => (int)$cm->id]);
        $base = [
            'courseid' => (int)$course->id,
            'courseshortname' => $course->shortname ?? '',
            'coursefullname' => $course->fullname ?? '',
            'cmid' => (int)$cm->id,
            'modname' => 'quiz',
            'instanceid' => (int)$quiz->id,
            'name' => $cm->name,
            'duedate' => $duedate,
            'grademax' => $grademax,
            'isteacher' => $isteacher,
            'url' => $url,
        ];
        $qid = (int)$quiz->id;
        if (!$isteacher) {
            $attempts = $preload['quizattempts'][$qid] ?? [];
            $quizgrade = $preload['quizgrades'][$qid] ?? null;
            $hasgrade = $quizgrade && $quizgrade->grade !== null;
            if ($hasgrade) {
                $latestreview = null;
                foreach ($attempts as $attempt) {
                    if ($attempt->state === 'finished') {
                        $latestreview = new \moodle_url('/mod/quiz/review.php', ['attempt' => (int)$attempt->id]);
                        break;
                    }
                }
                return $base + [
                    'status' => self::STATUS_GRADED,
                    'grade' => (float)$quizgrade->grade,
                    'gradedisplay' => self::format_grade((float)$quizgrade->grade, $grademax),
                    'pending' => 0,
                    'actionurl' => $latestreview ?? $url,
                    'actionlabel' => 'reviewlink',
                ];
            }
            $hasactive = false;
            $hasfinished = false;
            foreach ($attempts as $attempt) {
                if ($attempt->state === 'inprogress' || $attempt->state === 'overdue') {
                    $hasactive = true;
                    break;
                }
                if ($attempt->state === 'finished') {
                    $hasfinished = true;
                }
            }
            if ($hasactive || $hasfinished) {
                return $base + [
                    'status' => self::STATUS_INREVIEW,
                    'grade' => null,
                    'gradedisplay' => null,
                    'pending' => 0,
                    'actionurl' => $url,
                    'actionlabel' => 'viewlink',
                ];
            }
            return $base + [
                'status' => self::STATUS_NOTSUBMITTED,
                'grade' => null,
                'gradedisplay' => null,
                'pending' => 0,
                'actionurl' => $url,
                'actionlabel' => 'viewlink',
            ];
        }
        $pending = (int)($preload['quizpendingactive'][$qid] ?? 0)
            + (int)($preload['quizpendingungraded'][$qid] ?? 0);
        $gradedcount = (int)($preload['quizgraded'][$qid] ?? 0);
        $reporturl = new \moodle_url('/mod/quiz/report.php', ['id' => (int)$cm->id, 'mode' => 'grading']);
        if ($pending > 0) {
            $status = self::STATUS_INREVIEW;
        } else if ($gradedcount > 0) {
            $status = self::STATUS_GRADED;
        } else {
            $status = self::STATUS_NOTSUBMITTED;
        }
        return $base + [
            'status' => $status,
            'grade' => null,
            'gradedisplay' => null,
            'pending' => $pending,
            'actionurl' => $reporturl,
            'actionlabel' => 'checklink',
        ];
    }

    /**
     * Format "grade / max" for display, honouring a zero/negative max.
     *
     * Uses format_float() when available (full Moodle stack), otherwise a
     * plain 2-decimal fallback so the method stays testable in isolation.
     *
     * @param float $grade awarded grade.
     * @param float $max maximum grade of the activity.
     * @return string formatted grade.
     */
    public static function format_grade(float $grade, float $max): string {
        if (function_exists('format_float')) {
            $g = format_float($grade, 2, true);
            if ($max > 0) {
                return $g . ' / ' . format_float($max, 2, true);
            }
            return $g;
        }
        if ($max > 0) {
            return number_format($grade, 2) . ' / ' . number_format($max, 2);
        }
        return number_format($grade, 2);
    }

    /**
     * In-place sort of overview items (in review -> unsubmitted by duedate -> graded).
     *
     * @param array $items item list, sorted in place.
     */
    public static function sort_items(array &$items): void {
        $weight = [
            self::STATUS_INREVIEW => 0,
            self::STATUS_NOTSUBMITTED => 1,
            self::STATUS_GRADED => 2,
        ];
        usort($items, function(array $a, array $b) use ($weight): int {
            $wa = $weight[$a['status']] ?? 99;
            $wb = $weight[$b['status']] ?? 99;
            if ($wa !== $wb) {
                return $wa <=> $wb;
            }
            // Inside "not submitted" order by due date; 0 (no date) goes last.
            $da = !empty($a['duedate']) ? (int)$a['duedate'] : PHP_INT_MAX;
            $db = !empty($b['duedate']) ? (int)$b['duedate'] : PHP_INT_MAX;
            if ($da !== $db) {
                return $da <=> $db;
            }
            return strcasecmp((string)$a['name'], (string)$b['name']);
        });
    }

    /**
     * Read cached overview (urls stored as strings).
     *
     * @param int $userid user id.
     * @return array|null cached items with string urls, or null on miss.
     */
    protected static function cache_get(int $userid): ?array {
        try {
            $cache = \cache::make('block_task_analytics', 'overview');
            $data = $cache->get((string)$userid);
            if (is_array($data)) {
                return $data;
            }
        } catch (\Exception $e) {
            // No cache store in unit tests / install — treat as miss.
        } catch (\Throwable $e) {
            // Same.
        }
        return null;
    }

    /**
     * Store full sorted overview in MUC (urls converted to strings).
     *
     * @param int $userid user id.
     * @param array $items sorted items with moodle_url objects.
     */
    protected static function cache_set(int $userid, array $items): void {
        try {
            $cache = \cache::make('block_task_analytics', 'overview');
            $cache->set((string)$userid, self::store_urls($items));
        } catch (\Exception $e) {
            // Ignore cache failures — overview still works, just slower.
        } catch (\Throwable $e) {
            // Ignore.
        }
    }

    /**
     * Convert moodle_url objects to strings for cache storage.
     *
     * @param array $items items with moodle_url.
     * @return array items with string urls.
     */
    protected static function store_urls(array $items): array {
        foreach ($items as &$item) {
            if ($item['url'] instanceof \moodle_url) {
                $item['url'] = $item['url']->out(false);
            }
            if (!empty($item['actionurl']) && $item['actionurl'] instanceof \moodle_url) {
                $item['actionurl'] = $item['actionurl']->out(false);
            }
        }
        unset($item);
        return $items;
    }

    /**
     * Rebuild moodle_url objects after cache read.
     *
     * @param array $items items with string urls.
     * @return array items with moodle_url objects.
     */
    protected static function restore_urls(array $items): array {
        foreach ($items as &$item) {
            if (is_string($item['url'])) {
                $item['url'] = new \moodle_url($item['url']);
            }
            if (!empty($item['actionurl']) && is_string($item['actionurl'])) {
                $item['actionurl'] = new \moodle_url($item['actionurl']);
            }
        }
        unset($item);
        return $items;
    }
}
