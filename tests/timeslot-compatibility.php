<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('UTC');
define('ABSPATH', dirname(__DIR__) . '/');

$testTimeslots = [];
$testClosedDays = '';

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
    global $testTimeslots, $testClosedDays;
    return match ($key) {
        'rrze-rsvp-seat-room' => 77,
        'rrze-rsvp-room-timeslots' => $testTimeslots,
        'rrze-rsvp-room-days-closed' => $testClosedDays,
        default => '',
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
    return [];
}

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

echo "Timeslot compatibility checks passed.\n";
