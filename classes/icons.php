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
 * Icons of the "Quick access" block.
 *
 * @package    block_quick_access
 * @copyright  2026 Your Name
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace block_quick_access;

defined('MOODLE_INTERNAL') || die();

/**
 * Class icons.
 *
 * A handful of small inline SVG glyphs. The cloud renders the same icons from
 * JavaScript, so the markup lives here only to avoid an extra request and to
 * keep the block independent of the icon theme.
 */
class icons {

    /**
     * Paths of the supported glyphs, drawn on a 16x16 grid.
     *
     * @var string[]
     */
    const PATHS = [
        'add' => 'M7 2h2v5h5v2H9v5H7V9H2V7h5z',
        'edit' => 'M11.7 1.4 14.6 4.3 6.2 12.7 2 14l1.3-4.2zM2.6 12.5l-.6 1.9 1.9-.6z',
        'trash' => 'M6.3 1h3.4v1H14v1.5H2V2h4.3zM3.2 4.5h9.6l-.7 9.4a1 1 0 0 1-1 .9H4.9a1 1 0 0 1-1-.9z',
    ];

    /**
     * Return one of the glyphs as inline SVG.
     *
     * @param string $name One of the keys of self::PATHS.
     * @return string
     */
    public static function get(string $name): string {
        if (!isset(self::PATHS[$name])) {
            return '';
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16" ' .
            'class="icon block-quick-access-cloud__icon" aria-hidden="true" focusable="false">' .
            '<path d="%s" fill="currentColor"/></svg>',
            self::PATHS[$name]
        );
    }
}
