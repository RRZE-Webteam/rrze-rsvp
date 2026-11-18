<?php
/**
 * Exercise department assignments and immutable snapshots against local WordPress.
 *
 * Run: wp --skip-plugins --skip-themes --url=SITE eval-file tests/department-integration.php
 * Creates disposable posts, sends no mail and restores site options in finally.
 */

use RRZE\RSVP\Access\{BookingDepartment, DepartmentCatalog, RoomAccess};
use RRZE\RSVP\{Functions, RoomReservations};

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this test explicitly through WP-CLI.');
}
require_once dirname(__DIR__) . '/rrze-rsvp.php';
RRZE\RSVP\plugin()->loaded();
(new RRZE\RSVP\CPT\Rooms())->room_post_type();
(new RRZE\RSVP\CPT\Seats())->seats_post_type();
(new RRZE\RSVP\CPT\Bookings())->booking_post_type();
$originalOptions = [];
foreach (['rrze_rsvp', RoomAccess::CONTEXT_OPTION, DepartmentCatalog::OPTION_NAME] as $option) {
    $originalOptions[$option] = get_option($option, null);
}
$GLOBALS['departmentIntegrationIds'] = [];
$fixtureIds =& $GLOBALS['departmentIntegrationIds'];

/**
 * Stop on unexpected results without logging ciphertext or personal information.
 *
 * @param bool $condition Expected condition.
 * @param string $message Non-sensitive explanation.
 * @return void
 */
function departmentIntegrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Create a disposable booking through the production reservation writer.
 *
 * @param int $room Fixture room.
 * @param int $seat Fixture seat.
 * @param int $days Days in the future to avoid overlap with another fixture.
 * @return int Created booking ID, recorded for cleanup before further assertions.
 */
function departmentIntegrationBook(int $room, int $seat, int $days): int
{
    $booking = RoomReservations::create($room, $seat,
        current_datetime()->modify('+' . $days . ' days')->format('Y-m-d'),
        '09:00', null, false,
        ['rrze-rsvp-booking-guest-email' => Functions::crypt('department-fixture@example.test'),
            'rrze-rsvp-booking-status' => 'confirmed'],
        ['source' => 'sso', 'email' => 'department-fixture@example.test']);
    departmentIntegrationAssert(is_int($booking) && $booking > 0, 'Fixture reservation failed.');
    $GLOBALS['departmentIntegrationIds'][] = $booking;
    departmentIntegrationAssert(get_post_status($booking) === 'publish', 'Reservation was not published.');
    return $booking;
}

