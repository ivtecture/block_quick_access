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
 * Web services of the thought cloud ("Облако мыслей").
 *
 * @package    block_quick_access
 * @copyright  2026 Your Name
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace block_quick_access;

defined('MOODLE_INTERNAL') || die();

/**
 * Class external.
 */
class external extends \core_external\external_api {

    /**
     * Parameters of the get_words web service.
     *
     * @return array
     */
    public static function get_words_parameters(): array {
        return [
            'courseid' => new \external_value('int', 'Course the words belong to', VALUE_REQUIRED, VALUE_INT),
        ];
    }

    /**
     * Return the words of the thought cloud of a course.
     *
     * @param int $courseid
     * @return array
     */
    public static function get_words($courseid): array {
        $params = self::validate_parameters(self::get_words_parameters(), ['courseid' => $courseid]);
        $context = self::check_course_access($params['courseid']);

        return [
            'words' => wordcloud::get_words($params['courseid']),
            'canmanage' => has_capability('block_quick_access/managewords', $context),
        ];
    }

    /**
     * Returns of the get_words web service.
     *
     * @return array
     */
    public static function get_words_returns(): array {
        return [
            'words' => new \external_multiple_structure(self::word_structure()),
            'canmanage' => new \external_value('bool', 'Whether the user may change the words', VALUE_BOOL),
        ];
    }

    /**
     * Parameters of the add_word web service.
     *
     * @return array
     */
    public static function add_word_parameters(): array {
        return [
            'courseid' => new \external_value('int', 'Course to add the word to', VALUE_REQUIRED, VALUE_INT),
            'word' => new \external_value('string', 'Word to add', VALUE_REQUIRED, VALUE_TEXT),
        ];
    }

    /**
     * Add a word to the thought cloud of a course.
     *
     * @param int $courseid
     * @param string $word
     * @return array
     */
    public static function add_word($courseid, $word): array {
        global $USER;

        $params = self::validate_parameters(self::add_word_parameters(), ['courseid' => $courseid, 'word' => $word]);
        $context = self::check_course_access($params['courseid']);
        require_capability('block_quick_access/managewords', $context);

        return wordcloud::add_word($params['courseid'], $params['word'], (int) $USER->id);
    }

    /**
     * Returns of the add_word web service.
     *
     * @return array
     */
    public static function add_word_returns(): array {
        return self::word_structure();
    }

    /**
     * Parameters of the update_word web service.
     *
     * @return array
     */
    public static function update_word_parameters(): array {
        return [
            'wordid' => new \external_value('int', 'Word to change', VALUE_REQUIRED, VALUE_INT),
            'word' => new \external_value('string', 'New text of the word', VALUE_REQUIRED, VALUE_TEXT),
        ];
    }

    /**
     * Change the text of a word of the thought cloud.
     *
     * @param int $wordid
     * @param string $word
     * @return array
     */
    public static function update_word($wordid, $word): array {
        $params = self::validate_parameters(self::update_word_parameters(), ['wordid' => $wordid, 'word' => $word]);
        $context = self::check_course_access(self::get_word_courseid($params['wordid']));
        require_capability('block_quick_access/managewords', $context);

        return wordcloud::update_word($params['wordid'], $params['word']);
    }

    /**
     * Returns of the update_word web service.
     *
     * @return array
     */
    public static function update_word_returns(): array {
        return self::word_structure();
    }

    /**
     * Parameters of the delete_word web service.
     *
     * @return array
     */
    public static function delete_word_parameters(): array {
        return [
            'wordid' => new \external_value('int', 'Word to remove', VALUE_REQUIRED, VALUE_INT),
        ];
    }

    /**
     * Remove a word from the thought cloud.
     *
     * @param int $wordid
     * @return array
     */
    public static function delete_word($wordid): array {
        $params = self::validate_parameters(self::delete_word_parameters(), ['wordid' => $wordid]);
        $context = self::check_course_access(self::get_word_courseid($params['wordid']));
        require_capability('block_quick_access/managewords', $context);

        wordcloud::delete_word($params['wordid']);

        return ['deleted' => true];
    }

    /**
     * Returns of the delete_word web service.
     *
     * @return array
     */
    public static function delete_word_returns(): array {
        return [
            'deleted' => new \external_value('bool', 'Whether the word was removed', VALUE_BOOL),
        ];
    }

    /**
     * Description of a single word of the cloud.
     *
     * @return \external_single_structure
     */
    protected static function word_structure(): \external_single_structure {
        return new \external_single_structure([
            'id' => new \external_value('int', 'Id of the word', VALUE_INT),
            'word' => new \external_value('string', 'Text of the word', VALUE_TEXT),
            'posx' => new \external_value('int', 'Saved horizontal spot, percent of the page width', VALUE_INT),
            'posy' => new \external_value('int', 'Saved vertical spot, percent of the page height', VALUE_INT),
        ]);
    }

    /**
     * Make sure the current user may see the course the cloud belongs to.
     *
     * @param int $courseid
     * @return \context_course
     */
    protected static function check_course_access(int $courseid): \context_course {
        $course = get_course($courseid);
        $context = \context_course::instance($course->id);

        if (!has_capability('moodle/course:view', $context)) {
            throw new \moodle_exception('nopermissions', 'block_quick_access');
        }

        return $context;
    }

    /**
     * Return the course a stored word belongs to.
     *
     * The lookup is done before the capability check so that a word is only ever
     * touched inside the context of the course it belongs to.
     *
     * @param int $wordid
     * @return int
     */
    protected static function get_word_courseid(int $wordid): int {
        global $DB;

        $courseid = $DB->get_field(wordcloud::TABLE, 'courseid', ['id' => $wordid], MUST_EXIST);

        return (int) $courseid;
    }
}
