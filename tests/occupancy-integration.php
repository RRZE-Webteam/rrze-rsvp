<?php
/**
 * Exercise the admin occupancy reader with real WP post/meta queries.
 * Run with wp --skip-plugins --skip-themes eval-file tests/occupancy-integration.php.
 */
use RRZE\RSVP\Functions;

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this test through WP-CLI.');
}
require_once dirname(__DIR__) . '/includes/Functions.php';
foreach (['room', 'seat', 'booking'] as $type) {
    if (!post_type_exists($type)) {
        register_post_type($type);
    }
}

/**
 * Create a published fixture. Track its ID so failures also clean up.
 *
 * @param string $type Room, seat or booking.
 * @param array $meta Initial booking or schedule metadata.
 * @param array $ids Created post IDs, in creation order.
 * @return int New post ID.
 */
function occupancyFixture(string $type, array $meta, array &$ids): int
{
    $id = wp_insert_post(['post_type' => $type, 'post_status' => 'publish',
        'post_title' => 'RSVP occupancy test', 'meta_input' => $meta], true);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    $ids[] = $id;
    return $id;
}

$ids = [];
try {
    $day = current_time('Y-m-d');
    $rules = [];
    for ($hour = 6; $hour < 13; ++$hour) {
        $rules[] = ['rrze-rsvp-room-weekday' => [current_time('N')],
            'rrze-rsvp-room-starttime' => sprintf('%02d:00', $hour),
            'rrze-rsvp-room-endtime' => sprintf('%02d:00', $hour + 1)];
    }
    $room = occupancyFixture('room', ['rrze-rsvp-room-timeslots' => $rules], $ids);
    $seat = occupancyFixture('seat', ['rrze-rsvp-seat-room' => $room], $ids);
    $otherSeat = occupancyFixture('seat', ['rrze-rsvp-seat-room' => $room], $ids);
    $statuses = ['booked', 'customer-confirmed', 'checked-in', 'confirmed', 'confirmed', 'confirmed', 'confirmed'];
    $bookingIds = [];
    foreach ($rules as $index => $rule) {
        $bookingIds[] = occupancyFixture('booking', [
            'rrze-rsvp-booking-seat' => $seat,
            'rrze-rsvp-booking-start' => strtotime($day . ' ' . $rule['rrze-rsvp-room-starttime'] . ' UTC'),
            'rrze-rsvp-booking-end' => strtotime($day . ' ' . $rule['rrze-rsvp-room-endtime'] . ' UTC'),
            'rrze-rsvp-booking-status' => $statuses[$index],
        ], $ids);
    }
    $matrix = Functions::getOccupancyByRoomIdAdmin($room);
    $expected = ['pending', 'pending', 'checked-in', 'confirmed', 'confirmed', 'confirmed', 'confirmed'];
    if (array_values($matrix[$seat]) !== $expected || array_unique(array_values($matrix[$otherSeat])) !== ['available']) {
        throw new RuntimeException('All seven daily bookings must appear, with only the other seat free.');
    }
    update_post_meta($room, 'rrze-rsvp-room-days-closed', $day);
    $closed = Functions::getOccupancyByRoomIdAdmin($room);
    if ($closed[$seat] !== $matrix[$seat] || array_unique(array_values($closed[$otherSeat])) !== ['unscheduled']) {
        throw new RuntimeException('A closure must retain real bookings without offering other seats.');
    }
    wp_trash_post($bookingIds[0]);
    if (in_array('06:00 - 07:00', Functions::getOccupancyByRoomIdAdmin($room)['room_slots'], true)) {
        throw new RuntimeException('A closing day must not list intervals without bookings.');
    }
    echo "Occupancy integration passed.\n";
} finally {
    foreach (array_reverse($ids) as $id) {
        wp_delete_post($id, true);
    }
}
