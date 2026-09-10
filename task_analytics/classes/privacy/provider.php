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
 * Privacy provider for the "Task analytics" block.
 *
 * The block only reads assign/quiz data, it never stores anything itself.
 *
 * @package    block_task_analytics
 * @copyright  2026 Your Name
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace block_task_analytics\privacy;

defined('MOODLE_INTERNAL') || die();

/**
 * Null privacy provider (no personal data stored).
 */
class provider implements \core_privacy\local\metadata\null_provider {

    /**
     * Return the language string identifier for the reason of no data storage.
     *
     * @return string
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
