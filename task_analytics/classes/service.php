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
 * @package    block_task_analytics
 * @copyright  2026 Your Name
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

    /**
     * Build a sorted overview of all tasks visible to the current user.
     *
     * Sort order: "In review" first, then "Not submitted" ordered by duedate
     * (tasks without a due date go last), then "Graded". Ties fall back to
     * the task name.
     *
     * @param int|null $limit max items to return; null or <= 0 means no limit.
     * @return array list of item arrays (see file docblock).
     */
    public static function get_overview(?int $limit = self::BLOCK_LIMIT): array {
        global $USER, $DB;

        $items = [];

        if (empty($USER->id) || isguestuser()) {
            return $items;
        }
        $userid = (int)$USER->id;

        // All courses where the user is enrolled (includes staff roles).
        $courses = enrol_get_my_courses('*', 'visible DESC, sortorder ASC', 0);
        if (empty($courses)) {
            return $items;
        }

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
                // The module must exist in the DB (defensive: modinfo can be stale).
                if (!$DB->record_exists($cm->modname, ['id' => $cm->instance])) {
                    continue;
                }

                if ($cm->modname === 'assign') {
                    $item = self::get_assign_data($cm, $course, $isteacher, $userid);
                } else {
                    $item = self::get_quiz_data($cm, $course, $isteacher, $userid);
                }
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
        }

        self::sort_items($items);

        if (!empty($limit) && $limit > 0) {
            $items = array_slice($items, 0, $limit);
        }

        return $items;
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
            $grade = $DB->get_record('assign_grades', [
                'assignment' => $assign->id,
                'userid' => $userid,
            ]);
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
            "SELECT COUNT(s.id)
               FROM {assign_submission} s
               LEFT JOIN {assign_grades} g
                 ON g.assignment = s.assignment AND g.userid = s.userid
              WHERE s.assignment = :assignid
                AND s.latest = 1
                AND s.status = :submitted
                AND (g.id IS NULL OR g.grade IS NULL OR g.grade < 0 OR g.timemodified < s.timemodified)",
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
}
