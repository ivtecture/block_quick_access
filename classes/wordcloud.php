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
 * Storage of the thought cloud ("Облако мыслей").
 *
 * @package    block_quick_access
 * @copyright  2026 Your Name
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace block_quick_access;

defined('MOODLE_INTERNAL') || die();

/**
 * Class wordcloud.
 *
 * Reads and writes the words of a course. When a word is added it is given a
 * target spot on the page: words are laid out along a golden angle spiral, so
 * that every new word lands in a free quarter of the background and words do
 * not pile up on each other. The browser refines these targets at render time
 * to keep navigation and controls readable.
 */
class wordcloud {

    /** @var string Name of the table that holds the words. */
    const TABLE = 'block_quick_access_cloud';

    /** @var int Maximum amount of words per course. */
    const MAXWORDS = 100;

    /** @var int Maximum length of a single word, in characters. */
    const MAXLENGTH = 100;

    /** @var float Golden angle in radians, keeps the spiral evenly spread. */
    const GOLDENANGLE = 2.39996322972865332;

    /**
     * Return all words of a course, ordered the way they were added.
     *
     * @param int $courseid
     * @return array[] List of ['id', 'word', 'posx', 'posy'] entries.
     */
    public static function get_words(int $courseid): array {
        global $DB;

        $records = $DB->get_records(self::TABLE, ['courseid' => $courseid], 'sortorder ASC, id ASC', 'id, word, posx, posy');

        $words = [];
        foreach ($records as $record) {
            $words[] = self::export_word($record);
        }

        return $words;
    }

    /**
     * Count the words of a course.
     *
     * @param int $courseid
     * @return int
     */
    public static function count_words(int $courseid): int {
        global $DB;

        return (int) $DB->count_records(self::TABLE, ['courseid' => $courseid]);
    }

    /**
     * Add a word to the cloud of a course and spread it over the background.
     *
     * @param int $courseid
     * @param string $word
     * @param int $userid Author of the word.
     * @return array The stored word as ['id', 'word', 'posx', 'posy'].
     */
    public static function add_word(int $courseid, string $word, int $userid): array {
        global $DB;

        $word = self::prepare_word($word);

        $sortorder = self::count_words($courseid);
        if ($sortorder >= self::MAXWORDS) {
            throw new \moodle_exception('toomanywords', 'block_quick_access', '', self::MAXWORDS);
        }

        $position = self::spread($sortorder, $courseid, $word);

        $now = time();
        $record = (object) [
            'courseid' => $courseid,
            'word' => $word,
            'posx' => $position['x'],
            'posy' => $position['y'],
            'sortorder' => $sortorder,
            'userid' => $userid,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record(self::TABLE, $record, true);

        return self::export_word($record);
    }

    /**
     * Change the text of a word. The saved spot is kept as it is.
     *
     * @param int $wordid
     * @param string $word
     * @return array The stored word as ['id', 'word', 'posx', 'posy'].
     */
    public static function update_word(int $wordid, string $word): array {
        global $DB;

        $word = self::prepare_word($word);

        $record = $DB->get_record(self::TABLE, ['id' => $wordid], '*', MUST_EXIST);
        $record->word = $word;
        $record->timemodified = time();
        $DB->update_record(self::TABLE, $record);

        return self::export_word($record);
    }

    /**
     * Remove a word from a cloud.
     *
     * @param int $wordid
     */
    public static function delete_word(int $wordid): void {
        global $DB;

        $DB->delete_records(self::TABLE, ['id' => $wordid], MUST_EXIST);
    }

    /**
     * Trim the text of a word and make sure it can be stored.
     *
     * @param string $word
     * @return string
     */
    public static function prepare_word(string $word): string {
        // Drop any markup, collapse whitespace and cut the text to the allowed length.
        $word = clean_param($word, PARAM_TEXT);
        $word = preg_replace('/\s+/u', ' ', $word);
        $word = trim($word);

        if ($word === '') {
            throw new \moodle_exception('wordrequired', 'block_quick_access');
        }
        if (textlib::strlen($word) > self::MAXLENGTH) {
            throw new \moodle_exception('invalidword', 'block_quick_access', '', self::MAXLENGTH);
        }

        return $word;
    }

    /**
     * Pick the spot a new word is saved at.
     *
     * Words walk outwards along a golden angle spiral, which spreads even
     * numbers of words over the whole background. A small per course jitter
     * keeps the pattern from being recognisable.
     *
     * @param int $index Number of words already in the cloud.
     * @param int $courseid
     * @param string $word
     * @return array ['x' => percent, 'y' => percent].
     */
    protected static function spread(int $index, int $courseid, string $word): array {
        $angle = $index * self::GOLDENANGLE;
        $radius = sqrt(($index + 0.5) / self::MAXWORDS);
        $jitter = self::jitter($courseid . ':' . $word);

        $x = 50 + 46 * $radius * cos($angle) + $jitter;
        $y = 50 + 46 * $radius * sin($angle) + $jitter;

        return [
            'x' => (int) round(max(2, min(98, $x))),
            'y' => (int) round(max(2, min(98, $y))),
        ];
    }

    /**
     * Return a small, repeatable offset in the range -4 .. 4.
     *
     * @param string $seed
     * @return float
     */
    protected static function jitter(string $seed): float {
        $hash = hexdec(substr(md5($seed), 0, 4));

        return ($hash / 65535) * 8 - 4;
    }

    /**
     * Convert a database record into the shape returned to the browser.
     *
     * @param \stdClass $record
     * @return array
     */
    protected static function export_word(\stdClass $record): array {
        return [
            'id' => (int) $record->id,
            'word' => (string) $record->word,
            'posx' => (int) $record->posx,
            'posy' => (int) $record->posy,
        ];
    }
}
