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
 * Edit form for the Quick links block.
 *
 * Provides a repeatable set of "title -> URL" fields so a teacher can
 * add any number of links to be shown to students.
 *
 * Note: the file must be called edit_form.php and the class must be
 * block_<blockname>_edit_form - this is the convention Moodle uses to
 * locate the block's configuration form (both for the block's gear
 * menu and for modal editing, see block_manager::get_block_edit_form_class()).
 *
 * @package    block_quicklinks
 * @copyright  2026 Your Name
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Class block_quicklinks_edit_form.
 */
class block_quicklinks_edit_form extends block_edit_form {

    /**
     * Add the repeatable link fields to the form.
     *
     * @param MoodleQuickForm $mform
     */
    protected function specific_definition($mform) {

        $mform->addElement('header', 'configheader', get_string('linksheader', 'block_quicklinks'));

        // Pre-fill the form with configured links (+1 empty row), at least 3 rows.
        // Note: the saved config keys are 'linktitle'/'linkurl' (the mandatory
        // 'config_' prefix below is stripped by core when saving the form).
        $repeats = 3;
        if (!empty($this->block->config) && !empty($this->block->config->linktitle)
                && is_array($this->block->config->linktitle)) {
            $repeats = max(count($this->block->config->linktitle) + 1, 3);
        }

        // IMPORTANT: element names MUST start with 'config_' - block_manager::save_block_data()
        // only copies fields with that prefix into the block instance configuration
        // (see lib/blocklib.php). Fields without the prefix are silently discarded.
        $repeatelements = [
            'config_linktitle' => $mform->createElement('text', 'config_linktitle', get_string('linktitle', 'block_quicklinks'), ['size' => '40']),
            'config_linkurl' => $mform->createElement('text', 'config_linkurl', get_string('linkurl', 'block_quicklinks'), ['size' => '40']),
        ];

        $repeatoptions = [
            'config_linktitle' => [
                'type' => PARAM_TEXT,
            ],
            'config_linkurl' => [
                'type' => PARAM_URL,
            ],
        ];

        $this->repeat_elements($repeatelements, $repeats, $repeatoptions,
            'links_repeats', 'links_add', 1,
            get_string('addlink', 'block_quicklinks'), true);
    }
}
