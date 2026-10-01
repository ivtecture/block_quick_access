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
 * Toggles between the block menu and the leaderboard panel.
 *
 * @module     block_quick_access/main
 * @copyright  2026 Human Mind
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

define(['jquery'], function($) {

    /**
     * Initialise the block interactions.
     */
    function init() {
        var bqasel = '.bqa-block';

        // Show the leaderboard panel and hide the menu.
        $(document).on('click', '.bqa-show-leaderboards', function(e) {
            e.preventDefault();
            var block = $(this).closest(bqasel);
            block.find('.bqa-menu').addClass('d-none');
            block.find('.bqa-leaderboards').removeClass('d-none');
        });

        // Return to the block menu.
        $(document).on('click', '.bqa-back', function() {
            var block = $(this).closest(bqasel);
            block.find('.bqa-leaderboards').addClass('d-none');
            block.find('.bqa-menu').removeClass('d-none');
        });

        // Switch between the grade and completion leaderboards.
        $(document).on('click', '.bqa-mode-btn', function() {
            var block = $(this).closest(bqasel);
            var mode = $(this).data('mode');
            block.find('.bqa-mode-btn')
                .removeClass('btn-primary')
                .addClass('btn-outline-primary');
            $(this)
                .removeClass('btn-outline-primary')
                .addClass('btn-primary');
            block.find('.bqa-mode-panel').addClass('d-none');
            block.find('[data-panel="' + mode + '"]').removeClass('d-none');
        });
    }

    return {
        init: init
    };
});