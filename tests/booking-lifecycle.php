<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('UTC');

define('ABSPATH', dirname(__DIR__) . '/');

$testGetPostsCalls = [];
$testPostMeta = [];
$testPosts = [];

function absint($value): int
{
    return abs((int) $value);
}

function get_posts(array $args): array
{
    global $testGetPostsCalls;

    $testGetPostsCalls[] = $args;

    if (($args['post_type'] ?? '') === 'seat') {
        return [101, 102];
    }

    if (($args['post_type'] ?? '') === 'booking') {
        return [201];
    }

    return [];
}

function get_post_meta($postId, $key = '', $single = false)
{
    global $testPostMeta;

    if ($key === '') {
        return $testPostMeta[$postId] ?? [];
    }

    return $testPostMeta[$postId][$key] ?? '';
}

function get_post($postId)
{
    global $testPosts;

    return $testPosts[$postId] ?? null;
}

function update_meta_cache($metaType, array $objectIds): bool
{
    return true;
}

function current_time($type)
{
    return mktime(12, 0, 0, 6, 23, 2026);
}

require_once dirname(__DIR__) . '/includes/Functions.php';
require_once dirname(__DIR__) . '/includes/Metaboxes.php';

use RRZE\RSVP\Functions;
use RRZE\RSVP\Metaboxes;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) {
        return;
    }

    fwrite(
        STDERR,
        sprintf(
            "%s\nExpected: %s\nActual: %s\n",
            $message,
            var_export($expected, true),
            var_export($actual, true)
        )
    );
    exit(1);
}

function assertTrue(bool $actual, string $message): void
{
    assertSameValue(true, $actual, $message);
}

function assertFalse(bool $actual, string $message): void
{
    assertSameValue(false, $actual, $message);
}

$bookingStart = mktime(10, 0, 0, 6, 22, 2026);
$bookingEnd = mktime(11, 0, 0, 6, 22, 2026);
$timeslot = [
    'rrze-rsvp-room-weekday' => ['1'],
    'rrze-rsvp-room-starttime' => '10:00',
    'rrze-rsvp-room-endtime' => '11:00',
    'rrze-rsvp-room-timeslot-valid-from' => '22.06.2026',
    'rrze-rsvp-room-timeslot-valid-to' => '22.06.2026',
];

foreach (['booked', 'customer-confirmed', 'confirmed'] as $status) {
    assertTrue(
        Functions::canCancelBookingStatus($status),
        sprintf('Status "%s" should be cancellable.', $status)
    );
}

foreach (['checked-in', 'checked-out', 'cancelled'] as $status) {
    assertFalse(
        Functions::canCancelBookingStatus($status),
        sprintf('Status "%s" should not be cancellable.', $status)
    );
}

assertTrue(
    Functions::timeslotCoversBooking($timeslot, $bookingStart, $bookingEnd),
    'A matching timeslot should cover the booking, including the valid-until date.'
);

$changedTimeslot = $timeslot;
$changedTimeslot['rrze-rsvp-room-starttime'] = '09:00';
assertFalse(
    Functions::timeslotCoversBooking($changedTimeslot, $bookingStart, $bookingEnd),
    'Changing the start time must stop the timeslot from covering the booking.'
);

assertSameValue(
    1,
    count(Functions::getBookingsNotCoveredByTimeslots([], [['start' => $bookingStart, 'end' => $bookingEnd]])),
    'Server-side validation should report the number of uncovered bookings.'
);

assertSameValue(
    [],
    Functions::getBookingsNotCoveredByTimeslots(
        [$timeslot],
        [['start' => $bookingStart, 'end' => $bookingEnd]]
    ),
    'The submitted schedule should preserve a covered booking.'
);

$changedValidity = $timeslot;
$changedValidity['rrze-rsvp-room-timeslot-valid-from'] = '23.06.2026';
assertSameValue(
    [],
    Functions::getBookingsNotCoveredByTimeslots(
        [$changedValidity],
        [['start' => $bookingStart, 'end' => $bookingEnd]],
        false
    ),
    'Server-side protection must preserve the existing ability to change validity dates.'
);

assertSameValue(
    1,
    count(Functions::getBookingsNotCoveredByTimeslots(
        ['malformed-timeslot'],
        [['start' => $bookingStart, 'end' => $bookingEnd]],
        false
    )),
    'Malformed submitted timeslots must not bypass booking protection.'
);

$testPostMeta = [
    77 => [
        'rrze-rsvp-room-timeslots' => [[
            'rrze-rsvp-room-weekday' => ['1'],
            'rrze-rsvp-room-starttime' => '10:00',
            'rrze-rsvp-room-endtime' => '11:00',
            'rrze-rsvp-room-timeslot-valid-from' => mktime(0, 0, 0, 6, 22, 2026),
            'rrze-rsvp-room-timeslot-valid-to' => mktime(0, 0, 0, 6, 22, 2026),
        ]],
    ],
    201 => [
        'rrze-rsvp-booking-start' => $bookingStart,
        'rrze-rsvp-booking-end' => $bookingEnd,
        'rrze-rsvp-booking-status' => 'confirmed',
    ],
];

$firstBookings = Functions::getTimeslotBlockingBookings(77);
$secondBookings = Functions::getTimeslotBlockingBookings(77);

assertSameValue($firstBookings, $secondBookings, 'Cached room bookings should be stable.');
assertSameValue(2, count($testGetPostsCalls), 'Room seats and bookings should each be queried only once.');
assertSameValue('ids', $testGetPostsCalls[1]['fields'] ?? null, 'The booking query should request IDs only.');
assertSameValue('publish', $testGetPostsCalls[1]['post_status'] ?? null, 'Only published bookings should protect timeslots.');

$statusQuery = $testGetPostsCalls[1]['meta_query'][1]['value'] ?? [];
assertFalse(in_array('cancelled', $statusQuery, true), 'The booking query must exclude cancelled bookings.');
assertTrue(in_array('customer-confirmed', $statusQuery, true), 'The booking query must include customer-confirmed bookings.');

$metaboxes = (new ReflectionClass(Metaboxes::class))->newInstanceWithoutConstructor();
$field = (object) [
    'object_id' => 77,
    'args' => [
        'attributes' => [],
    ],
];
$metaboxes->cbTimeslotAttributes(
    ['_name' => 'rrze-rsvp-room-timeslots[0][rrze-rsvp-room-starttime]'],
    $field
);

assertSameValue(
    'disabled',
    $field->args['attributes']['disabled'] ?? null,
    'The room editor should disable a timeslot that covers a protected booking.'
);
assertSameValue(2, count($testGetPostsCalls), 'Rendering multiple timeslot fields must reuse the cached booking query.');

$testPosts[999] = (object) [
    'post_type' => 'page',
    'post_status' => 'publish',
];
assertFalse(
    Functions::canDeleteBooking(999),
    'Deletion policy must reject IDs that are not booking posts.'
);

$testPosts[998] = (object) [
    'post_type' => 'booking',
    'post_status' => 'trash',
];
$testPostMeta[998] = [
    'rrze-rsvp-booking-status' => 'cancelled',
];
assertTrue(
    Functions::canDeleteBooking(998),
    'Cancelled legacy or trashed bookings must remain eligible for permanent deletion.'
);

echo "Booking lifecycle tests passed.\n";
