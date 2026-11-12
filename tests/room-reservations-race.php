<?php
/**
 * Run two independent WordPress writers against one disposable seat.
 * wp --skip-plugins --skip-themes eval-file --use-include tests/room-reservations-race.php
 */
use RRZE\RSVP\{RoomReservations, RoomSlots};

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run through WP-CLI.');
}
require_once dirname(__DIR__) . '/rrze-rsvp.php';
foreach (['room', 'seat', 'booking'] as $type) {
    if (!post_type_exists($type)) {
        register_post_type($type);
    }
}
$day = current_datetime()->modify('+1 day')->format('Y-m-d');
$title = 'RSVP concurrent reservation fixture';
if ($seat = (int) getenv('RSVP_RACE_TEST_SEAT')) {
    $room = (int) get_post_meta($seat, 'rrze-rsvp-seat-room', true);
    if (get_the_title($room) !== $title || get_the_title($seat) !== $title) {
        throw new RuntimeException('Expected a disposable fixture.');
    }
    // Both workers must reach the gate before either can write.
    echo "READY\n";
    flush();
    fgets(STDIN);
    $time = getenv('RSVP_RACE_TEST_START');
    $result = RoomReservations::create($room, $seat, $day, $time, 60, false, [
        'rrze-rsvp-booking-guest-email' => 'test-' . $time,
        'rrze-rsvp-booking-status' => 'confirmed',
    ]);
    echo 'RESULT ' . (is_wp_error($result) ? $result->get_error_code() : 'saved') . "\n";
    return;
}

$room = wp_insert_post(['post_type' => 'room', 'post_status' => 'publish', 'post_title' => $title, 'meta_input' => [
    RoomSlots::ENABLED => 'on', RoomSlots::MIN => 20, RoomSlots::MAX => 80, RoomSlots::STEP => 20,
    'rrze-rsvp-room-timeslots' => [['rrze-rsvp-room-weekday' => [gmdate('N', strtotime($day))],
        'rrze-rsvp-room-starttime' => '08:00', 'rrze-rsvp-room-endtime' => '16:00']],
]]);
$seat = wp_insert_post(['post_type' => 'seat', 'post_status' => 'publish', 'post_title' => $title,
    'meta_input' => ['rrze-rsvp-seat-room' => $room]]);
$workers = [];
try {
    foreach (['09:00', '09:20'] as $start) {
        $process = proc_open(['wp', '--path=' . ABSPATH, '--url=' . home_url(), '--skip-plugins', '--skip-themes',
            'eval-file', '--use-include', __FILE__], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR],
            $pipes, null, array_merge(getenv(), ['RSVP_RACE_TEST_SEAT' => (string) $seat, 'RSVP_RACE_TEST_START' => $start]));
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start a booking worker.');
        }
        $workers[] = [$process, $pipes];
    }
    foreach ($workers as [$process, $pipes]) {
        do {
            $line = fgets($pipes[1]);
        } while ($line !== false && trim($line) !== 'READY');
        if ($line === false) {
            throw new RuntimeException('Booking worker failed before writing.');
        }
    }
    foreach ($workers as [$process, $pipes]) {
        fwrite($pipes[0], "go\n");
        fflush($pipes[0]);
    }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        preg_match('/^RESULT (\w+)$/m', $output, $match);
        $results[] = $match[1] ?? 'failed';
    }
    sort($results);
    $saved = get_posts(['fields' => 'ids', 'post_type' => 'booking', 'numberposts' => -1,
        'cache_results' => false, 'meta_key' => 'rrze-rsvp-booking-seat', 'meta_value' => $seat]);
    if ($results !== ['saved', 'seat_unavailable'] || count($saved) !== 1) {
        throw new RuntimeException('Overlapping writes must produce exactly one reservation: ' . implode(', ', $results));
    }
    WP_CLI::success('Two concurrent, overlapping reservations produced exactly one booking.');
} finally {
    foreach ($workers as [$process, $pipes]) {
        fclose($pipes[0]);
        fclose($pipes[1]);
        proc_close($process);
    }
    foreach (get_posts(['fields' => 'ids', 'post_type' => 'booking', 'numberposts' => -1,
        'cache_results' => false, 'meta_key' => 'rrze-rsvp-booking-seat', 'meta_value' => $seat]) as $id) {
        wp_delete_post($id, true);
    }
    wp_delete_post($seat, true);
    wp_delete_post($room, true);
}
