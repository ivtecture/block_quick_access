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
 * Upgrade steps of the "Quick access" block.
 *
 * @package    block_quick_access
 * @copyright  2026 Your Name
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the plugin database.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_block_quick_access_upgrade($oldversion) {
    global $DB;

    if ($oldversion < 2026092900) {
        // The thought cloud was introduced, together with its storage.
        if (!$DB->get_manager()->table_exists('block_quick_access_cloud')) {
            $table = new xmldb_table('block_quick_access_cloud');

            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('word', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('posx', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '50');
            $table->add_field('posy', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '50');
            $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

            $table->add_index('courseidx', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'sortorder']);

            $DB->get_manager()->create_table($table);
        }
    }

    return true;
}
