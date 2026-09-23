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
 * One-click shortcut to the block's configuration form.
 *
 * Moodle only processes the bui_editid URL action while the editing mode
 * is on, and turning editing on via edit=1 redirects to the same page
 * with notifyeditingon=1, dropping bui_editid. So the shortcut works in
 * two steps:
 *
 *  1. The user clicks the shortcut link (href carries edit=1&sesskey&
 *     bui_editid). Before navigating away we remember the "clean" edit
 *     URL (bui_editid only) in the sessionStorage.
 *  2. After the redirect to ...&notifyeditingon=1 we continue to the
 *     remembered URL - editing is on now, so Moodle renders the block's
 *     configuration form.
 *
 * When the editing mode is already on, the link carries the core
 * data-action="editblock" attributes and the core_block/edit module
 * opens the configuration form in a modal dialog instead (this module
 * is not even loaded then).
 *
 * @module     block_quicklinks/shortcut
 * @copyright  2026 Your Name
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

const STORAGE_KEY = 'block_quicklinks_editurl';

/**
 * Initialise the shortcut helper.
 *
 * @param {String} editurl URL of the current page with bui_editid added.
 */
export const init = (editurl) => {
    // Step 1: remember where to go before the editing-mode redirect
    // drops the bui_editid parameter.
    document.addEventListener('click', (e) => {
        const link = e.target.closest('.quicklinks-manage-link');
        if (!link || document.body.classList.contains('editing')) {
            return;
        }
        try {
            window.sessionStorage.setItem(STORAGE_KEY, editurl);
        } catch (e) {
            // No sessionStorage available: just follow the href.
        }
    }, true);

    // Step 2: continue to the configuration form once editing is on.
    const params = new URLSearchParams(window.location.search);
    if (params.get('notifyeditingon')) {
        let saved = null;
        try {
            saved = window.sessionStorage.getItem(STORAGE_KEY);
            window.sessionStorage.removeItem(STORAGE_KEY);
        } catch (e) {
            saved = null;
        }
        if (saved) {
            window.location.replace(saved);
        }
    }
};
