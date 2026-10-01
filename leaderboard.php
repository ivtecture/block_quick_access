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
 * Library functions for the "Quick access" block (quick links + leaderboard).
 *
 * @package    block_quick_access
 * @copyright  2026 Human mind
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->dirroot . '/grade/querylib.php');
require_once($CFG->dirroot . '/user/lib.php');

/**
 * Resolve the configured number of top users to display (clamped to 1-50).
 *
 * @return int
 */
function block_quick_access_get_limit() {
    $limit = (int)get_config('block_quick_access', 'limit');
    if ($limit <= 0) {
        $limit = 5;
    }
    return min($limit, 50);
}

/**
 * Return the ids of all roles with the "student" archetype.
 *
 * @return int[] Map roleid => true.
 */
function block_quick_access_get_student_roleids() {
    static $roleids = null;
    if ($roleids === null) {
        $roleids = [];
        foreach (get_archetype_roles('student') as $role) {
            $roleids[(int)$role->id] = true;
        }
    }
    return $roleids;
}

/**
 * Enrolled users of the course holding a student role (teachers/managers excluded).
 *
 * The student-role check is resolved with a single bulk query covering all role
 * assignments at the course context and its ancestors (so students enrolled at
 * a category level are found too), and the result is cached per context id so
 * consecutive calls within one request reuse it.
 *
 * @param \context $context Course context.
 * @return \stdClass[] Map userid => user record.
 */
function block_quick_access_get_enrolled_students(\context $context) {
    global $DB;

    static $cache = [];

    $ctxid = $context->id;
    if (isset($cache[$ctxid])) {
        return $cache[$ctxid];
    }

    $enrolled = get_enrolled_users($context);
    if (!$enrolled) {
        $cache[$ctxid] = [];
        return $cache[$ctxid];
    }

    $contextids = [$ctxid];
    $parent = $context->get_parent_context();
    while ($parent) {
        $contextids[] = (int)$parent->id;
        $parent = $parent->get_parent_context();
    }

    list($inctx, $params) = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');
    $params['archetype'] = 'student';
    $sql = "SELECT DISTINCT ra.userid
              FROM {role_assignments} ra
              JOIN {role} r ON r.id = ra.roleid
             WHERE ra.contextid $inctx AND r.archetype = :archetype";

    $studentids = [];
    foreach ($DB->get_records_sql($sql, $params) as $row) {
        $studentids[(int)$row->userid] = true;
    }

    $students = [];
    foreach ($enrolled as $userid => $user) {
        if (isset($studentids[(int)$userid])) {
            $students[$userid] = $user;
        }
    }

    $cache[$ctxid] = $students;
    return $students;
}

/**
 * Display a rank: medal emoji for the first three places, plain value otherwise.
 *
 * @param int|string $rank
 * @return string
 */
function block_quick_access_rank_display($rank) {
    $medals = [1 => '🥇', 2 => '🥈', 3 => '🥉'];
    if (isset($medals[$rank])) {
        return $medals[$rank];
    }
    return $rank;
}

/**
 * CSS class applying a gold/silver/bronze background to the top three rows.
 *
 * @param int|string $rank
 * @return string
 */
function block_quick_access_rank_class($rank) {
    $classes = [1 => 'bqa-row-gold', 2 => 'bqa-row-silver', 3 => 'bqa-row-bronze'];
    return $classes[$rank] ?? '';
}

/**
 * Sort a userid => score map, assign ranks (equal scores share a rank) and
 * hydrate each entry with its user record.
 *
 * @param float[] $scores Map userid => score.
 * @param int $limit Maximum number of entries.
 * @param string $valuekey Key used to store the score in each entry.
 * @return array[] Entries with keys: rank, userid, $valuekey, user.
 */
function block_quick_access_rank_entries(array $scores, $limit, $valuekey) {
    arsort($scores);

    $result = [];
    $rank = 0;
    $lastscore = null;
    $iterator = 0;
    foreach ($scores as $userid => $score) {
        if (count($result) >= $limit) {
            break;
        }
        $iterator++;
        if ($score !== $lastscore) {
            $rank = $iterator;
            $lastscore = $score;
        }
        $result[] = [
            'rank' => $rank,
            'userid' => $userid,
            $valuekey => $score,
        ];
    }

    $userids = array_column($result, 'userid');
    $users = $userids ? user_get_users_by_id($userids) : [];

    foreach ($result as &$entry) {
        $entry['user'] = $users[$entry['userid']] ?? null;
    }
    unset($entry);

    return array_values(array_filter($result, function ($entry) {
        return $entry['user'] !== null;
    }));
}

