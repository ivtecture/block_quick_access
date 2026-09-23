<?php
/**
 * Script to create a sample Quick links block instance in a course via CLI.
 * 
 * Usage:
 *   docker compose exec -u www-data web php /var/www/html/blocks/quicklinks/create_sample_block.php
 * 
 * This script:
 * 1. Finds the first available course (typically "test" course)
 * 2. Creates a new block_quicklinks instance on the course main page
 * 3. Configures it with sample quick links
 * 4. Returns the block instance ID and configuration
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../config.php');

// Get the first course (usually ID=2 after site course)
$courses = $DB->get_records('course', ['visible' => 1], 'id ASC', '*', 0, 2);
if (count($courses) < 2) {
    cli_error("Error: No visible courses found (need at least the site course + one test course).");
}

$course = array_pop($courses); // Get the second course (first after site)
$courseid = $course->id;

echo "Creating block on course: {$course->shortname} (ID: $courseid)\n";

// Get the course context
$context = context_course::instance($courseid);

// Get or create a block instance for this course
$blocktype = 'quicklinks';

// Check if block already exists
$existing = $DB->get_record('block_instances', [
    'blockname' => $blocktype,
    'parentcontextid' => $context->id,
    'pagetypepattern' => 'course-view-*',
    'subpagepattern' => null
]);

if ($existing) {
    $blockinstance = $existing;
    echo "Using existing block instance ID: {$blockinstance->id}\n";
} else {
    // Create new instance
    $blockinstance = new stdClass();
    $blockinstance->blockname = $blocktype;
    $blockinstance->parentcontextid = $context->id;
    $blockinstance->showinsubcontexts = 0;
    $blockinstance->pagetypepattern = 'course-view-*';
    $blockinstance->subpagepattern = null;
    $blockinstance->defaultregion = 'side-pre';
    $blockinstance->defaultweight = 0;
    $blockinstance->configdata = '';
    $blockinstance->timecreated = time();
    $blockinstance->timemodified = time();

    $blockinstance->id = $DB->insert_record('block_instances', $blockinstance);
    echo "Created new block instance ID: {$blockinstance->id}\n";
}

// Configure the block with sample links
$config = new stdClass();

// Sample links (title => url pairs)
$samplelinks = [
    'Moodle Docs' => 'https://docs.moodle.org',
    'Course Resources' => 'https://moodle.org/course/view.php?id=' . $courseid,
    'Moodle Community' => 'https://moodle.net',
];

$config->linktitle = [];
$config->linkurl = [];

foreach ($samplelinks as $title => $url) {
    $config->linktitle[] = $title;
    $config->linkurl[] = $url;
}

// Encode and save config
$configdata = base64_encode(serialize($config));
$DB->set_field('block_instances', 'configdata', $configdata, ['id' => $blockinstance->id]);

echo "\nBlock configured with sample links:\n";
foreach ($config->linktitle as $i => $title) {
    echo "  [{$i}] $title => {$config->linkurl[$i]}\n";
}

echo "\n✓ Sample block created successfully!\n";
echo "Visit http://localhost:8080/course/view.php?id={$courseid} to see the block.\n";
echo "Log in as admin/admin123\n";

?>
