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
 * Full task list for the "Task analytics" block.
 *
 * Shows every assign/quiz task across the current user's enrolled courses
 * (the block itself renders only the first 7 items).
 *
 * @package    block_task_analytics
 * @copyright  2026 Your Name
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();

$PAGE->set_url(new moodle_url('/blocks/task_analytics/view.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('alltasks', 'block_task_analytics'));
$PAGE->set_heading(get_string('alltasks', 'block_task_analytics'));
$PAGE->navbar->add(get_string('pluginname', 'block_task_analytics'));

$items = \block_task_analytics\service::get_overview(0);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('alltasks', 'block_task_analytics'));

if (empty($items)) {
    echo $OUTPUT->notification(get_string('notasks', 'block_task_analytics'), 'info');
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->attributes['class'] = 'generaltable task-analytics-table';
$table->head = [
    get_string('course', 'block_task_analytics'),
    get_string('task', 'block_task_analytics'),
    get_string('duedate', 'block_task_analytics'),
    get_string('status', 'block_task_analytics'),
    get_string('action', 'block_task_analytics'),
];

foreach ($items as $item) {
    $tasklink = html_writer::link($item['url'], format_string($item['name']));

    if (!empty($item['duedate'])) {
        $duedate = userdate($item['duedate'], get_string('strftimedatetime', 'langconfig'));
    } else {
        $duedate = get_string('noduedate', 'block_task_analytics');
    }

    if ($item['status'] === \block_task_analytics\service::STATUS_GRADED && !$item['isteacher']) {
        $statustext = get_string('graded', 'block_task_analytics', $item['gradedisplay']);
    } else if ($item['status'] === \block_task_analytics\service::STATUS_INREVIEW && $item['isteacher']) {
        $statustext = get_string('inreview', 'block_task_analytics')
            . ' (' . get_string('pending', 'block_task_analytics', $item['pending']) . ')';
    } else {
        $statustext = get_string($item['status'], 'block_task_analytics');
    }
    $badge = html_writer::span($statustext, 'task-status task-status-' . $item['status']);

    if (!empty($item['actionurl']) && !empty($item['actionlabel'])) {
        $action = html_writer::link(
            $item['actionurl'],
            get_string($item['actionlabel'], 'block_task_analytics')
        );
    } else {
        $action = '';
    }

    $table->data[] = [
        format_string($item['courseshortname']),
        $tasklink,
        $duedate,
        $badge,
        $action,
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
