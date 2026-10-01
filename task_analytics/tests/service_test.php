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
 * Unit tests for the task analytics service (isolation, no DB needed).
 *
 * Covers format_grade(), sort_items() and pick_latest_grade() — the pure
 * logic flagged in the review ("testable in isolation" уже заготовлено).
 *
 * @package    block_task_analytics
 * @copyright  2026 Spada1557
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

namespace block_task_analytics;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/blocks/task_analytics/classes/service.php');

/**
 * Tests for {@see \block_task_analytics\service}.
 *
 * @covers \block_task_analytics\service
 */
final class service_test extends \advanced_testcase {

    /**
     * format_grade honours zero max and formats pairs.
     */
    public function test_format_grade(): void {
        $this->resetAfterTest(false);
        // Fallback path (format_float exists in full Moodle, but both branches
        // must contain "85" and handle zero max without division).
        $withmax = service::format_grade(85.0, 100.0);
        $this->assertStringContainsString('85', $withmax);
        $this->assertStringContainsString('100', $withmax);

        $nomax = service::format_grade(85.0, 0.0);
        $this->assertStringContainsString('85', $nomax);
        $this->assertStringNotContainsString('/', $nomax);
    }

    /**
     * sort_items orders inreview -> notsubmitted (by duedate, nodate last) -> graded.
     */
    public function test_sort_items(): void {
        $this->resetAfterTest(false);
        $items = [
            ['status' => service::STATUS_GRADED, 'duedate' => 100, 'name' => 'C'],
            ['status' => service::STATUS_NOTSUBMITTED, 'duedate' => 0, 'name' => 'No date'],
            ['status' => service::STATUS_NOTSUBMITTED, 'duedate' => 200, 'name' => 'Later'],
            ['status' => service::STATUS_NOTSUBMITTED, 'duedate' => 100, 'name' => 'Earlier'],
            ['status' => service::STATUS_INREVIEW, 'duedate' => 999, 'name' => 'Review'],
        ];
        service::sort_items($items);
        $this->assertSame(service::STATUS_INREVIEW, $items[0]['status']);
        $this->assertSame('Earlier', $items[1]['name']);
        $this->assertSame('Later', $items[2]['name']);
        $this->assertSame('No date', $items[3]['name']);
        $this->assertSame(service::STATUS_GRADED, $items[4]['status']);
    }

    /**
     * pick_latest_grade prefers higher attemptnumber, then timemodified, then id.
     */
    public function test_pick_latest_grade(): void {
        $this->resetAfterTest(false);
        $old = (object)['id' => 1, 'assignment' => 10, 'userid' => 5, 'grade' => 70,
            'attemptnumber' => 0, 'timemodified' => 100];
        $new = (object)['id' => 2, 'assignment' => 10, 'userid' => 5, 'grade' => 90,
            'attemptnumber' => 1, 'timemodified' => 50];
        $picked = service::pick_latest_grade([$old, $new]);
        $this->assertSame(2, (int)$picked->id);

        // Same attemptnumber -> newer timemodified wins (regression for
        // dml_multiple_records_exception: must never throw, must pick one).
        $a = (object)['id' => 3, 'assignment' => 10, 'userid' => 5, 'grade' => 60,
            'attemptnumber' => 1, 'timemodified' => 200];
        $b = (object)['id' => 4, 'assignment' => 10, 'userid' => 5, 'grade' => 80,
            'attemptnumber' => 1, 'timemodified' => 100];
        $picked = service::pick_latest_grade([$a, $b]);
        $this->assertSame(3, (int)$picked->id);
    }
}