/**
 * Render the leaderboard panel (mode switch + both leaderboard tables + back button).
 *
 * @param int $courseid Course ID.
 * @return string Empty string when the user may not view it.
 */
function block_quick_access_render_leaderboard_panel($courseid) {
    $context = context_course::instance($courseid);
    if (!has_capability('block/quick_access:view', $context)) {
        return '';
    }

    // Only users with the "viewscores" capability see other students' names and
    // numbers; everyone else sees ranks/medals and their own row only.
    $canscores = has_capability('block/quick_access:viewscores', $context);
    $limit = block_quick_access_get_limit();

    $modes = [
        'grade'      => get_string('bygrade', 'block_quick_access'),
        'completion' => get_string('bycompletion', 'block_quick_access'),
    ];
    $buttons = [];
    $first = true;
    foreach ($modes as $mode => $label) {
        $buttons[] = html_writer::tag('button', $label, [
            'type' => 'button',
            'class' => 'btn bqa-mode-btn ' . ($first ? 'btn-primary' : 'btn-outline-primary'),
            'data-mode' => $mode,
        ]);
        $first = false;
    }
    $switch = html_writer::div(implode('', $buttons), 'btn-group btn-group-sm', ['role' => 'group']);

    $heading = html_writer::tag('h6', get_string('leaderboard', 'block_quick_access'));
    $gradepanel = html_writer::div(
        block_quick_access_render_grade_table($courseid, $limit, $canscores),
        'bqa-mode-panel my-2',
        ['data-panel' => 'grade']
    );
    $completionpanel = html_writer::div(
        block_quick_access_render_completion_table($courseid, $limit, $canscores),
        'bqa-mode-panel my-2 d-none',
        ['data-panel' => 'completion']
    );
    $back = html_writer::tag('button', get_string('back', 'block_quick_access'), [
        'type' => 'button',
        'class' => 'btn btn-sm btn-outline-secondary bqa-back',
    ]);

    return $heading
        . html_writer::div($switch, 'd-flex mb-1')
        . $gradepanel
        . $completionpanel
        . html_writer::div($back, 'mt-2');
}

/**
 * Render the "by grade" leaderboard table.
 *
 * When the viewer cannot see other students' scores ($canscores false), every
 * row except their own shows placeholders instead of name/picture and points;
 * their own row falls back to the requested limit if not among the top entries.
 *
 * @param int $courseid Course ID.
 * @param int $limit Maximum number of entries.
 * @param bool $canscores Whether the viewer may see other students' scores.
 * @return string
 */
function block_quick_access_render_grade_table($courseid, $limit, $canscores = false) {
    global $OUTPUT, $USER;

    $viewerid = $canscores ? null : (int)$USER->id;
    $entries = block_quick_access_get_leaderboard($courseid, $limit, $viewerid);
    if (empty($entries)) {
        return html_writer::div(get_string('noentries', 'block_quick_access'), 'text-muted');
    }

    $table = new html_table();
    $table->id = 'block_quick_access_leaderboard';
    $table->head = [
        get_string('rank', 'block_quick_access'),
        get_string('user', 'block_quick_access'),
        get_string('points', 'block_quick_access'),
    ];
    $table->attributes['class'] = 'generaltable table-sm mb-0';

    foreach ($entries as $entry) {
        $user = $entry['user'];
        $own = (int)$entry['userid'] === $viewerid;
        if ($canscores || $own) {
            $picture = $OUTPUT->user_picture($user, ['courseid' => $courseid, 'size' => 24]);
            $name = html_writer::link(
                new moodle_url('/user/view.php', ['id' => $user->id, 'course' => $courseid]),
                fullname($user)
            );
            $points = $entry['grade'] !== null ? format_float($entry['grade'], 2, true) : '-';
        } else {
            $picture = '';
            $name = '-';
            $points = '-';
        }
        $row = new html_table_row();
        $row->attributes['class'] = block_quick_access_rank_class($entry['rank']);
        $row->cells = [
            block_quick_access_rank_display($entry['rank']),
            $picture . ' ' . $name,
            $points,
        ];
        $table->data[] = $row;
    }

    return html_writer::table($table);
}

