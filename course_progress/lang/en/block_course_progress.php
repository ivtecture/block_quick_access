<?php

/**
 * English strings for the "Course progress" block.
 *
 * @package    block_course_progress
 * @copyright  2026 Your Name
 * @license    https://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

// Note: language files are included via require(), no MOODLE_INTERNAL guard here (Moodle convention).

$string['pluginname'] = 'Course progress';
$string['coursecontextonly'] = 'The course progress block can only be used inside a course.';
$string['completionnotenabled'] = 'Activity completion is not enabled for this course, so progress cannot be shown.';
$string['noactivities'] = 'This course has no activities with completion tracking enabled yet.';
$string['guestsnocontent'] = 'Progress is not available for guests. Please log in.';
$string['progressdetails'] = 'Completed {$a->completed} of {$a->total} activities ({$a->percent}%).';
$string['privacy:metadata'] = 'The Course progress block only displays existing Activity completion data; it stores no data itself.';
