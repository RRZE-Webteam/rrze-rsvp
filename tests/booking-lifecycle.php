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
$testNow = mktime(12, 0, 0, 6, 23, 2026);
$testScenarioCount = 0;

define('CORONA_MODE', false);
define('MINUTE_IN_SECONDS', 60);
define('AUTH_KEY', 'booking-lifecycle-test-key');
define('AUTH_SALT', 'booking-lifecycle-test-salt');

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

function get_the_title($postId = 0): string
{
    return 'Test item ' . $postId;
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
    global $testNow;

    return $testNow;
}

function date_i18n($format, $timestamp = false): string
{
    return date($format, $timestamp ?: current_time('timestamp'));
}

function get_option($name)
{
    return match ($name) {
        'date_format' => 'Y-m-d',
        'time_format' => 'H:i',
        default => '',
    };
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

function runLifecycleScenario(string $name, callable $scenario): void
{
    global $testScenarioCount;

    $testScenarioCount++;
    $scenario($name);
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

$schedule = (new ReflectionClass(Schedule::class))->newInstanceWithoutConstructor();
$automaticCheckInMethod = new ReflectionMethod(Schedule::class, 'cancelNotCheckedInBookings');
$automaticCheckOutMethod = new ReflectionMethod(Schedule::class, 'checkOutNotCheckedOutBookings');

runLifecycleScenario('consultation automatic check-in and checkout', function (string $scenario) use (
    $schedule,
    $automaticCheckInMethod,
    $automaticCheckOutMethod
): void {
    global $testNow, $testPostMeta, $testPosts, $testQueryIds;

    $bookingId = 990;
    $seatId = 3001;
    $roomId = 4001;
    $testPosts[$bookingId] = (object) [
        'ID' => $bookingId,
        'post_type' => 'booking',
        'post_status' => 'publish',
        'post_date' => '2026-06-22 09:00:00',
    ];
    $testPostMeta[$bookingId] = [
        'rrze-rsvp-booking-start' => mktime(10, 0, 0, 6, 23, 2026),
        'rrze-rsvp-booking-end' => mktime(11, 0, 0, 6, 23, 2026),
        'rrze-rsvp-booking-seat' => $seatId,
        'rrze-rsvp-booking-status' => 'confirmed',
    ];
    $testPostMeta[$seatId] = [
        'rrze-rsvp-seat-room' => $roomId,
    ];
    $testPostMeta[$roomId] = [
        'rrze-rsvp-room-bookingmode' => ['consultation'],
        'rrze-rsvp-room-check-in-time' => ['15'],
    ];
    $testQueryIds = [$bookingId];

    $testNow = mktime(10, 20, 0, 6, 23, 2026);
    $automaticCheckInMethod->invoke($schedule);
    assertSameValue(
        'checked-in',
        $testPostMeta[$bookingId]['rrze-rsvp-booking-status'],
        "$scenario: consultation booking should be checked in after the grace period."
    );

    $testNow = mktime(12, 0, 0, 6, 23, 2026);
    $automaticCheckOutMethod->invoke($schedule);
    assertSameValue(
        'checked-out',
        $testPostMeta[$bookingId]['rrze-rsvp-booking-status'],
        "$scenario: consultation booking should be checked out after its end time."
    );
});

runLifecycleScenario('manual checked-in booking with end time', function (string $scenario) use (
    $schedule,
    $automaticCheckOutMethod
): void {
    global $testNow, $testPostMeta, $testPosts, $testQueryArgs, $testQueryIds;

    $bookingId = 989;
    $testPosts[$bookingId] = (object) [
        'post_type' => 'booking',
        'post_status' => 'publish',
    ];
    $testPostMeta[$bookingId] = [
        'rrze-rsvp-booking-start' => mktime(10, 0, 0, 6, 23, 2026),
        'rrze-rsvp-booking-end' => mktime(11, 0, 0, 6, 23, 2026),
        'rrze-rsvp-booking-status' => 'checked-in',
    ];
    $testNow = mktime(12, 0, 0, 6, 23, 2026);
    $testQueryArgs = [];
    $testQueryIds = [$bookingId];

    $automaticCheckOutMethod->invoke($schedule);
    assertSameValue(
        'checked-out',
        $testPostMeta[$bookingId]['rrze-rsvp-booking-status'],
        "$scenario: expired manual booking should be checked out."
    );

    $checkoutQuery = $testQueryArgs[0] ?? [];
    assertTrue($checkoutQuery['no_found_rows'] ?? false, "$scenario: query should skip pagination counts.");
    assertSameValue(
        ['checked-in'],
        $checkoutQuery['meta_query']['booking_status_clause']['value'] ?? null,
        "$scenario: query should be restricted to checked-in bookings."
    );
    assertSameValue(
        '<',
        $checkoutQuery['meta_query']['booking_end_clause'][0]['compare'] ?? null,
        "$scenario: query should select passed end timestamps."
    );
    assertSameValue(
        'NOT EXISTS',
        $checkoutQuery['meta_query']['booking_end_clause'][1]['compare'] ?? null,
        "$scenario: query should retain missing end metadata."
    );
});

runLifecycleScenario('missing end metadata across midnight', function (string $scenario) use (
    $schedule,
    $automaticCheckOutMethod
): void {
    global $testNow, $testPostMeta, $testPosts, $testQueryIds;

    $staleBookingId = 988;
    $currentBookingId = 987;
    foreach ([$staleBookingId, $currentBookingId] as $bookingId) {
        $testPosts[$bookingId] = (object) [
            'post_type' => 'booking',
            'post_status' => 'publish',
        ];
    }
    $testPostMeta[$staleBookingId] = [
        'rrze-rsvp-booking-start' => mktime(10, 0, 0, 6, 22, 2026),
        'rrze-rsvp-booking-status' => 'checked-in',
    ];
    $testPostMeta[$currentBookingId] = [
        'rrze-rsvp-booking-start' => mktime(10, 0, 0, 6, 23, 2026),
        'rrze-rsvp-booking-status' => 'checked-in',
    ];
    $testNow = mktime(12, 0, 0, 6, 23, 2026);
    $testQueryIds = [$staleBookingId, $currentBookingId];

    $automaticCheckOutMethod->invoke($schedule);
    assertSameValue(
        'checked-out',
        $testPostMeta[$staleBookingId]['rrze-rsvp-booking-status'],
        "$scenario: previous-day booking should be checked out."
    );
    assertSameValue(
        'checked-in',
        $testPostMeta[$currentBookingId]['rrze-rsvp-booking-status'],
        "$scenario: current-day booking should remain checked in."
    );
});

runLifecycleScenario('delete controls before and after a timeslot', function (string $scenario): void {
    global $testNow, $testPostMeta, $testPosts;

    $activeBookingId = 986;
    $expiredBookingId = 985;
    foreach ([$activeBookingId, $expiredBookingId] as $bookingId) {
        $testPosts[$bookingId] = (object) [
            'post_type' => 'booking',
            'post_status' => 'publish',
        ];
    }
    $testPostMeta[$activeBookingId] = [
        'rrze-rsvp-booking-start' => mktime(10, 0, 0, 6, 23, 2026),
        'rrze-rsvp-booking-end' => mktime(13, 0, 0, 6, 23, 2026),
        'rrze-rsvp-booking-status' => 'checked-in',
    ];
    $testPostMeta[$expiredBookingId] = [
        'rrze-rsvp-booking-start' => mktime(10, 0, 0, 6, 23, 2026),
        'rrze-rsvp-booking-end' => mktime(11, 0, 0, 6, 23, 2026),
        'rrze-rsvp-booking-status' => 'checked-in',
    ];
    $testNow = mktime(12, 0, 0, 6, 23, 2026);

    assertFalse(Functions::canDeleteBooking($activeBookingId), "$scenario: active booking must be protected.");
    assertTrue(Functions::canDeleteBooking($expiredBookingId), "$scenario: expired booking must be deletable.");
});

runLifecycleScenario('confirmed booking end-of-day restriction', function (string $scenario): void {
    global $testNow, $testPostMeta, $testPosts;

    $sameDayBookingId = 984;
    $previousDayBookingId = 983;
    foreach ([$sameDayBookingId, $previousDayBookingId] as $bookingId) {
        $testPosts[$bookingId] = (object) [
            'post_type' => 'booking',
            'post_status' => 'publish',
        ];
    }
    $testPostMeta[$sameDayBookingId] = [
        'rrze-rsvp-booking-start' => mktime(10, 0, 0, 6, 23, 2026),
        'rrze-rsvp-booking-end' => mktime(11, 0, 0, 6, 23, 2026),
        'rrze-rsvp-booking-status' => 'confirmed',
    ];
    $testPostMeta[$previousDayBookingId] = [
        'rrze-rsvp-booking-start' => mktime(10, 0, 0, 6, 22, 2026),
        'rrze-rsvp-booking-end' => mktime(11, 0, 0, 6, 22, 2026),
        'rrze-rsvp-booking-status' => 'confirmed',
    ];
    $testNow = mktime(12, 0, 0, 6, 23, 2026);

    assertFalse(
        Functions::canDeleteBooking($sameDayBookingId),
        "$scenario: same-day confirmed booking must remain protected."
    );
    assertTrue(
        Functions::canDeleteBooking($previousDayBookingId),
        "$scenario: previous-day confirmed booking must be deletable."
    );
});

runLifecycleScenario('DST boundary end-of-day fallback', function (string $scenario): void {
    // RSVP stores booking wall-clock values as UTC-based timestamps. These are
    // the 2026 Europe/Berlin daylight-saving transition dates.
    foreach ([[3, 29], [10, 25]] as [$month, $day]) {
        $start = mktime(12, 0, 0, $month, $day, 2026);
        $expectedEnd = mktime(23, 59, 59, $month, $day, 2026);
        assertSameValue(
            $expectedEnd,
            Utils::getEndOfDayTimestamp($start),
            "$scenario: fallback must stay on the booking date $month/$day."
        );
    }
});

assertSameValue(6, $testScenarioCount, 'Exactly six lifecycle scenarios should run.');

echo "Booking lifecycle tests passed, including 6 lifecycle scenarios.\n";
