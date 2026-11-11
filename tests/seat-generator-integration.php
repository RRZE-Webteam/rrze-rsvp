<?php
/**
 * Exercise reviewed seat publication in WordPress, using disposable fixtures.
 * Run: wp --skip-plugins --skip-themes eval-file tests/seat-generator-integration.php
 */
use RRZE\RSVP\SeatGenerator;

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this test through WP-CLI.');
}
require_once dirname(__DIR__) . '/includes/SeatGenerator.php';
foreach (['room', 'seat'] as $type) {
    if (!post_type_exists($type)) {
        register_post_type($type);
    }
}
if (!taxonomy_exists('rrze-rsvp-equipment')) {
    register_taxonomy('rrze-rsvp-equipment', 'seat');
}

/**
 * Report a readable failure and let finally clean up the disposable room.
 *
 * @param bool $condition Expected test result.
 * @param string $message Behavior under test.
 * @return void
 */
function assertSeatBatch(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$room = wp_insert_post(['post_type' => 'room', 'post_status' => 'publish', 'post_title' => 'RSVP batch QA'], true);
assertSeatBatch(!is_wp_error($room), 'Room fixture could not be created.');
$term = null;
try {
    $term = wp_insert_term(wp_unique_id('QA ') . wp_generate_uuid4(), 'rrze-rsvp-equipment');
    assertSeatBatch(!is_wp_error($term), 'Equipment fixture could not be created.');
    $termId = (int) $term['term_id'];
    $s = SeatGenerator::settings(['room_id' => $room, 'prefix' => 'Testgerät', 'number_seats' => '3', 'equipment' => [$termId]]);
    $rooms = [$room => 'QA'];
    $terms = [$termId => 'QA'];
    assertSeatBatch(!SeatGenerator::errors($s, $rooms, $terms), 'Fixture settings should be valid.');
    foreach ([['number_rows' => '0'], ['number_seats' => '-1'], ['number_seats' => '2.5'],
        ['number_rows' => '25', 'number_seats' => '25'], ['start_number' => '0', 'number_rows' => '2'],
        ['equipment' => [-2]], ['room_id' => '0']] as $invalid) {
        assertSeatBatch((bool) SeatGenerator::errors($invalid + $s, $rooms, $terms), 'Invalid input must be rejected.');
    }
    $letters = SeatGenerator::plan(['number_rows' => '2', 'number_seats' => '27', 'schema_seats' => 'a-z', 'start_number' => '101'] + $s);
    assertSeatBatch($letters['new'][26] === 'Testgerät 101AA' && $letters['new'][27] === 'Testgerät 102A', 'Mixed numbering must carry past Z and restart for each row.');
    $plan = SeatGenerator::plan($s);
    assertSeatBatch($plan['new'] === ['Testgerät 1', 'Testgerät 2', 'Testgerät 3'], 'A single row must omit the row label.');
    $oldReview = wp_create_nonce(SeatGenerator::reviewAction($s, $plan));
    $existing = wp_insert_post(['post_type' => 'seat', 'post_status' => 'draft', 'post_title' => ' Testgerät 1',
        'meta_input' => ['rrze-rsvp-seat-room' => $room]]);
    assertSeatBatch(is_wp_error(SeatGenerator::create($s, $oldReview)), 'Changes since preview must require fresh review.');
    $plan = SeatGenerator::plan($s);
    assertSeatBatch($plan['new'] === ['Testgerät 2', 'Testgerät 3'] && $plan['existing'] === ['Testgerät 1'], 'Drafts in this room must be protected.');
    $review = wp_create_nonce(SeatGenerator::reviewAction($s, $plan));
    $result = SeatGenerator::create($s, $review);
    assertSeatBatch($result === ['created' => 2, 'skipped' => 1, 'failed' => 0, 'equipment_failed' => 0], 'Only missing seats should be created.');
    $ids = get_posts(['fields' => 'ids', 'post_type' => 'seat', 'meta_key' => 'rrze-rsvp-seat-room', 'meta_value' => $room]);
    assertSeatBatch(count($ids) === 2 && !has_term($termId, 'rrze-rsvp-equipment', $existing) && get_post_status($existing) === 'draft', 'Existing seats must remain unchanged.');
    foreach ($ids as $id) {
        assertSeatBatch(has_term($termId, 'rrze-rsvp-equipment', $id), 'New seats must receive shared equipment.');
    }
    assertSeatBatch(is_wp_error(SeatGenerator::create($s, $review)) && !SeatGenerator::plan($s)['new'], 'Repeating a confirmation must not duplicate seats.');
    echo "Seat generator integration passed.\n";
} finally {
    foreach (get_posts(['fields' => 'ids', 'posts_per_page' => -1, 'post_type' => 'seat', 'post_status' => 'any',
        'meta_key' => 'rrze-rsvp-seat-room', 'meta_value' => $room]) as $id) {
        wp_delete_post($id, true);
    }
    if (is_array($term)) {
        wp_delete_term($term['term_id'], 'rrze-rsvp-equipment');
    }
    wp_delete_post($room, true);
}
