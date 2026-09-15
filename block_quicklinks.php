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
 * Quick links block.
 *
 * A course block where a teacher can add an arbitrary list of
 * "title -> URL" links via the block's edit form. Students (and any
 * other users) see the list read-only.
 *
 * @package    block_quicklinks
 * @copyright  2026 Your Name
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Class block_quicklinks.
 */
class block_quicklinks extends block_base {

    /** Maximum number of link fields shown in the edit form. */
    const MAX_LINKS = 20;

    /**
     * Initialise the block title.
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_quicklinks');
    }

    /**
     * The block has per-instance configuration (the list of links).
     *
     * @return bool
     */
    public function has_config() {
        return false;
    }

    /**
     * Use the standard edit form so teachers can manage the links.
     *
     * @return bool
     */
    public function instance_allow_config() {
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

    /**
     * The block is intended for course pages.
     *
     * @return array
     */
    public function applicable_formats() {
        return [
            'all' => false,
            'site' => true,
            'course-view' => true,
            'mod' => true,
        ];
    }

    /**
     * Return the block content (cached in $this->content).
     *
     * Teachers can manage links via the block's "Configure" action;
     * everyone else sees a plain read-only list.
     *
     * @return stdClass
     */
    public function get_content() {
        global $CFG;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        $links = $this->get_links();

        if (empty($links)) {
            // Show a hint for users who can edit the block instance.
            if ($this->user_can_edit()) {
                $this->content->text = get_string('nolinksyet', 'block_quicklinks');
            }
            return $this->content;
        }

        $items = [];
        foreach ($links as $link) {
            $url = new moodle_url($link['url']);
            $items[] = html_writer::tag('li', html_writer::link($url, s($link['title'])));
        }

        $this->content->text = html_writer::tag('ul', implode('', $items), ['class' => 'list quicklinks-links']);

        return $this->content;
    }

    /**
     * Decode the configured links from the instance config.
     *
     * The edit form stores links as parallel arrays:
     * $this->config->linktitle[] and $this->config->linkurl[].
     *
     * @return array[] Array of ['title' => string, 'url' => string].
     */
    protected function get_links(): array {
        if (empty($this->config) || empty($this->config->linktitle) || !is_array($this->config->linktitle)) {
            return [];
        }

        $titles = $this->config->linktitle;
        $urls = is_array($this->config->linkurl ?? null) ? $this->config->linkurl : [];

        $links = [];
        foreach ($titles as $i => $title) {
            $title = trim($title ?? '');
            $url = trim($urls[$i] ?? '');
            if ($title === '' || $url === '') {
                continue;
            }
            $links[] = ['title' => $title, 'url' => $url];
        }

        return $links;
    }

    /**
     * Whether the current user may edit this block instance (add links).
     *
     * @return bool
     */
    protected function user_can_edit(): bool {
        if (empty($this->instance)) {
            return false;
        }
        $context = context_block::instance($this->instance->id);
        return has_capability('moodle/block:edit', $context);
    }
}
