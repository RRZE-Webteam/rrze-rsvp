<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED);
date_default_timezone_set('UTC');

define('ABSPATH', dirname(__DIR__) . '/');

$testGetPostsCalls = [];
$testPostMeta = [];
$testPosts = [];
$testQueryArgs = [];
$testQueryIds = [];
$testUpdatedMeta = [];
$testCurrentPostId = 0;

define('CORONA_MODE', false);

class WP_Query
{
    private array $postIds;
    private int $index = 0;

    public function __construct(array $args)
    {
        global $testQueryArgs, $testQueryIds;

        $testQueryArgs[] = $args;
        $this->postIds = $testQueryIds;
    }

    public function have_posts(): bool
    {
        return $this->index < count($this->postIds);
    }

    public function the_post(): void
    {
        global $testCurrentPostId;

        $testCurrentPostId = $this->postIds[$this->index];
        $this->index++;
    }
}

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

function get_the_ID(): int
{
    global $testCurrentPostId;

    return $testCurrentPostId;
}

function update_post_meta($postId, $key, $value): bool
{
    global $testPostMeta, $testUpdatedMeta;

    $testPostMeta[$postId][$key] = $value;
    $testUpdatedMeta[$postId][$key] = $value;
    return true;
}

function wp_reset_postdata(): void
{
}

function update_meta_cache($metaType, array $objectIds): bool
{
    return true;
}

function current_time($type)
{
    return mktime(12, 0, 0, 6, 23, 2026);
}

function wp_timezone(): DateTimeZone
{
    return new DateTimeZone('UTC');
}

require_once dirname(__DIR__) . '/includes/Utils.php';
require_once dirname(__DIR__) . '/includes/Functions.php';
require_once dirname(__DIR__) . '/includes/Metaboxes.php';
require_once dirname(__DIR__) . '/includes/Schedule.php';

use RRZE\RSVP\Functions;
use RRZE\RSVP\Metaboxes;
use RRZE\RSVP\Schedule;
use RRZE\RSVP\Utils;

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

$testPosts[997] = (object) [
    'post_type' => 'booking',
    'post_status' => 'publish',
];
$testPostMeta[997] = [
    'rrze-rsvp-booking-start' => mktime(10, 0, 0, 6, 23, 2026),
    'rrze-rsvp-booking-end' => mktime(13, 0, 0, 6, 23, 2026),
    'rrze-rsvp-booking-status' => 'checked-in',
];
assertFalse(
    Functions::canDeleteBooking(997),
    'An active checked-in booking must not be deletable.'
);

$testPosts[996] = (object) [
    'post_type' => 'booking',
    'post_status' => 'publish',
];
$testPostMeta[996] = [
    'rrze-rsvp-booking-start' => mktime(10, 0, 0, 6, 23, 2026),
    'rrze-rsvp-booking-end' => mktime(11, 0, 0, 6, 23, 2026),
    'rrze-rsvp-booking-status' => 'checked-in',
];
assertTrue(
    Functions::canDeleteBooking(996),
    'A checked-in booking must become deletable after its timeslot has ended.'
);

$testPosts[995] = (object) [
    'post_type' => 'booking',
    'post_status' => 'publish',
];
$testPostMeta[995] = [
    'rrze-rsvp-booking-start' => mktime(10, 0, 0, 6, 22, 2026),
    'rrze-rsvp-booking-status' => 'checked-in',
];
assertTrue(
    Functions::canDeleteBooking(995),
    'A stale checked-in booking without end metadata must use the end-of-day fallback.'
);

$testPosts[994] = (object) [
    'post_type' => 'booking',
    'post_status' => 'publish',
];
$testPostMeta[994] = [
    'rrze-rsvp-booking-start' => mktime(10, 0, 0, 6, 22, 2026),
    'rrze-rsvp-booking-end' => mktime(11, 0, 0, 6, 22, 2026),
    'rrze-rsvp-booking-status' => 'checked-out',
];
assertTrue(
    Functions::canDeleteBooking(994),
    'A checked-out booking must remain deletable after its timeslot has ended.'
);

$testPosts[993] = (object) [
    'post_type' => 'booking',
    'post_status' => 'publish',
];
$testPostMeta[993] = [
    'rrze-rsvp-booking-start' => mktime(10, 0, 0, 6, 23, 2026),
    'rrze-rsvp-booking-end' => mktime(11, 0, 0, 6, 23, 2026),
    'rrze-rsvp-booking-status' => 'confirmed',
];
assertFalse(
    Functions::canDeleteBooking(993),
    'A confirmed booking must retain the intended end-of-day deletion restriction.'
);

$testPosts[992] = (object) [
    'post_type' => 'booking',
    'post_status' => 'publish',
];
$testPostMeta[992] = [
    'rrze-rsvp-booking-start' => mktime(10, 0, 0, 6, 22, 2026),
    'rrze-rsvp-booking-end' => mktime(11, 0, 0, 6, 22, 2026),
    'rrze-rsvp-booking-status' => 'confirmed',
];
assertTrue(
    Functions::canDeleteBooking(992),
    'A confirmed booking must become deletable after its calendar day has ended.'
);

$testPosts[991] = (object) [
    'post_type' => 'booking',
    'post_status' => 'publish',
];
$testPostMeta[991] = [
    'rrze-rsvp-booking-start' => mktime(10, 0, 0, 6, 23, 2026),
    'rrze-rsvp-booking-status' => 'checked-in',
];
assertFalse(
    Functions::canDeleteBooking(991),
    'A current-day checked-in booking without end metadata must remain active until the end of day.'
);
assertSameValue(
    mktime(23, 59, 59, 6, 23, 2026),
    Utils::getEndOfDayTimestamp(mktime(10, 0, 0, 6, 23, 2026)),
    'The end-of-day fallback must resolve to the final second of the booking date.'
);

$testQueryIds = [997, 996, 995, 991];
$schedule = (new ReflectionClass(Schedule::class))->newInstanceWithoutConstructor();
$checkOutMethod = new ReflectionMethod(Schedule::class, 'checkOutNotCheckedOutBookings');
$checkOutMethod->invoke($schedule);

$checkoutQuery = $testQueryArgs[0] ?? [];
assertSameValue(
    2,
    count($checkoutQuery['meta_query'] ?? []),
    'The checkout query must not exclude checked-in bookings with missing end metadata.'
);
assertFalse(
    isset($testUpdatedMeta[997]['rrze-rsvp-booking-status']),
    'The checkout job must leave an active booking checked in.'
);
assertFalse(
    isset($testUpdatedMeta[991]['rrze-rsvp-booking-status']),
    'The checkout job must leave a current-day booking without end metadata checked in.'
);
assertSameValue(
    'checked-out',
    $testUpdatedMeta[996]['rrze-rsvp-booking-status'] ?? null,
    'The checkout job must check out a booking after its timeslot ends.'
);
assertSameValue(
    'checked-out',
    $testUpdatedMeta[995]['rrze-rsvp-booking-status'] ?? null,
    'The checkout job must check out a stale booking that has no end metadata.'
);

echo "Booking lifecycle tests passed.\n";
