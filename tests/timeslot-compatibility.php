<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('UTC');
define('ABSPATH', dirname(__DIR__) . '/');

$testTimeslots = [];
$testClosedDays = '';
$roomMeta = [];
$bookingMeta = [];

/**
 * Supply isolated legacy metadata for the real availability implementation.
 *
 * @param int $postId Room or seat fixture ID.
 * @param string $key Metadata key.
 * @param bool $single Whether to return a single value.
 * @return mixed Fixture metadata.
 */
function get_post_meta($postId, $key = '', $single = false)
{
    global $testTimeslots, $testClosedDays, $roomMeta, $bookingMeta;
    return match ($key) {
        'rrze-rsvp-seat-room' => 77,
        'rrze-rsvp-room-timeslots' => $testTimeslots,
        'rrze-rsvp-room-days-closed' => $testClosedDays,
        default => $bookingMeta[$postId][$key] ?? $roomMeta[$key] ?? '',
    };
}

/**
 * Keep this schedule compatibility test independent of existing reservations.
 *
 * @param array $args WordPress query arguments.
 * @return array Empty booking result.
 */
function get_posts(array $args): array
{
    global $bookingMeta;
    if ($args['post_type'] === 'seat') {
        return [101, 102];
    }
    return array_map(/** @param int $id Booking fixture. @return object WP post stub. */ static fn(int $id) => (object) ['ID' => $id], array_keys($bookingMeta));
}

/** @param string $format Requested format. @return int Mock site time seven minutes into the first interval. */
function current_time($format): int
{
    return strtotime('2026-11-03 09:07 UTC');
}

/** @param string $label Source string. @param string $domain Translation domain. @return string Test label. */
function __($label, $domain): string
{
    return $label;
}

require_once dirname(__DIR__) . '/includes/RoomSlots.php';
require_once dirname(__DIR__) . '/includes/Functions.php';

use RRZE\RSVP\Functions;

/**
 * Compare availability including exact dates and time ranges.
 *
 * @param array $expected Expected available slots.
 * @param array $actual Actual available slots.
 * @param string $message Scenario description.
 * @return void
 */
function assertAvailability(array $expected, array $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': ' . json_encode($actual));
    }
}

$testTimeslots = [[
    'rrze-rsvp-room-weekday' => ['2'],
    'rrze-rsvp-room-starttime' => '09:00',
    'rrze-rsvp-room-endtime' => '11:00',
    'rrze-rsvp-room-timeslot-valid-from' => strtotime('2026-11-03'),
    'rrze-rsvp-room-timeslot-valid-to' => strtotime('2026-11-03'),
]];
assertAvailability(
    ['2026-11-03' => ['09:00-11:00']],
    Functions::getSeatAvailability(101, '2026-11-01', '2026-11-17'),
    'A single date must not repeat in following weeks'
);

$testTimeslots[] = [
    'rrze-rsvp-room-weekday' => ['4'],
    'rrze-rsvp-room-starttime' => '14:00',
    'rrze-rsvp-room-endtime' => '16:00',
];
assertAvailability(
    ['2026-11-03' => ['09:00-11:00'], '2026-11-05' => ['14:00-16:00'], '2026-11-12' => ['14:00-16:00']],
    Functions::getSeatAvailability(101, '2026-11-01', '2026-11-17'),
    'Existing weekly rules and single dates must coexist'
);

$testClosedDays = "2026-11-03\n2026-11-12";
assertAvailability(
    ['2026-11-05' => ['14:00-16:00']],
    Functions::getSeatAvailability(101, '2026-11-01', '2026-11-17'),
    'Closed days must still override either schedule type'
);

// The clock is 09:07. A reservation from 10:00 to 11:00 splits the first device's opening.
use RRZE\RSVP\RoomSlots;
$roomMeta = [RoomSlots::ENABLED => 'on', RoomSlots::STEP => '20', RoomSlots::MIN => '20', RoomSlots::MAX => '80'];
$testClosedDays = '';
$testTimeslots[0]['rrze-rsvp-room-endtime'] = '12:00';
$day = '2026-11-03';
$bookingMeta = [501 => [
    'rrze-rsvp-booking-start' => strtotime("$day 10:00 UTC"),
    'rrze-rsvp-booking-end' => strtotime("$day 11:00 UTC"),
    'rrze-rsvp-booking-seat' => 101,
]];
$slots = RoomSlots::available(77, $day, $day, false, 40)['slots'][$day];
assertAvailability([102], $slots['09:40-10:20'], 'Partial overlaps only remove the occupied device');
assertAvailability([101, 102], $slots['11:00-11:40'], 'An adjacent reservation may start on the release boundary');
assertAvailability([], $slots['09:00-09:40'] ?? [], 'Ordinary bookings cannot start in the past');
$instant = RoomSlots::available(77, $day, $day, false, 40, 101, true);
assertAvailability(['09:00-09:40' => [101]], $instant['slots'][$day], 'On-site check-in starts at the current grid point');
assertAvailability([60], [$instant['longest']], 'Pinned check-in cannot extend into the next booking');
assertAvailability([], RoomSlots::available(77, $day, $day, false, 30)['slots'], 'A submitted off-grid duration is rejected');
assertAvailability([], RoomSlots::available(77, $day, $day, false, 100)['slots'], 'The maximum is enforced');
assertAvailability([RoomSlots::MIN, RoomSlots::MAX], array_keys(RoomSlots::errors(array_merge($roomMeta, [
    RoomSlots::MIN => 30, RoomSlots::MAX => 90,
]))), 'Both incompatible boundaries identify their fields');
$testTimeslots[0]['rrze-rsvp-room-starttime'] = '08:10';
$testTimeslots[0]['rrze-rsvp-room-endtime'] = '10:10';
$testTimeslots[] = array_replace($testTimeslots[0], ['rrze-rsvp-room-starttime' => '10:10', 'rrze-rsvp-room-endtime' => '12:10']);
$slots = RoomSlots::available(77, $day, $day, true, 40)['slots'][$day];
assertAvailability([101, 102], $slots['08:30-09:10'], 'The grid starts at the opening time');
assertAvailability([], $slots['09:50-10:30'] ?? [], 'A booking cannot straddle adjacent openings');
unset($roomMeta[RoomSlots::ENABLED]);
assertAvailability([102], Functions::getRoomAvailability(77, $day, $day)[$day]['08:10-10:10'], 'A partial reservation blocks the fixed window after switching back');
$roomMeta = [RoomSlots::ENABLED => 'on', RoomSlots::STEP => 30, RoomSlots::MIN => 30, RoomSlots::MAX => 240];
$testTimeslots = [array_replace($testTimeslots[0], ['rrze-rsvp-room-starttime' => '08:00', 'rrze-rsvp-room-endtime' => '16:00'])];
assertAvailability([102], RoomSlots::available(77, $day, $day, true, 210)['slots'][$day]['09:00-12:30'], 'Three and a half hours occupy a single device');

echo "Timeslot compatibility checks passed.\n";
