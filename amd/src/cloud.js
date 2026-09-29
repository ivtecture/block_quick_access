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
 * The thought cloud ("Облако мыслей") of the "Quick access" block.
 *
 * The module does two things:
 *
 *  1. it keeps the list of words inside the block in sync with the server
 *     (add, change and remove are plain AJAX calls);
 *  2. it spreads the words over the background of the page. Every word carries
 *     a target spot chosen on the server, but the target is only a wish: the
 *     module measures the real page, marks everything that must stay readable
 *     (navigation, forms, buttons, the block itself) as blocked and everything
 *     that is merely content as costly, and then picks for every word the free
 *     cell that is closest to the target and touches as little content as
 *     possible. Words never sit on a control, they never cover each other, and
 *     they keep their relative position between page loads.
 *
 * @module     block_quick_access/cloud
 * @copyright  2026 Your Name
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

define([
    'core/ajax',
    'core/str',
    'core/pending',
    'core/notification'
], function(Ajax, Str, Pending, Notification) {
    'use strict';

    var REGION = '[data-region="block-quick-access-cloud"]';
    var OVERLAY_ID = 'block-quick-access-cloud-overlay';
    var HOST_ID = 'block-quick-access-cloud-host';

    // Geometry of the placement grid.
    var GRID = 20;            // Edge of one placement cell, px.
    var PADDING = 8;          // Free space kept around every obstacle, px.
    var NEARBY = 14;          // Cells searched around the target before scanning everything.
    var COST_PENALTY = 40;    // Score added per cell covered by content.
    var RELAYOUT_DELAY = 250; // Quiet period before the cloud is laid out again, ms.

    // Word look. The values are derived from the word id, so a word always
    // looks the same.
    var MIN_FONT = 13;
    var MAX_FONT = 30;
    var MIN_OPACITY = 0.16;
    var MAX_OPACITY = 0.34;
    var MAX_TILT = 4;         // Degrees a word may be tilted by.

    // Things a word must never cover: page furniture, anything clickable and
    // the block itself.
    var BLOCKING = [
        'nav', 'header', 'footer',
        '.navbar', '.navbar-nav', '.breadcrumb', '.drawer-toggles', '.sidemenu', '.moremenu',
        '.modal', '.offcanvas', '.toast', '.dropdown-menu', '.popover', '.tooltip', '.collapsible',
        '.block', '.card-header', '.card-footer', '.drawer-content',
        'form', 'fieldset', 'button', 'input', 'select', 'textarea', 'label', 'summary',
        '.btn', '.btn-group', '.nav-tabs', '.nav-pills', '.form-control', '.mform',
        '.sticky-top', '.sticky-bottom', '.fixed-top', '.fixed-bottom',
        '#admin-searchbox', '#usernavigation', '.user-menu', '.alert',
        '[role="navigation"]', '[role="button"]', '[role="menu"]', '[role="tablist"]', '[contenteditable]'
    ].join(', ');

    // Content a word may lie on, but only when the page has no free room left.
    var COSTLY = [
        '.card', '.card-body',
        '.activity', '.section', '.topic', '.modtype', '.module', '.weeks', '.resources',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'li', 'dl', 'dt', 'dd', 'blockquote', 'pre',
        'table', '.list-group', '.list-group-item', '.progress',
        'img', 'svg', 'video', 'audio', 'iframe', 'canvas', 'picture'
    ].join(', ');

    // The same glyphs as classes/icons.php, so that the block needs no icons.
    var ICONS = {
        add: 'M7 2h2v5h5v2H9v5H7V9H2V7h5z',
        edit: 'M11.7 1.4 14.6 4.3 6.2 12.7 2 14l1.3-4.2zM2.6 12.5l-.6 1.9 1.9-.6z',
        trash: 'M6.3 1h3.4v1H14v1.5H2V2h4.3zM3.2 4.5h9.6l-.7 9.4a1 1 0 0 1-1 .9H4.9a1 1 0 0 1-1-.9z'
    };

    var relayoutTimer = null;

    /**
     * Call a web service of the plugin and show failures to the user.
     *
     * @param {Object} state State of the block region.
     * @param {String} methodname Name of the web service.
     * @param {Object} args Arguments of the web service.
     * @param {Element} [button] Button to spin while the call runs.
     * @return {Promise} Resolves with the answer, or with null when it failed.
     */
    function call(state, methodname, args, button) {
        if (button) {
            Pending.add(button);
        }
        return Ajax.call([Ajax.METHOD, state.serviceurl, {methodname: methodname, args: args}])
            .then(function(response) {
                if (button) {
                    Pending.remove(button);
                }
                return response;
            }, function(error) {
                if (button) {
                    Pending.remove(button);
                }
                Notification.exception(error);
                return null;
            });
    }

    /**
     * Reload the words of a course and redraw everything.
     *
     * @param {Object} state State of the block region.
     * @return {Promise}
     */
    function refresh(state) {
        return call(state, 'block_quick_access_get_words', {courseid: state.courseid})
            .then(function(response) {
                if (!response) {
                    return;
                }
                state.words = response.words || [];
                renderList(state);
                spread(state.words);
            });
    }

    /**
     * Read the words the server already rendered into the block.
     *
     * @param {Element} region Block region.
     * @return {Object[]}
     */
    function readWords(region) {
        var nodes = region.querySelectorAll('[data-region="qa-cloud-list"] [data-wordid]');
        var words = [];
        var i;

        for (i = 0; i < nodes.length; i++) {
            words.push({
                id: parseInt(nodes[i].getAttribute('data-wordid'), 10),
                word: nodes[i].getAttribute('data-word') || '',
                posx: parseInt(nodes[i].getAttribute('data-posx'), 10),
                posy: parseInt(nodes[i].getAttribute('data-posy'), 10)
            });
        }

        return words;
    }

    /**
     * Rebuild the list of words inside the block.
     *
     * @param {Object} state State of the block region.
     * @return {Promise}
     */
    function renderList(state) {
        var list = state.list;
        var empty = state.empty;
        var promises = [];
        var i;

        if (!list) {
            return Promise.resolve();
        }

        for (i = 0; i < state.words.length; i++) {
            promises.push(buildItem(state, state.words[i]));
        }

        return Promise.all(promises).then(function(items) {
            list.innerHTML = '';
            items.forEach(function(item) {
                list.appendChild(item);
            });
            if (empty) {
                empty.hidden = items.length > 0;
            }
        });
    }

    /**
     * Build one row of the list: the word plus its edit and remove buttons.
     *
     * @param {Object} state State of the block region.
     * @param {Object} word
     * @return {Promise} Resolves with the row element.
     */
    function buildItem(state, word) {
        return Promise.all([
            Str.getString('editword', 'block_quick_access', word.word),
            Str.getString('deleteword', 'block_quick_access', word.word)
        ]).then(function(labels) {
            var item = document.createElement('li');
            item.className = 'block-quick-access-cloud__item d-flex align-items-center';
            item.setAttribute('data-wordid', word.id);
            item.setAttribute('data-word', word.word);
            item.setAttribute('data-posx', word.posx);
            item.setAttribute('data-posy', word.posy);

            var text = document.createElement('span');
            text.className = 'block-quick-access-cloud__text';
            text.textContent = word.word;
            item.appendChild(text);

            var actions = document.createElement('span');
            actions.className = 'ms-auto d-flex align-items-center gap-1';
            actions.appendChild(buildButton('qa-cloud-edit', 'edit', labels[0]));
            actions.appendChild(buildButton('qa-cloud-delete', 'trash', labels[1]));
            item.appendChild(actions);

            return item;
        });
    }

    /**
     * Build one icon button of the list.
     *
     * @param {String} action Value of the data-action attribute.
     * @param {String} icon Name of the glyph.
     * @param {String} label Text for screen readers and for the tooltip.
     * @return {Element}
     */
    function buildButton(action, icon, label) {
        var button = document.createElement('button');

        button.type = 'button';
        button.className = 'btn btn-link btn-sm p-0';
        button.setAttribute('data-action', action);
        button.setAttribute('title', label);
        button.setAttribute('aria-label', label);
        button.innerHTML = glyph(icon);

        return button;
    }

    /**
     * Return a glyph as inline SVG.
     *
     * @param {String} name Name of the glyph.
     * @return {String}
     */
    function glyph(name) {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 16 16"' +
            ' class="icon block-quick-access-cloud__icon" aria-hidden="true" focusable="false">' +
            '<path d="' + ICONS[name] + '" fill="currentColor"/></svg>';
    }

    /**
     * Find a word by its id.
     *
     * @param {Object} state State of the block region.
     * @param {Number} id
     * @return {Object|null}
     */
    function findWord(state, id) {
        var i;
        for (i = 0; i < state.words.length; i++) {
            if (state.words[i].id === id) {
                return state.words[i];
            }
        }
        return null;
    }

    /**
     * Open the inline editor, either to add a word or to change an existing one.
     *
     * @param {Object} state State of the block region.
     * @param {Number|null} wordid Id of the word to change, null to add a new one.
     */
    function openEditor(state, wordid) {
        var word = wordid === null ? null : findWord(state, wordid);

        if (wordid !== null && !word) {
            return;
        }

        state.editing = wordid;
        state.input.value = word ? word.word : '';
        setError(state, '');
        state.form.hidden = false;
        state.add.hidden = true;
        state.input.focus();
    }

    /**
     * Close the inline editor and forget what was being changed.
     *
     * @param {Object} state State of the block region.
     */
    function closeEditor(state) {
        state.editing = null;
        state.input.value = '';
        setError(state, '');
        state.form.hidden = true;
        state.add.hidden = false;
    }

    /**
     * Show or clear the validation message under the word input.
     *
     * @param {Object} state State of the block region.
     * @param {String} message
     */
    function setError(state, message) {
        if (state.error) {
            state.error.textContent = message;
        }
        state.input.classList.toggle('is-invalid', Boolean(message));
    }

    /**
     * Store the word the user typed in.
     *
     * @param {Object} state State of the block region.
     */
    function save(state) {
        var word = state.input.value.replace(/^\s+|\s+$/g, '');
        var editing = state.editing;
        var methodname;
        var args;
        var button;

        if (!word) {
            Str.getString('wordrequired', 'block_quick_access').then(function(message) {
                setError(state, message);
                state.input.focus();
            });
            return;
        }

        if (editing === null) {
            methodname = 'block_quick_access_add_word';
            args = {courseid: state.courseid, word: word};
        } else {
            methodname = 'block_quick_access_update_word';
            args = {wordid: editing, word: word};
        }

        button = state.region.querySelector('[data-action="qa-cloud-save"]');
        call(state, methodname, args, button).then(function(response) {
            if (!response) {
                return;
            }
            closeEditor(state);
            refresh(state);
        });
    }

    /**
     * Ask for confirmation and remove a word.
     *
     * @param {Object} state State of the block region.
     * @param {Number} wordid
     */
    function remove(state, wordid) {
        var word = findWord(state, wordid);
        var item;
        var button;

        if (!word) {
            return;
        }

        Str.getString('confirmdelete', 'block_quick_access', word.word).then(function(message) {
            if (!window.confirm(message)) {
                return;
            }
            item = state.region.querySelector('[data-wordid="' + wordid + '"]');
            button = item ? item.querySelector('[data-action="qa-cloud-delete"]') : null;

            call(state, 'block_quick_access_delete_word', {wordid: wordid}, button).then(function(response) {
                if (!response) {
                    return;
                }
                if (state.editing === wordid) {
                    closeEditor(state);
                }
                refresh(state);
            });
        });
    }

    /**
     * Act on a click somewhere inside the block region.
     *
     * @param {Object} state State of the block region.
     * @param {Event} event
     */
    function onClick(state, event) {
        var trigger = event.target.closest('[data-action]');
        var wordid;

        if (!trigger || !state.region.contains(trigger)) {
            return;
        }

        switch (trigger.getAttribute('data-action')) {
            case 'qa-cloud-add':
                openEditor(state, null);
                break;
            case 'qa-cloud-edit':
                wordid = parseInt(trigger.closest('[data-wordid]').getAttribute('data-wordid'), 10);
                openEditor(state, wordid);
                break;
            case 'qa-cloud-delete':
                wordid = parseInt(trigger.closest('[data-wordid]').getAttribute('data-wordid'), 10);
                remove(state, wordid);
                break;
            case 'qa-cloud-save':
                save(state);
                break;
            case 'qa-cloud-cancel':
                closeEditor(state);
                break;
        }
    }

    /**
     * Enter stores the word, Escape closes the editor.
     *
     * @param {Object} state State of the block region.
     * @param {Event} event
     */
    function onKeydown(state, event) {
        if (event.key !== 'Enter' && event.key !== 'Escape') {
            return;
        }
        if (event.key === 'Enter' && event.target === state.input) {
            event.preventDefault();
            save(state);
        } else if (event.key === 'Escape') {
            closeEditor(state);
        }
    }

    /**
     * Start the cloud for one block region.
     *
     * @param {Element} region Block region.
     */
    function initRegion(region) {
        if (region.qaCloud) {
            return;
        }

        var state = {
            region: region,
            courseid: parseInt(region.getAttribute('data-courseid'), 10),
            canmanage: region.getAttribute('data-canmanage') === 'true',
            serviceurl: region.getAttribute('data-serviceurl'),
            form: region.querySelector('[data-region="qa-cloud-form"]'),
            input: region.querySelector('[data-region="qa-cloud-input"]'),
            error: region.querySelector('[data-region="qa-cloud-error"]'),
            list: region.querySelector('[data-region="qa-cloud-list"]'),
            empty: region.querySelector('[data-region="qa-cloud-empty"]'),
            add: region.querySelector('[data-action="qa-cloud-add"]'),
            editing: null,
            words: []
        };
        region.qaCloud = state;

        if (state.canmanage) {
            // Show the words the server already rendered, then keep them in sync.
            state.words = readWords(region);
            spread(state.words);
            region.addEventListener('click', function(event) {
                onClick(state, event);
            });
            region.addEventListener('keydown', function(event) {
                onKeydown(state, event);
            });
        }

        refresh(state);
    }

    /**
     * Start the cloud of every block region on the page.
     */
    function init() {
        var regions = document.querySelectorAll(REGION);
        var i;

        for (i = 0; i < regions.length; i++) {
            initRegion(regions[i]);
        }
    }

    //
    // Placing the words.
    //

    /**
     * Return the element the words are drawn into, creating it if needed.
     *
     * @return {Element}
     */
    function getOverlay() {
        var overlay = document.getElementById(OVERLAY_ID);
        var host;

        if (overlay) {
            return overlay;
        }

        overlay = document.createElement('div');
        overlay.id = OVERLAY_ID;
        overlay.className = 'block-quick-access-cloud-overlay';
        overlay.setAttribute('aria-hidden', 'true');

        host = document.getElementById('page') || document.body;
        host.classList.add(HOST_ID);
        if (host === document.body) {
            // There is no #page on this page, follow the window instead.
            overlay.classList.add('block-quick-access-cloud-overlay--window');
        }
        host.appendChild(overlay);

        return overlay;
    }

    /**
     * Draw the words on the background of the page.
     *
     * @param {Object[]} words
     */
    function spread(words) {
        var overlay = getOverlay();
        var base = overlay.getBoundingClientRect();
        var nodes = [];
        var grid;
        var i;
        var spot;

        overlay.innerHTML = '';
        overlay.qaCloudWords = words;

        if (!words.length || !base.width || !base.height) {
            return;
        }

        for (i = 0; i < words.length; i++) {
            nodes.push(buildWord(words[i]));
            overlay.appendChild(nodes[i]);
        }

        grid = buildGrid(base);

        for (i = 0; i < words.length; i++) {
            spot = findSpot(grid, words[i], nodes[i], base);
            placeWord(nodes[i], spot, words[i], base);
            occupy(grid, spot, nodes[i]);
        }
    }

    /**
     * Create the element of one word.
     *
     * @param {Object} word
     * @return {Element}
     */
    function buildWord(word) {
        var seed = word.id || 1;
        var size = MIN_FONT + (MAX_FONT - MIN_FONT) * pseudo(seed * 37);
        var opacity = MIN_OPACITY + (MAX_OPACITY - MIN_OPACITY) * pseudo(seed * 61);
        var tilt = (pseudo(seed * 89) - 0.5) * 2 * MAX_TILT;
        var node = document.createElement('span');

        node.className = 'block-quick-access-cloud__word';
        node.textContent = word.word;
        node.style.fontSize = size.toFixed(1) + 'px';
        node.style.opacity = opacity.toFixed(2);
        node.style.transform = 'translate(-50%, -50%) rotate(' + tilt.toFixed(2) + 'deg)';

        return node;
    }

    /**
     * A repeatable number between 0 and 1.
     *
     * @param {Number} seed
     * @return {Number}
     */
    function pseudo(seed) {
        return (Math.imul(Math.abs(Math.round(seed)) | 0, 2654435761) >>> 0) % 1000 / 1000;
    }

    /**
     * Mark every cell of the page that is taken or costly.
     *
     * @param {DOMRect} base Position and size of the overlay.
     * @return {Object} The placement grid.
     */
    function buildGrid(base) {
        var cols = Math.max(1, Math.ceil(base.width / GRID));
        var rows = Math.max(1, Math.ceil(base.height / GRID));
        var grid = {
            cols: cols,
            rows: rows,
            blocked: new Uint8Array(cols * rows),
            costly: new Uint8Array(cols * rows),
            taken: new Uint8Array(cols * rows)
        };

        mark(collect(BLOCKING, base), grid, base, 'blocked');
        mark(collect(COSTLY, base), grid, base, 'costly');

        return grid;
    }

    /**
     * Measure every element matched by a selector, relative to the overlay.
     *
     * @param {String} selector
     * @param {DOMRect} base Position and size of the overlay.
     * @return {Object[]}
     */
    function collect(selector, base) {
        var nodes = document.querySelectorAll(selector);
        var rects = [];
        var style;
        var rect;
        var i;

        for (i = 0; i < nodes.length; i++) {
            if (nodes[i].closest('#' + OVERLAY_ID)) {
                continue;
            }
            style = window.getComputedStyle(nodes[i]);
            if (style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0') {
                continue;
            }
            rect = nodes[i].getBoundingClientRect();
            if (rect.width < 4 || rect.height < 4) {
                continue;
            }
            rects.push({
                left: rect.left - base.left,
                top: rect.top - base.top,
                right: rect.right - base.left,
                bottom: rect.bottom - base.top
            });
        }

        return rects;
    }

    /**
     * Mark every cell an obstacle touches.
     *
     * @param {Object[]} rects
     * @param {Object} grid
     * @param {DOMRect} base
     * @param {String} name Name of the grid array to mark, 'blocked' or 'costly'.
     */
    function mark(rects, grid, base, name) {
        var i;

        for (i = 0; i < rects.length; i++) {
            fill(grid, rects[i], base, name);
        }
    }

    /**
     * Set a value in every cell a rectangle touches.
     *
     * @param {Object} grid
     * @param {Object} rect
     * @param {DOMRect} base
     * @param {String} name Name of the grid array to mark.
     */
    function fill(grid, rect, base, name) {
        var col0 = Math.max(0, Math.floor((rect.left - base.left - PADDING) / GRID));
        var col1 = Math.min(grid.cols - 1, Math.floor((rect.right - base.left + PADDING) / GRID));
        var row0 = Math.max(0, Math.floor((rect.top - base.top - PADDING) / GRID));
        var row1 = Math.min(grid.rows - 1, Math.floor((rect.bottom - base.top + PADDING) / GRID));
        var row;
        var col;

        for (row = row0; row <= row1; row++) {
            for (col = col0; col <= col1; col++) {
                grid[name][row * grid.cols + col] = 1;
            }
        }
    }

    /**
     * Search a free spot for a word.
     *
     * The neighbourhood of the saved target is searched first, and only if
     * nothing fits there the whole page is scanned.
     *
     * @param {Object} grid
     * @param {Object} word
     * @param {Element} node
     * @param {DOMRect} base
     * @return {Object|null} The chosen cell, or null when the page is full.
     */
    function findSpot(grid, word, node, base) {
        var size = node.getBoundingClientRect();
        var targetx = base.width * word.posx / 100;
        var targety = base.height * word.posy / 100;
        var col0 = Math.max(0, Math.floor((targetx - size.width / 2) / GRID));
        var col1 = Math.min(grid.cols - 1, Math.floor((targetx + size.width / 2) / GRID));
        var row0 = Math.max(0, Math.floor((targety - size.height / 2) / GRID));
        var row1 = Math.min(grid.rows - 1, Math.floor((targety + size.height / 2) / GRID));
        var areas = [
            {
                col0: Math.max(0, col0 - NEARBY),
                col1: Math.min(grid.cols - 1, col1 + NEARBY),
                row0: Math.max(0, row0 - NEARBY),
                row1: Math.min(grid.rows - 1, row1 + NEARBY)
            },
            {col0: 0, col1: grid.cols - 1, row0: 0, row1: grid.rows - 1}
        ];
        var best = null;
        var bestscore = Infinity;
        var area;
        var row;
        var col;
        var spot;
        var pass;

        for (pass = 0; pass < areas.length && best === null; pass++) {
            area = areas[pass];
            for (row = area.row0; row <= area.row1; row++) {
                for (col = area.col0; col <= area.col1; col++) {
                    spot = score(grid, col, row, size.width, size.height, targetx, targety);
                    if (spot && spot.score < bestscore) {
                        bestscore = spot.score;
                        best = spot;
                    }
                }
            }
        }

        return best;
    }

    /**
     * Judge one cell: null when it is taken, a score otherwise.
     *
     * @param {Object} grid
     * @param {Number} col
     * @param {Number} row
     * @param {Number} width Size of the word, px.
     * @param {Number} height Size of the word, px.
     * @param {Number} targetx Where the word would like to be, px.
     * @param {Number} targety
     * @return {Object|null}
     */
    function score(grid, col, row, width, height, targetx, targety) {
        var x = col * GRID + GRID / 2;
        var y = row * GRID + GRID / 2;
        var col0 = Math.max(0, Math.floor((x - width / 2) / GRID));
        var col1 = Math.min(grid.cols - 1, Math.floor((x + width / 2) / GRID));
        var row0 = Math.max(0, Math.floor((y - height / 2) / GRID));
        var row1 = Math.min(grid.rows - 1, Math.floor((y + height / 2) / GRID));
        var cost = 0;
        var cell;
        var i;
        var j;

        for (i = row0; i <= row1; i++) {
            for (j = col0; j <= col1; j++) {
                cell = i * grid.cols + j;
                // Already taken by a control or by another word.
                if (grid.blocked[cell] || grid.taken[cell]) {
                    return null;
                }
                if (grid.costly[cell]) {
                    cost++;
                }
            }
        }

        return {
            col: col,
            row: row,
            x: x,
            y: y,
            score: Math.sqrt((x - targetx) * (x - targetx) + (y - targety) * (y - targety)) + cost * COST_PENALTY
        };
    }

    /**
     * Put a word in its cell.
     *
     * @param {Element} node
     * @param {Object|null} spot
     * @param {Object} word
     * @param {DOMRect} base
     */
    function placeWord(node, spot, word, base) {
        var x;
        var y;

        if (spot) {
            x = spot.x;
            y = spot.y;
        } else {
            // Nowhere is free, keep the saved spot and stay as transparent as possible.
            x = base.width * word.posx / 100;
            y = base.height * word.posy / 100;
            node.style.opacity = MIN_OPACITY;
        }

        node.style.left = x.toFixed(1) + 'px';
        node.style.top = y.toFixed(1) + 'px';
    }

    /**
     * Remember that a word now sits in a cell.
     *
     * @param {Object} grid
     * @param {Object|null} spot
     * @param {Element} node
     */
    function occupy(grid, spot, node) {
        var size;
        var x;
        var y;
        var i;
        var j;

        if (!spot) {
            return;
        }
        size = node.getBoundingClientRect();
        x = spot.x;
        y = spot.y;

        for (i = Math.max(0, Math.floor((y - size.height / 2) / GRID));
            i <= Math.min(grid.rows - 1, Math.floor((y + size.height / 2) / GRID)); i++) {
            for (j = Math.max(0, Math.floor((x - size.width / 2) / GRID));
                j <= Math.min(grid.cols - 1, Math.floor((x + size.width / 2) / GRID)); j++) {
                grid.taken[i * grid.cols + j] = 1;
            }
        }
    }

    /**
     * Lay the cloud out again once the page has stopped changing.
     */
    function scheduleRerun() {
        var overlay = document.getElementById(OVERLAY_ID);

        if (!overlay || !overlay.qaCloudWords) {
            return;
        }
        if (relayoutTimer) {
            clearTimeout(relayoutTimer);
        }
        relayoutTimer = setTimeout(function() {
            relayoutTimer = null;
            spread(overlay.qaCloudWords);
        }, RELAYOUT_DELAY);
    }

    /**
     * Did something other than the cloud itself appear on the page?
     *
     * The cloud writes its own words into the page, and reacting to that would
     * mean laying the cloud out again, forever.
     *
     * @param {NodeList} nodes
     * @return {Boolean}
     */
    function isRealChange(nodes) {
        var overlay = document.getElementById(OVERLAY_ID);
        var i;

        for (i = 0; i < nodes.length; i++) {
            if (nodes[i] !== overlay && nodes[i].parentNode !== overlay) {
                return true;
            }
        }

        return false;
    }

    /**
     * Notice the situations that change where free space is: a new window
     * size, an opened drawer, a block that arrives later.
     */
    function watch() {
        var host = document.getElementById('page');
        var observer;

        window.addEventListener('resize', scheduleRerun);

        if (window.ResizeObserver && host) {
            observer = new window.ResizeObserver(scheduleRerun);
            observer.observe(host);
        }

        if (window.MutationObserver && host) {
            // Course sections and blocks may be swapped in after the page load.
            observer = new window.MutationObserver(function(mutations) {
                var i;

                for (i = 0; i < mutations.length; i++) {
                    if (isRealChange(mutations[i].addedNodes)) {
                        init();
                        scheduleRerun();
                        return;
                    }
                }
            });
            observer.observe(host, {childList: true, subtree: true});
        }
    }

    // The block markup is already in the page by the time this module runs,
    // but the module may also be loaded from the document head.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            init();
            watch();
        });
    } else {
        init();
        watch();
    }

    return {
        init: init
    };
});
