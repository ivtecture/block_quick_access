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
 * Course progress block.
 *
 * Displays the current user's progress through the course as a progress
 * bar: the percentage of course activities with Activity completion that
 * the user has completed.
 *
 * @package    block_course_progress
 * @copyright  2026 Your Name
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Class block_course_progress.
 */
class block_course_progress extends block_base {

    /**
     * Initialise the block title.
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_course_progress');
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

        // The block only makes sense inside a course. On pages without a
        // course id (front page, dashboard, ...) show a hint instead.
        if (empty($this->page->course->id) || $this->page->course->id == SITEID) {
            $this->content->text = html_writer::div(
                get_string('coursecontextonly', 'block_course_progress'),
                'alert alert-info my-0'
            );
            return $this->content;
        }

        global $USER;

        $course = $this->page->course;
        $userid = $USER->id;

        // Guests have no completion data to show.
        if (isguestuser() || !$userid) {
            $this->content->text = html_writer::div(
                get_string('guestsnocontent', 'block_course_progress'),
                'alert alert-info my-0'
            );
            return $this->content;
        }

        $completion = new completion_info($course);

        // Activity completion must be enabled for the course.
        if (!$completion->is_enabled()) {
            $this->content->text = html_writer::div(
                get_string('completionnotenabled', 'block_course_progress'),
                'alert alert-warning my-0'
            );
            return $this->content;
        }

        // Activities with (enabled) completion tracking in this course.
        $activities = $completion->get_activities();

        if (empty($activities)) {
            $this->content->text = html_writer::div(
                get_string('noactivities', 'block_course_progress'),
                'alert alert-info my-0'
            );
            return $this->content;
        }

        $total = 0;
        $completed = 0;
        foreach ($activities as $cm) {
            $total++;
            $data = $completion->get_data($cm, false, $userid);
            if ($data->completionstate == COMPLETION_COMPLETE ||
                $data->completionstate == COMPLETION_COMPLETE_PASS) {
                $completed++;
            }
        }

        $percent = ($total > 0) ? (int) round($completed / $total * 100) : 0;

        // Pick the bar colour by progress: red < 50, yellow < 100, green = 100.
        if ($percent >= 100) {
            $barclass = 'bg-success';
        } else if ($percent >= 50) {
            $barclass = 'bg-warning';
        } else {
            $barclass = 'bg-danger';
        }

        $bar = html_writer::div($percent . '%', 'progress-bar ' . $barclass, [
            'role' => 'progressbar',
            'aria-valuenow' => $percent,
            'aria-valuemin' => 0,
            'aria-valuemax' => 100,
            'style' => 'width: ' . $percent . '%;',
        ]);

        $progresshtml = html_writer::div($bar, 'progress my-2', ['style' => 'height: 1.5rem;']);
        $details = html_writer::div(
            get_string('progressdetails', 'block_course_progress', (object) [
                'completed' => $completed,
                'total' => $total,
                'percent' => $percent,
            ]),
            'text-center small'
        );

        $this->content->text = $progresshtml . $details;

        return $this->content;
    }

    /**
     * The block is only meant for course pages (and activities within a course).
     *
     * @return array
     */
    public function applicable_formats() {
        return [
            'all' => false,
            'course' => true,
            'mod' => true,
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
