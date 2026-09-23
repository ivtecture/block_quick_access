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
        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        $links = $this->get_links();

        // Load the one-click shortcut helper for users who can edit this
        // block (teachers): it completes the jump to the configuration
        // form after the editing mode has been switched on.
        if (!empty($this->instance) && !$this->page->user_is_editing() && $this->user_can_edit()) {
            $editurl = new moodle_url($this->page->url, ['bui_editid' => $this->instance->id]);
            $this->page->requires->js_call_amd('block_quicklinks/shortcut', 'init', [$editurl->out(false)]);
        }

        if (empty($links)) {
            // Show a hint + a shortcut straight to the config form
            // for users who can edit the block instance (teachers).
            if ($this->user_can_edit()) {
                $this->content->text = get_string('nolinksyet', 'block_quicklinks');
                $this->content->footer = $this->get_manage_links_link('addlinks');
            }
            return $this->content;
        }

        $items = [];
        foreach ($links as $link) {
            $url = new moodle_url($link['url']);
            $items[] = html_writer::tag('li', html_writer::link($url, s($link['title'])));
        }

        $this->content->text = html_writer::tag('ul', implode('', $items), ['class' => 'list quicklinks-links']);

        // Quick shortcut to the block's configuration form, so a teacher
        // can add/edit links without enabling editing mode first.
        if ($this->user_can_edit()) {
            $this->content->footer = $this->get_manage_links_link('editlinks');
        }

        return $this->content;
    }

    /**
     * Build a shortcut link that jumps straight to this block instance's
     * configuration form (the very same form the block's gear icon opens).
     *
     * The link is only rendered for users who can edit this block
     * instance (e.g. teachers), students never see it.
     *
     * How it works:
     * - The href points to the current page with bui_editid=<instance id>.
     *   Moodle's block_manager::process_url_edit() then renders the block's
     *   configuration form instead of the page content.
     * - URL block actions are only processed while the editing mode is on,
     *   so when editing is off the link also carries edit=1 + sesskey to
     *   switch the editing mode on first (supported by course/view.php,
     *   course/section.php, my/index.php, ...).
     * - When the editing mode is already on, the core_block/edit JavaScript
     *   module is loaded by core, and the data-* attributes below make the
     *   link open the configuration form in a modal dialog, exactly like
     *   the block's gear menu does (and the href stays as a no-JS fallback).
     *
     * @param string $stringkey Lang string key used as the link label
     *                           ('addlinks' or 'editlinks').
     * @return string HTML for the link, or an empty string if unavailable.
     */
    protected function get_manage_links_link(string $stringkey): string {
        global $OUTPUT;

        if (empty($this->instance) || !$this->user_can_edit()) {
            return '';
        }

        $editing = $this->page->user_is_editing();

        $params = ['bui_editid' => $this->instance->id];
        if (!$editing) {
            // Editing mode is required for the bui_editid URL action to be
            // processed, so turn it on as part of the jump.
            $params['edit'] = 1;
            $params['sesskey'] = sesskey();
        }
        $url = new moodle_url($this->page->url, $params);

        $label = get_string($stringkey, 'block_quicklinks');
        $icon = $OUTPUT->pix_icon('i/settings', $label, 'core', ['class' => 'iconsmall']);

        $attributes = [
            'class' => 'quicklinks-manage-link',
            'title' => $label,
        ];
        if ($editing) {
            // Let the core_block/edit JS pick this link up and show the
            // configuration form in a modal dialog instead of navigating.
            $attributes['data-action'] = 'editblock';
            $attributes['data-blockid'] = $this->instance->id;
            $attributes['data-blockform'] = block_manager::get_block_edit_form_class($this->name());
            $attributes['data-header'] = $label;
        }

        return html_writer::link($url, $icon . s($label), $attributes);
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
    public function user_can_edit(): bool {
        if (empty($this->instance)) {
            return false;
        }
        $context = context_block::instance($this->instance->id);
        return has_capability('moodle/block:edit', $context);
    }
}
