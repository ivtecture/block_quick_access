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
 * Shared renderer for the "Task analytics" block and view.php.
 *
 * Single place for status text / badge / action link, so the block list
 * and the full table never diverge (review: "вынести общий renderer").
 * Also fixes the literal {$a} bug: teacher graded items use a dedicated
 * 'gradedteacher' string without a placeholder.
 *
 * @package    block_task_analytics
 * @copyright  2026 Spada1557
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace block_task_analytics;

defined('MOODLE_INTERNAL') || die();

/**
 * Shared rendering helpers.
 */
class renderer {

    /**
     * Build the human-readable status text for an item.
     *
     * - Student graded: get_string('graded', ..., $gradedisplay) with param.
     * - Teacher graded: get_string('gradedteacher', ...) without placeholder
     *   (was: get_string('graded') without param -> literal "{$a}").
     * - Teacher inreview: "In review (N to check)".
     * - Otherwise: plain status string.
     *
     * @param array $item overview item.
     * @return string status text.
     */
    public static function status_text(array $item): string {
        $status = $item['status'] ?? service::STATUS_NOTSUBMITTED;
        $isteacher = !empty($item['isteacher']);
        if ($status === service::STATUS_GRADED) {
            if ($isteacher) {
                return get_string('gradedteacher', 'block_task_analytics');
            }
            return get_string('graded', 'block_task_analytics', $item['gradedisplay'] ?? '');
        }
        if ($status === service::STATUS_INREVIEW && $isteacher) {
            return get_string('inreview', 'block_task_analytics')
                . ' (' . get_string('pending', 'block_task_analytics', (int)($item['pending'] ?? 0)) . ')';
        }
        return get_string($status, 'block_task_analytics');
    }

    /**
     * Render a status badge span.
     *
     * @param array $item overview item.
     * @return string HTML.
     */
    public static function status_badge(array $item): string {
        $status = $item['status'] ?? service::STATUS_NOTSUBMITTED;
        return \html_writer::span(self::status_text($item), 'task-status task-status-' . $status);
    }

    /**
     * Render an action link (or empty string when absent).
     *
     * @param array $item overview item.
     * @param array $attrs extra HTML attributes.
     * @param bool $skipduplicate when true, skip "Open task" rows duplicating the title link.
     * @return string HTML.
     */
    public static function action_link(array $item, array $attrs = [], bool $skipduplicate = false): string {
        if (empty($item['actionurl']) || empty($item['actionlabel'])) {
            return '';
        }
        if ($skipduplicate && ($item['actionlabel'] === 'viewlink')
                && ((string)$item['actionurl'] === (string)$item['url'])) {
            return '';
        }
        return \html_writer::link(
            $item['actionurl'],
            get_string($item['actionlabel'], 'block_task_analytics'),
            $attrs
        );
    }

    /**
     * Render one compact list row (used by the block).
     *
     * @param array $item overview item.
     * @return string HTML fragment.
     */
    public static function render_item_compact(array $item): string {
        $tasklink = \html_writer::link($item['url'], format_string($item['name']), ['class' => 'task-analytics-name']);
        $course = \html_writer::div(
            format_string($item['courseshortname']),
            'task-analytics-course text-muted small'
        );
        $badge = self::status_badge($item);
        $action = self::action_link($item, ['class' => 'task-analytics-action small'], true);
        $meta = $badge . ($action !== '' ? ' ' . $action : '');
        return $course . $tasklink . \html_writer::div($meta, 'task-analytics-meta');
    }
}
