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
 * Services (web service definitions) of the "Quick access" block.
 *
 * @package    block_quick_access
 * @copyright  2026 Your Name
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [

    'block_quick_access_get_words' => [
        'classname' => 'block_quick_access\external',
        'methodname' => 'get_words',
        'description' => 'Return the words of the thought cloud of a course',
        'ajax' => true,
        'capabilities' => 'moodle/course:view',
    ],

    'block_quick_access_add_word' => [
        'classname' => 'block_quick_access\external',
        'methodname' => 'add_word',
        'description' => 'Add a word to the thought cloud of a course',
        'ajax' => true,
        'capabilities' => 'block_quick_access/managewords',
    ],

    'block_quick_access_update_word' => [
        'classname' => 'block_quick_access\external',
        'methodname' => 'update_word',
        'description' => 'Change the text of a word of the thought cloud',
        'ajax' => true,
        'capabilities' => 'block_quick_access/managewords',
    ],

    'block_quick_access_delete_word' => [
        'classname' => 'block_quick_access\external',
        'methodname' => 'delete_word',
        'description' => 'Remove a word from the thought cloud of a course',
        'ajax' => true,
        'capabilities' => 'block_quick_access/managewords',
    ],

];
