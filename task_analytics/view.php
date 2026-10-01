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
 * Supports filtering by course / status and pagination (review fix).
 *
 * @package    block_task_analytics
 * @copyright  2026 Spada1557
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

require_login();

$coursefilter = optional_param('courseid', 0, PARAM_INT);
$statusfilter = optional_param('status', '', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 20, PARAM_INT);
$perpage = min(max($perpage, 5), 100);

$baseurl = new moodle_url('/blocks/task_analytics/view.php');
$PAGE->set_url($baseurl, ['courseid' => $coursefilter, 'status' => $statusfilter, 'page' => $page]);
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('alltasks', 'block_task_analytics'));
$PAGE->set_heading(get_string('alltasks', 'block_task_analytics'));
$PAGE->navbar->add(get_string('pluginname', 'block_task_analytics'));

$items = \block_task_analytics\service::get_overview(0);

// Build course options for the filter (from the loaded items — no extra queries).
$courses = [];
foreach ($items as $it) {
    $courses[(int)$it['courseid']] = $it['courseshortname'];
}
asort($courses);

// Apply filters in PHP (items are already cached + sorted).
$filtered = array_values(array_filter($items, function(array $it) use ($coursefilter, $statusfilter): bool {
    if ($coursefilter && (int)$it['courseid'] !== $coursefilter) {
        return false;
    }
    if ($statusfilter !== '' && $it['status'] !== $statusfilter) {
        return false;
    }
    return true;
}));

$total = count($filtered);
$pages = (int)ceil($total / $perpage);
$page = max(0, min($page, max(0, $pages - 1)));
$paged = array_slice($filtered, $page * $perpage, $perpage);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('alltasks', 'block_task_analytics'));

// Filter form (GET, preserves pagination base).
$filterurl = new moodle_url('/blocks/task_analytics/view.php');
echo html_writer::start_tag('form', ['method' => 'get', 'action' => $filterurl->out(false),
    'class' => 'task-analytics-filter form-inline mb-3']);
echo html_writer::label(get_string('filtercourse', 'block_task_analytics'), 'id_courseid', false,
    ['class' => 'mr-2']);
echo html_writer::select(
    [0 => get_string('allcourses', 'block_task_analytics')] + $courses,
    'courseid',
    $coursefilter,
    false,
    ['id' => 'id_courseid', 'class' => 'custom-select mr-2']
);
echo html_writer::label(get_string('filterstatus', 'block_task_analytics'), 'id_status', false,
    ['class' => 'mr-2 ml-2']);
echo html_writer::select(
    [
        '' => get_string('allstatuses', 'block_task_analytics'),
        \block_task_analytics\service::STATUS_INREVIEW => get_string('inreview', 'block_task_analytics'),
        \block_task_analytics\service::STATUS_NOTSUBMITTED => get_string('notsubmitted', 'block_task_analytics'),
        \block_task_analytics\service::STATUS_GRADED => get_string('gradedteacher', 'block_task_analytics'),
    ],
    'status',
    $statusfilter,
    false,
    ['id' => 'id_status', 'class' => 'custom-select mr-2']
);
echo html_writer::empty_tag('input', ['type' => 'submit',
    'value' => get_string('filterapply', 'block_task_analytics'), 'class' => 'btn btn-secondary']);
echo html_writer::end_tag('form');

if (empty($filtered)) {
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

foreach ($paged as $item) {
    $tasklink = html_writer::link($item['url'], format_string($item['name']));

    if (!empty($item['duedate'])) {
        $duedate = userdate($item['duedate'], get_string('strftimedatetime', 'langconfig'));
    } else {
        $duedate = get_string('noduedate', 'block_task_analytics');
    }

    // Shared renderer: single source of status/badge logic with the block.
    $badge = \block_task_analytics\renderer::status_badge($item);
    $action = \block_task_analytics\renderer::action_link($item);

    $table->data[] = [
        format_string($item['courseshortname']),
        $tasklink,
        $duedate,
        $badge,
        $action,
    ];
}

echo html_writer::table($table);
echo $OUTPUT->paging_bar($total, $page, $perpage,
    new moodle_url('/blocks/task_analytics/view.php', ['courseid' => $coursefilter, 'status' => $statusfilter]));
echo $OUTPUT->footer();