try {
    $options = is_array($originalOptions['rrze_rsvp']) ? $originalOptions['rrze_rsvp'] : [];
    $options['general_room_access_enabled'] = 'on';
    update_option('rrze_rsvp', $options);
    foreach (['room', 'seat'] as $type) {
        $id = wp_insert_post(['post_type' => $type, 'post_status' => 'publish',
            'post_title' => '[RSVP department integration] ' . wp_generate_uuid4()], true);
        departmentIntegrationAssert(is_int($id) && $id > 0, 'Could not create fixture.');
        $fixtureIds[] = $id;
    }
    [$room, $seat] = $fixtureIds;
    update_post_meta($seat, 'rrze-rsvp-seat-room', $room);
    update_post_meta($room, 'rrze-rsvp-room-sso-required', 'on');
    update_post_meta($room, 'rrze-rsvp-room-bookingmode', 'no-check');
    update_post_meta($room, 'rrze-rsvp-room-days-in-advance', 7);
    update_post_meta($room, 'rrze-rsvp-room-timeslots', [[
        'rrze-rsvp-room-weekday' => ['1', '2', '3', '4', '5', '6', '7'],
        'rrze-rsvp-room-starttime' => '09:00', 'rrze-rsvp-room-endtime' => '10:00',
    ]]);
    $name = '[RSVP Test] Physik ' . wp_generate_uuid4();
    $department = DepartmentCatalog::create($name);
    departmentIntegrationAssert(is_array($department), 'Could not create department.');
    departmentIntegrationAssert(is_wp_error(DepartmentCatalog::create(strtoupper($name))), 'Duplicate names were accepted.');
    $revision = RoomAccess::revision($room);
    departmentIntegrationAssert(is_string($revision), 'Could not read empty-list revision.');
    $entries = [['email' => 'department-fixture@example.test', 'department_id' => $department['id']]];
    departmentIntegrationAssert(RoomAccess::saveEntries($room, $entries, $revision) === true, 'Could not assign department.');
    departmentIntegrationAssert(is_wp_error(RoomAccess::saveEntries($room, [], $revision)), 'A stale editor overwrote a changed list.');
    departmentIntegrationAssert(RoomAccess::save($room, ['department-fixture@example.test']) === true
        && RoomAccess::readEntries($room) === $entries, 'Legacy save dropped a department.');
    $rawList = (string) get_post_meta($room, RoomAccess::META_KEY, true);
    departmentIntegrationAssert(!str_contains($rawList, 'department-fixture@example.test')
        && !str_contains($rawList, $department['id']), 'Room assignment was stored in plaintext.');

    $first = departmentIntegrationBook($room, $seat, 1);
    $historical = ['id' => $department['id'], 'name' => $name];
    departmentIntegrationAssert(BookingDepartment::read($first) === $historical, 'Snapshot missing or incorrect.');
    $rawSnapshot = (string) get_post_meta($first, BookingDepartment::META_KEY, true);
    departmentIntegrationAssert($rawSnapshot !== '' && !str_contains($rawSnapshot, $name)
        && !str_contains($rawSnapshot, $department['id']), 'Booking assignment was stored in plaintext.');

    $renamed = $name . ' Neu';
    departmentIntegrationAssert(DepartmentCatalog::rename($department['id'], $renamed) === true, 'Rename failed.');
    departmentIntegrationAssert(DepartmentCatalog::setArchived($department['id'], true) === true, 'Archive failed.');
    $second = departmentIntegrationBook($room, $seat, 2);
    departmentIntegrationAssert(BookingDepartment::read($first) === $historical, 'Rename changed the historical snapshot.');
    departmentIntegrationAssert(BookingDepartment::read($second) === ['id' => $department['id'], 'name' => $renamed],
        'Existing archived assignment did not retain its current name for a new booking.');
    departmentIntegrationAssert(is_wp_error(RoomAccess::saveEntries($room, array_merge($entries,
        [['email' => 'new-fixture@example.test', 'department_id' => $department['id']]]))),
        'A new archived assignment was accepted.');

    departmentIntegrationAssert(RoomAccess::saveEntries($room, []) === true, 'Could not remove the permission list.');
    $options['general_room_access_enabled'] = 'off';
    update_option('rrze_rsvp', $options);
    $third = departmentIntegrationBook($room, $seat, 3);
    departmentIntegrationAssert(BookingDepartment::read($third) === null, 'An unrestricted booking received a snapshot.');
    departmentIntegrationAssert(BookingDepartment::read($first) === $historical, 'Disabling access removed history.');
    update_post_meta($third, BookingDepartment::META_KEY, wp_slash($rawSnapshot));
    departmentIntegrationAssert(is_wp_error(BookingDepartment::read($third)), 'Copied snapshot authorized another booking.');
    update_post_meta($first, BookingDepartment::META_KEY, 'damaged-fixture');
    departmentIntegrationAssert(is_wp_error(BookingDepartment::read($first)), 'Damaged snapshot was treated as absent.');
    echo "Department WordPress integration passed (catalog, revision, encrypted assignments, immutable booking history, archive, disabled feature, tamper rejection).\n";
} finally {
    foreach (array_reverse($fixtureIds) as $id) {
        wp_delete_post($id, true);
    }
    foreach ($originalOptions as $option => $value) {
        if ($value === null) {
            delete_option($option);
        } else {
            update_option($option, $value, in_array($option, [RoomAccess::CONTEXT_OPTION, DepartmentCatalog::OPTION_NAME], true) ? false : null);
        }
    }
}
