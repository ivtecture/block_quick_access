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
 * On course pages the block also carries the "Thought cloud": a teacher adds
 * words to it and the words are spread over the background of the page.
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
     * Return the block content.
     *
     * The result is built once per request and kept in $this->content.
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

        // The thought cloud only makes sense inside a course.
        $coursecontext = $this->get_course_context();
        if ($coursecontext) {
            $this->content->text .= $this->get_cloud_content($coursecontext);
        }

        return $this->content;
    }

    /**
     * Return the course the block is displayed in, if any.
     *
     * @return context_course|false
     */
    protected function get_course_context() {
        if ($this->context->contextlevel == CONTEXT_COURSE) {
            return $this->context;
        }

        // Blocks of activities and other pages inside a course are nested in its context.
        return $this->context->get_course_context(false);
    }

    /**
     * Render the "Thought cloud" part of the block.
     *
     * @param context_course $coursecontext
     * @return string
     */
    protected function get_cloud_content(context_course $coursecontext): string {
        global $OUTPUT;

        $courseid = (int) $coursecontext->instanceid;
        $canmanage = has_capability('block_quick_access/managewords', $coursecontext);
        $words = $canmanage ? \block_quick_access\wordcloud::get_words($courseid) : [];

        $context = [
            'canmanage' => $canmanage,
            'title' => get_string('cloudtitle', 'block_quick_access'),
            'add' => get_string('addword', 'block_quick_access'),
            'addicon' => \block_quick_access\icons::get('add'),
            'empty' => get_string('cloudempty', 'block_quick_access'),
            'hascwords' => !empty($words),
            'save' => get_string('save'),
            'cancel' => get_string('cancel'),
            'inputlabel' => get_string('wordinput', 'block_quick_access'),
            'placeholder' => get_string('wordplaceholder', 'block_quick_access'),
            'maxwords' => \block_quick_access\wordcloud::MAXWORDS,
            'maxlength' => \block_quick_access\wordcloud::MAXLENGTH,
            'inputid' => 'block-quick-access-cloud-' . $this->instance->id,
            'courseid' => $courseid,
            'serviceurl' => (new moodle_url('/lib/ajax/service.php'))->out(false),
            'words' => [],
        ];

        foreach ($words as $word) {
            $context['words'][] = $word + [
                'editlabel' => get_string('editword', 'block_quick_access', $word['word']),
                'deletelabel' => get_string('deleteword', 'block_quick_access', $word['word']),
                'editicon' => \block_quick_access\icons::get('edit'),
                'deleteicon' => \block_quick_access\icons::get('trash'),
            ];
        }

        return $OUTPUT->render_from_template('block_quick_access/cloud', $context);
    }

    /**
     * Load the AMD module that powers the thought cloud.
     *
     * This hook is the canonical place to require JavaScript: it is invoked by
     * block_base::formatted_contents() on every request the block is rendered,
     * so the module is registered even when the surrounding markup comes from
     * a cache. The module itself is a no-op when the cloud region is absent.
     */
    public function get_required_javascript() {
        if ($this->get_course_context()) {
            $this->page->requires->js_call_amd('block_quick_access/cloud');
        }
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