/**
 * Render the "by completion" leaderboard table (see grade-table privacy rules).
 *
 * @param int $courseid Course ID.
 * @param int $limit Maximum number of entries.
 * @param bool $canscores Whether the viewer may see other students' scores.
 * @return string
 */
function block_quick_access_render_completion_table($courseid, $limit, $canscores = false) {
    global $OUTPUT, $USER;

    $viewerid = $canscores ? null : (int)$USER->id;
    $entries = block_quick_access_get_leaderboard_completion($courseid, $limit, $viewerid);
    if (empty($entries)) {
        return html_writer::div(get_string('nocompletiondata', 'block_quick_access'), 'text-muted');
    }

    $table = new html_table();
    $table->id = 'block_quick_access_leaderboard_completion';
    $table->head = [
        get_string('rank', 'block_quick_access'),
        get_string('user', 'block_quick_access'),
        get_string('completionrate', 'block_quick_access'),
    ];
    $table->attributes['class'] = 'generaltable table-sm mb-0';

    foreach ($entries as $entry) {
        $user = $entry['user'];
        $own = (int)$entry['userid'] === $viewerid;
        if ($canscores || $own) {
            $picture = $OUTPUT->user_picture($user, ['courseid' => $courseid, 'size' => 24]);
            $name = html_writer::link(
                new moodle_url('/user/view.php', ['id' => $user->id, 'course' => $courseid]),
                fullname($user)
            );
        } else {
            $picture = '';
            $name = '-';
        }
        $row = new html_table_row();
        $row->attributes['class'] = block_quick_access_rank_class($entry['rank']);
        $row->cells = [
            block_quick_access_rank_display($entry['rank']),
            $picture . ' ' . $name,
            ($canscores || $own) ? round($entry['percent']) . '%' : '-',
        ];
        $table->data[] = $row;
    }

    return html_writer::table($table);
}

/**
 * Build a course leaderboard ranked by the course-total grade.
 *
 * Students without a visible course-total grade (unset or hidden) are appended
 * at the end (rank '-'). Results are cached for a short TTL; cache entries are
 * only produced when no viewer id is passed, so masked views never leak.
 *
 * @param int $courseid Course ID.
 * @param int $limit Maximum number of entries to return.
 * @param int|null $viewerid When given, ensure this user's row is included
 *        (used for the masked view where students see only their own row).
 * @return array[] List of entries, each with keys: rank, grade, user.
 */
function block_quick_access_get_leaderboard($courseid, $limit = 5, $viewerid = null) {
    $context = context_course::instance($courseid);

    $enrolled = block_quick_access_get_enrolled_students($context);
    if (empty($enrolled)) {
        return [];
    }

    if ($viewerid === null) {
        $cache = cache::make('block_quick_access', 'result');
        $key = 'grade_' . $courseid . '_' . $limit;
        if (($cached = $cache->get($key)) !== false) {
            return $cached;
        }
    }

    $grades = grade_get_course_grades($courseid, array_keys($enrolled));

    $hidden = block_quick_access_get_hidden_grade_users($courseid, array_keys($enrolled));

    $scored = [];
    $unscored = [];
    foreach ($grades->grades as $userid => $grade) {
        if (!isset($enrolled[$userid])) {
            continue;
        }
        if (isset($hidden[$userid])) {
            $unscored[] = $userid;
        } else if ($grade->grade === null || $grade->grade === '') {
            $unscored[] = $userid;
        } else {
            $scored[$userid] = (float)$grade->grade;
        }
    }

    $result = block_quick_access_rank_entries($scored, $limit, 'grade');

    foreach ($unscored as $userid) {
        if (count($result) >= $limit) {
            break;
        }
        $result[] = [
            'rank' => '-',
            'grade' => null,
            'userid' => $userid,
        ];
    }

    if ($viewerid !== null) {
        block_quick_access_append_viewer_entry($result, $scored, $viewerid, 'grade');
    }

    $userids = array_column($result, 'userid');
    $users = $userids ? user_get_users_by_id($userids) : [];

    foreach ($result as &$entry) {
        $entry['user'] = $users[$entry['userid']] ?? null;
    }
    unset($entry);

    $result = array_values(array_filter($result, function ($entry) {
        return $entry['user'] !== null;
    }));

    if ($viewerid === null) {
        $cache->set($key, $result);
    }

    return $result;
}

