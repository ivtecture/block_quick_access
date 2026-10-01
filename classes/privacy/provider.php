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

namespace block_quick_access\privacy;

use core_privacy\local\metadata\null_provider;

/**
 * The quick access block only displays data already present in Moodle and
 * does not store any personal data itself.
 *
 * @package    block_quick_access
 * @copyright  2026 Human Mind
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
class provider implements null_provider {

    /**
     * Get the language string identifier for the privacy explanation.
     *
     * @return string
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}