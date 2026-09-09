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
 * @package    block_quick_access
 * @copyright  2026 Your Name
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

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

        $this->content->text = html_writer::tag('ul', implode('', $items), ['class' => 'list quick-access-links']);

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
