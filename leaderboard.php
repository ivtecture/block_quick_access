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
 * Resolve the configured number of top users to display.
 *
 * @return int
 */
function block_quick_access_get_limit() {
    $limit = (int)get_config('block_quick_access', 'limit');
    if ($limit <= 0) {
        $limit = 5;
    }
    return $limit;
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
 * Whether a user holds at least one student role in the given context.
 *
 * @param int $userid
 * @param \context $context
 * @return bool
 */
function block_quick_access_is_student($userid, \context $context) {
    $studentroleids = block_quick_access_get_student_roleids();
    foreach (get_user_roles($context, $userid, false) as $role) {
        if (isset($studentroleids[(int)$role->roleid])) {
            return true;
        }
    }
    return false;
}

/**
 * Enrolled users of the course holding a student role (teachers/managers excluded).
 *
 * @param \context $context Course context.
 * @return \stdClass[] Map userid => user record.
 */
function block_quick_access_get_enrolled_students(\context $context) {
    $students = [];
    foreach (get_enrolled_users($context) as $userid => $user) {
        if (block_quick_access_is_student($userid, $context)) {
            $students[$userid] = $user;
        }
    }
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
        block_quick_access_render_grade_table($courseid, $limit),
        'bqa-mode-panel my-2',
        ['data-panel' => 'grade']
    );
    $completionpanel = html_writer::div(
        block_quick_access_render_completion_table($courseid, $limit),
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
 * @param int $courseid Course ID.
 * @param int $limit Maximum number of entries.
 * @return string
 */
function block_quick_access_render_grade_table($courseid, $limit) {
    global $OUTPUT;

    $entries = block_quick_access_get_leaderboard($courseid, $limit);
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
        $picture = $OUTPUT->user_picture($user, ['courseid' => $courseid, 'size' => 24]);
        $name = html_writer::link(
            new moodle_url('/user/view.php', ['id' => $user->id, 'course' => $courseid]),
            fullname($user)
        );
        $points = $entry['grade'] !== null ? format_float($entry['grade'], 2, true) : '-';
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
 * Render the "by completion" leaderboard table.
 *
 * @param int $courseid Course ID.
 * @param int $limit Maximum number of entries.
 * @return string
 */
function block_quick_access_render_completion_table($courseid, $limit) {
    global $OUTPUT;

    $entries = block_quick_access_get_leaderboard_completion($courseid, $limit);
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
        $picture = $OUTPUT->user_picture($user, ['courseid' => $courseid, 'size' => 24]);
        $name = html_writer::link(
            new moodle_url('/user/view.php', ['id' => $user->id, 'course' => $courseid]),
            fullname($user)
        );
        $row = new html_table_row();
        $row->attributes['class'] = block_quick_access_rank_class($entry['rank']);
        $row->cells = [
            block_quick_access_rank_display($entry['rank']),
            $picture . ' ' . $name,
            round($entry['percent']) . '%',
        ];
        $table->data[] = $row;
    }

    return html_writer::table($table);
}

/**
 * Build a course leaderboard ranked by the course-total grade.
 *
 * Students without a course-total grade are appended at the end (rank '-').
 *
 * @param int $courseid Course ID.
 * @param int $limit Maximum number of entries to return.
 * @return array[] List of entries, each with keys: rank, grade, user.
 */
function block_quick_access_get_leaderboard($courseid, $limit = 5) {
    $context = context_course::instance($courseid);

    $enrolled = block_quick_access_get_enrolled_students($context);
    if (empty($enrolled)) {
        return [];
    }

    $grades = grade_get_course_grades($courseid, array_keys($enrolled));

    $scored = [];
    $unscored = [];
    foreach ($grades->grades as $userid => $grade) {
        if (!isset($enrolled[$userid])) {
            continue;
        }
        if ($grade->grade === null || $grade->grade === '') {
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
 * Build a course leaderboard ranked by the percentage of activities
 * completed/read by each student.
 *
 * @param int $courseid Course ID.
 * @param int $limit Maximum number of entries.
 * @return array[] List of entries, each with keys: rank, percent, user.
 */
function block_quick_access_get_leaderboard_completion($courseid, $limit = 5) {
    $context = context_course::instance($courseid);

    $enrolled = block_quick_access_get_enrolled_students($context);
    if (empty($enrolled)) {
        return [];
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

    $scores = block_quick_access_course_activity_completion(array_keys($enrolled), $cms);
    if ($scores === null) {
        return [];
    }

    return block_quick_access_rank_entries($scores, $limit, 'percent');
}

/**
 * Percentage of course activities completed/read by each of the given users.
 *
 * An activity counts as completed when the student has actually finished it
 * on their own, no teacher grading needed: completion tracking met, an
 * assignment submission sent, or the activity viewed (read).
 *
 * @param int[] $userids
 * @param \cm_info[] $cms List of course modules to count.
 * @return float[]|null Map userid => percentage, or null when no activities.
 */
function block_quick_access_course_activity_completion(array $userids, array $cms) {
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

    // Activities with completion tracking: add those the student completed.
    if ($tracked) {
        list($in, $params) = $DB->get_in_or_equal($tracked, SQL_PARAMS_NAMED, 'cm');
        $params['complete'] = COMPLETION_COMPLETE;
        $sql = "SELECT userid, COUNT(*) AS n
                  FROM {course_modules_completion}
                 WHERE coursemoduleid $in AND (completionstate & :complete) = :complete
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

    // Quizzes: a finished attempt means the student completed the quiz.
    if ($quizzes) {
        list($in, $params) = $DB->get_in_or_equal($quizzes, SQL_PARAMS_NAMED, 'qz');
        $params['finished'] = 'finished';
        $sql = "SELECT userid, COUNT(DISTINCT quiz) AS n
                  FROM {quiz_attempts}
                 WHERE quiz $in AND userid <> 0 AND state = :finished
                 GROUP BY userid";
        foreach ($DB->get_records_sql($sql, $params) as $row) {
            if (isset($completed[$row->userid])) {
                $completed[$row->userid] += (int)$row->n;
            }
        }
    }

    // Other activities: a course module "viewed" log entry means the student read it.
    if ($views) {
        list($in, $params) = $DB->get_in_or_equal($views, SQL_PARAMS_NAMED, 'cm');
        $sql = "SELECT userid, COUNT(DISTINCT objectid) AS n
                  FROM {logstore_standard_log}
                 WHERE objecttable = 'course_modules' AND objectid $in AND action IN ('viewed', 'view')
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