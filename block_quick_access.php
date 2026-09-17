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
 * Quick access block.
 *
 * Displays a list of handy shortcuts (Dashboard, My courses, Calendar,
 * Site home) plus an administration link for users who are allowed to
 * configure the site.
 *
 * Additionally, on course pages it offers a "Leaderboard" link that opens
 * a panel with two leaderboards (by course-total grade and by course
 * completion) and a "Back" button returning to the block menu.
 *
 * @package    block_quick_access
 * @copyright  2026 Human Mind
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/leaderboard.php');

/**
 * Class block_quick_access.
 */
class block_quick_access extends block_base {

    /**
     * Initialise the block title.
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_quick_access');
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

        // Quick links shown to every user.
        $links = [
            get_string('dashboard', 'block_quick_access') => new moodle_url('/my/'),
            get_string('mycourses', 'block_quick_access') => new moodle_url('/my/courses.php'),
            get_string('calendar', 'block_quick_access')  => new moodle_url('/calendar/view.php', ['view' => 'month']),
            get_string('sitehome', 'block_quick_access')  => new moodle_url('/'),
        ];

        // The administration link is only rendered for users who can configure the site.
        if (has_capability('moodle/site:config', context_system::instance())) {
            $links[get_string('administration', 'block_quick_access')] = new moodle_url('/admin/index.php');
        }

        $items = [];
        foreach ($links as $label => $url) {
            $items[] = html_writer::tag('li', html_writer::link($url, $label));
        }

        // Course leaderboard panel: only meaningful on a real course page.
        $course = $this->page->course;
        $panel = '';
        if ($course && isset($course->id) && (int)$course->id !== SITEID) {
            $panel = block_quick_access_render_leaderboard_panel((int)$course->id);
        }

        $menuclass = 'list quick-access-links';
        if ($panel !== '') {
            $menuclass .= ' bqa-menu-ul';
            $items[] = html_writer::tag('li', html_writer::tag(
                'a',
                get_string('leaderboard', 'block_quick_access'),
                [
                    'href' => '#',
                    'class' => 'btn btn-sm btn-outline-secondary w-100 bqa-show-leaderboards',
                ]
            ));
        }
        $menu = html_writer::tag('ul', implode('', $items), ['class' => $menuclass]);

        if ($panel !== '') {
            $this->content->text = html_writer::div(
                html_writer::div($menu, 'bqa-menu')
                . html_writer::div($panel, 'bqa-leaderboards d-none'),
                'bqa-block'
            );
            $this->page->requires->js_call_amd('block_quick_access/main', 'init');
        } else {
            $this->content->text = html_writer::div($menu, 'bqa-menu bqa-block');
        }

        return $this->content;
    }

    /**
     * The block may be added to any page.
     *
     * @return array
     */
    public function applicable_formats() {
        return ['all' => true];
    }

    /**
     * The block has global (admin) configuration for the leaderboard.
     *
     * @return bool
     */
    public function has_config() {
        return true;
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