/**
 * User ids whose course-total grade is currently hidden from students.
 *
 * A grade is hidden when grade_grades.hidden is set (1 = permanently hidden,
 * a future timestamp = hidden until then).
 *
 * @param int $courseid Course ID.
 * @param int[] $userids Users to check.
 * @return array Map userid => true.
 */
function block_quick_access_get_hidden_grade_users($courseid, array $userids) {
    global $DB;

    if (!$userids) {
        return [];
    }

    $item = grade_item::fetch_course_item($courseid);
    if (!$item) {
        return [];
    }

    list($insql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
    $params['itemid'] = (int)$item->id;
    $params['now'] = time();
    $sql = "SELECT DISTINCT userid
              FROM {grade_grades}
             WHERE itemid = :itemid AND userid $insql
               AND (hidden = 1 OR (hidden > 0 AND hidden > :now))";

    $hidden = [];
    foreach ($DB->get_records_sql($sql, $params) as $row) {
        $hidden[(int)$row->userid] = true;
    }
    return $hidden;
}

/**
 * Append the viewer's own row to a ranked result when they fall outside the
 * top entries, computing their absolute (tie-aware) rank.
 *
 * @param array $result Ranked entries list, passed by reference.
 * @param float[] $scores All scores (userid => score).
 * @param int|null $viewerid Viewer user id.
 * @param string $valuekey Key storing the score in each entry.
 * @return bool Whether a new row was appended.
 */
function block_quick_access_append_viewer_entry(array &$result, array $scores, $viewerid, $valuekey) {
    if (!$viewerid || !isset($scores[(int)$viewerid])) {
        return false;
    }
    foreach ($result as $entry) {
        if ((int)$entry['userid'] === (int)$viewerid) {
            return false;
        }
    }

    arsort($scores);
    $position = 0;
    $rank = 0;
    $last = null;
    foreach ($scores as $userid => $score) {
        $position++;
        if ($score !== $last) {
            $rank = $position;
            $last = $score;
        }
        if ((int)$userid === (int)$viewerid) {
            break;
        }
    }

    $result[] = [
        'rank' => $rank,
        'userid' => (int)$viewerid,
        $valuekey => $scores[(int)$viewerid],
    ];
    return true;
}

/**
 * Build a course leaderboard ranked by the percentage of activities
 * completed/read by each student (see grade getter for viewer/caching rules).
 *
 * @param int $courseid Course ID.
 * @param int $limit Maximum number of entries.
 * @param int|null $viewerid Ensure this user's row is included (masked view).
 * @return array[] List of entries, each with keys: rank, percent, user.
 */
function block_quick_access_get_leaderboard_completion($courseid, $limit = 5, $viewerid = null) {
    $context = context_course::instance($courseid);

    $enrolled = block_quick_access_get_enrolled_students($context);
    if (empty($enrolled)) {
        return [];
    }

    if ($viewerid === null) {
        $cache = cache::make('block_quick_access', 'result');
        $key = 'completion_' . $courseid . '_' . $limit;
        if (($cached = $cache->get($key)) !== false) {
            return $cached;
        }
    }

    $course = get_course($courseid);
    $cms = [];
    $modinfo = get_fast_modinfo($course);
    foreach ($modinfo->get_cms() as $cm) {
        if ($cm->modname === 'label' || $cm->modname === 'forum') {
            continue;
        }
        if (!$cm->visible) {
            continue;
        }
        $cms[] = $cm;
    }

    $scores = block_quick_access_course_activity_completion($course, array_keys($enrolled), $cms);
    if ($scores === null) {
        return [];
    }

    $result = block_quick_access_rank_entries($scores, $limit, 'percent');

    if ($viewerid !== null) {
        if (block_quick_access_append_viewer_entry($result, $scores, $viewerid, 'percent')) {
            $viewer = user_get_users_by_id([$viewerid])[$viewerid] ?? null;
            if ($viewer) {
                $result[count($result) - 1]['user'] = $viewer;
            }
        }
    }

    if ($viewerid === null) {
        $cache->set($key, $result);
    }

    return $result;
}

/**
 * Percentage of course activities completed/read by each of the given users.
 *
 * An activity counts as completed when the student has actually finished it
 * on their own, no teacher grading needed: completion tracking met, an
 * assignment submission sent, or the activity viewed (read).
 *
 * @param \stdClass $course Course record (used for logstore scope/filtering).
 * @param int[] $userids
 * @param \cm_info[] $cms List of course modules to count.
 * @return float[]|null Map userid => percentage, or null when no activities.
 */
function block_quick_access_course_activity_completion(\stdClass $course, array $userids, array $cms) {
    global $DB;

    $total = count($cms);
    if (!$total) {
        return null;
    }

    $completed = array_fill_keys($userids, 0);

    $tracked = [];
    $assigns = [];
    $quizzes = [];
    $views = [];
    foreach ($cms as $cm) {
        if ($cm->completion != COMPLETION_TRACKING_NONE) {
            $tracked[] = $cm->id;
        } else if ($cm->modname === 'assign') {
            $assigns[] = $cm->instance;
        } else if ($cm->modname === 'quiz') {
            $quizzes[] = $cm->instance;
        } else {
            $views[] = $cm->id;
        }
    }

    // Activities with completion tracking: add those the student completed
    // (a state of COMPLETE or COMPLETE_PASS; the raw state bit is not enough).
    if ($tracked) {
        list($in, $params) = $DB->get_in_or_equal($tracked, SQL_PARAMS_NAMED, 'cm');
        $params['complete'] = COMPLETION_COMPLETE;
        $params['completepass'] = COMPLETION_COMPLETE_PASS;
        $sql = "SELECT userid, COUNT(*) AS n
                  FROM {course_modules_completion}
                 WHERE coursemoduleid $in AND completionstate IN (:complete, :completepass)
                 GROUP BY userid";
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            if (isset($completed[$row->userid])) {
                $completed[$row->userid] += (int)$row->n;
            }
        }
    }

    // Assignments: a submission sent by the student counts, grading not required.
    if ($assigns) {
        list($in, $params) = $DB->get_in_or_equal($assigns, SQL_PARAMS_NAMED, 'asg');
        $params['status1'] = 'submitted';
        $params['status2'] = 'reopened';
        $sql = "SELECT userid, COUNT(DISTINCT assignment) AS n
                  FROM {assign_submission}
                 WHERE assignment $in AND userid <> 0 AND status IN (:status1, :status2)
                 GROUP BY userid";
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            if (isset($completed[$row->userid])) {
                $completed[$row->userid] += (int)$row->n;
            }
        }
    }

    // Quizzes: a finished (non-preview) attempt means the student completed the quiz.
    if ($quizzes) {
        list($in, $params) = $DB->get_in_or_equal($quizzes, SQL_PARAMS_NAMED, 'qz');
        $params['finished'] = 'finished';
        $params['preview'] = 0;
        $sql = "SELECT userid, COUNT(DISTINCT quiz) AS n
                  FROM {quiz_attempts}
                 WHERE quiz $in AND userid <> 0 AND preview = :preview AND state = :finished
                 GROUP BY userid";
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            if (isset($completed[$row->userid])) {
                $completed[$row->userid] += (int)$row->n;
            }
        }
    }

    // Other activities: a course module "viewed" log entry means the student
    // read it. Only counted when logstore_standard is enabled; the query is
    // scoped to this course and the course start date (or a one-year window).
    if ($views && get_config('logstore_standard', 'enabled')) {
        list($in, $params) = $DB->get_in_or_equal($views, SQL_PARAMS_NAMED, 'cm');
        $params['courseid'] = (int)$course->id;
        $params['from'] = (int)$course->startdate;
        if ($params['from'] <= 0) {
            $params['from'] = time() - 365 * DAYSECS;
        }
        $sql = "SELECT userid, COUNT(DISTINCT objectid) AS n
                  FROM {logstore_standard_log}
                 WHERE courseid = :courseid AND objecttable = 'course_modules'
                   AND objectid $in AND action IN ('viewed', 'view')
                   AND timecreated >= :from
                 GROUP BY userid";
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            if (isset($completed[$row->userid])) {
                $completed[$row->userid] += (int)$row->n;
            }
        }
    }

    foreach ($completed as $userid => $done) {
        $completed[$userid] = (float)($done / $total * 100);
    }

    return $completed;
}