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

namespace block_quick_access;

/**
 * Event observers for the "Quick access" block.
 *
 * @package    block_quick_access
 * @copyright  2026 Human mind
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class observer {

    /**
     * Purge the cached leaderboard results of a course when a grade changes,
     * so newly hidden/graded items show up without waiting for the TTL.
     *
     * @param \core\event\base $event
     */
    public static function grade_changed(\core\event\base $event) {
        $cache = \cache::make('block_quick_access', 'result');

        $data = $event->get_data();
        $courseid = (int)($data['courseid'] ?? 0);
        if ($courseid > 0) {
            // Keys are built as 'grade_' / 'completion_' + courseid + '_' + limit (clamped 1-50).
            for ($limit = 1; $limit <= 50; $limit++) {
                $cache->delete('grade_' . $courseid . '_' . $limit);
                $cache->delete('completion_' . $courseid . '_' . $limit);
            }
        } else {
            $cache->purge();
        }
    }
}