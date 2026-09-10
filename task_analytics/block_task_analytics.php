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
 * Task analytics block.
 *
 * Compact overview (max 7 items) of all assign/quiz tasks across the current
 * user's enrolled courses. Students see their own submission state, teachers
 * see aggregates waiting for grading. The full list lives in view.php.
 *
 * @package    block_task_analytics
 * @copyright  2026 Your Name
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Class block_task_analytics.
 */
class block_task_analytics extends block_base {

    /**
     * Initialise the block title.
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_task_analytics');
    }

    /**
     * Return the block content (cached in $this->content).
     *
     * @return stdClass
     */
    public function get_content() {
        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        if (!isloggedin() || isguestuser()) {
            return $this->content;
        }

        $items = \block_task_analytics\service::get_overview(\block_task_analytics\service::BLOCK_LIMIT);

        if (empty($items)) {
            $this->content->text = html_writer::div(
                get_string('notasks', 'block_task_analytics'),
                'task-analytics-empty text-muted'
            );
            return $this->content;
        }

        $lis = [];
        foreach ($items as $item) {
            $lis[] = html_writer::tag('li', $this->render_item_compact($item), ['class' => 'task-analytics-item']);
        }
        $this->content->text = html_writer::tag('ul', implode('', $lis), ['class' => 'list task-analytics-list']);

        $viewallurl = new moodle_url('/blocks/task_analytics/view.php');
        $this->content->footer = html_writer::link(
            $viewallurl,
            get_string('viewall', 'block_task_analytics'),
            ['class' => 'task-analytics-viewall']
        );

        return $this->content;
    }

    /**
     * Render one compact list row: course, task link, status badge, action link.
     *
     * @param array $item overview item from service::get_overview().
     * @return string HTML fragment.
     */
    protected function render_item_compact(array $item): string {
        $tasklink = html_writer::link($item['url'], format_string($item['name']), ['class' => 'task-analytics-name']);
        $course = html_writer::div(
            format_string($item['courseshortname']),
            'task-analytics-course text-muted small'
        );

        if ($item['status'] === \block_task_analytics\service::STATUS_GRADED && !$item['isteacher']) {
            $statustext = get_string('graded', 'block_task_analytics', $item['gradedisplay']);
        } else if ($item['status'] === \block_task_analytics\service::STATUS_INREVIEW && $item['isteacher']) {
            $statustext = get_string('inreview', 'block_task_analytics')
                . ' (' . get_string('pending', 'block_task_analytics', $item['pending']) . ')';
        } else {
            $statustext = get_string($item['status'], 'block_task_analytics');
        }
        $badge = html_writer::span($statustext, 'task-status task-status-' . $item['status']);

        $action = '';
        if (!empty($item['actionurl']) && !empty($item['actionlabel'])) {
            // For graded student items the action points at the feedback/review page.
            // For plain "open task" rows the action duplicates the title link, so skip it.
            $isduplicate = ($item['actionlabel'] === 'viewlink' && (string)$item['actionurl'] === (string)$item['url']);
            if (!$isduplicate) {
                $action = html_writer::link(
                    $item['actionurl'],
                    get_string($item['actionlabel'], 'block_task_analytics'),
                    ['class' => 'task-analytics-action small']
                );
            }
        }

        $meta = $badge . ($action !== '' ? ' ' . $action : '');
        return $course . $tasklink . html_writer::div($meta, 'task-analytics-meta');
    }

    /**
     * The block may be added on the dashboard, course pages and the site home.
     *
     * @return array
     */
    public function applicable_formats() {
        return [
            'my' => true,
            'course-view' => true,
            'site-index' => true,
        ];
    }

    /**
     * The block has no global (admin) configuration.
     *
     * @return bool
     */
    public function has_config() {
        return false;
    }

    /**
     * Only one instance of the block per page is allowed.
     *
     * @return bool
     */
    public function instance_allow_multiple() {
        return false;
    }
}
