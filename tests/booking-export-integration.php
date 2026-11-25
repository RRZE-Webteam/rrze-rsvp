<?php
/**
 * Exercise export SQL, permissions and encrypted history against local WordPress.
 *
 * Run: wp --skip-plugins --skip-themes --url=SITE eval-file tests/booking-export-integration.php
 * Creates disposable posts, sends no mail and restores options and the current user.
 */

use RRZE\RSVP\Access\{BookingDepartment, DepartmentCatalog, RoomAccess};
use RRZE\RSVP\Export\{Admin, BookingExport};
use RRZE\RSVP\Functions;

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this test explicitly through WP-CLI.');
}
require_once dirname(__DIR__) . '/rrze-rsvp.php';
RRZE\RSVP\plugin()->loaded();
(new RRZE\RSVP\CPT\Rooms())->room_post_type();
(new RRZE\RSVP\CPT\Seats())->seats_post_type();
(new RRZE\RSVP\CPT\Bookings())->booking_post_type();
$originalUser = get_current_user_id();
$originalOptions = [];
foreach (['rrze_rsvp', RoomAccess::CONTEXT_OPTION, DepartmentCatalog::OPTION_NAME] as $option) {
    $originalOptions[$option] = get_option($option, null);
}
$GLOBALS['exportIntegrationIds'] = [];

/**

 * @param bool $condition Expected condition.

 * @param string $message Safe failure description.

 * @return void

 */
function exportIntegrationAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/**
 * Create and immediately track a disposable post for unconditional cleanup.
 *
 * @param string $type Registered post type.
 * @param string $title Fixture title.
 * @return int New draft ID.
 */
function exportIntegrationPost(string $type, string $title): int
{
    $id = wp_insert_post(['post_type' => $type, 'post_status' => 'draft', 'post_title' => $title], true);
    exportIntegrationAssert(is_int($id) && $id > 0, 'Could not create export fixture.');
    $GLOBALS['exportIntegrationIds'][] = $id;
    return $id;
}

/**
 * Create one booking without running notifications or public booking handlers.
 *
 * @param int $seat Fixture seat ID.
 * @param string $start Local-clock start in existing room-booking format.
 * @param string $status Existing booking status.
 * @param array|null $department Optional immutable historical department.
 * @return int Published booking ID.
 */
function exportIntegrationBooking(int $seat, string $start, string $status = 'confirmed', ?array $department = null): int
{
    $id = exportIntegrationPost('booking', '[RSVP export integration]');
    $stamp = strtotime($start . ' UTC');
    foreach (['seat' => $seat, 'start' => $stamp, 'end' => $stamp + 1800, 'status' => $status,
        'guest-firstname' => Functions::crypt('Änne'), 'guest-lastname' => Functions::crypt('Test; "West"'),
        'guest-email' => Functions::crypt('export-fixture@example.test')] as $key => $value) {
        update_post_meta($id, 'rrze-rsvp-booking-' . $key, $value);
    }
    if ($department !== null) {
        $room = (int) get_post_meta($seat, 'rrze-rsvp-seat-room', true);
        exportIntegrationAssert(BookingDepartment::saveNew($id, $room, $department) === true, 'Could not write encrypted history.');
    }
    wp_update_post(['ID' => $id, 'post_status' => 'publish']);
    return $id;
}

/**
 * Deny one booking even for a super administrator, proving the per-object boundary.
 *
 * @param array $caps Required primitive capabilities.
 * @param string $cap Requested capability.
 * @param int $userId Current user.
 * @param array $args Object arguments.
 * @return array Required capabilities, with an explicit fixture denial.
 */
function exportIntegrationDeny(array $caps, string $cap, int $userId, array $args): array
{
    return $cap === 'edit_post' && ($args[0] ?? 0) === ($GLOBALS['exportIntegrationDenied'] ?? 0)
        ? ['do_not_allow'] : $caps;
}

try {
    $admins = get_users(['role' => 'administrator', 'fields' => 'ID', 'number' => 1]);
    exportIntegrationAssert($admins !== [], 'A local site administrator is required.');
    wp_set_current_user((int) $admins[0]);
    exportIntegrationAssert(current_user_can('edit_bookings'), 'The local administrator cannot manage bookings.');
    $options = is_array($originalOptions['rrze_rsvp']) ? $originalOptions['rrze_rsvp'] : [];
    $options['general_room_access_enabled'] = 'on';
    update_option('rrze_rsvp', $options);
    $room = exportIntegrationPost('room', '[RSVP export integration] Raum; "West"');
    $seat = exportIntegrationPost('seat', '[RSVP export integration] Platz 1');
    wp_update_post(['ID' => $room, 'post_status' => 'publish']);
    wp_update_post(['ID' => $seat, 'post_status' => 'publish']);
    update_post_meta($seat, 'rrze-rsvp-seat-room', $room);
    update_post_meta($room, 'rrze-rsvp-room-sso-required', 'on');
    $department = DepartmentCatalog::create('[RSVP Export Test] ' . wp_generate_uuid4());
    exportIntegrationAssert(is_array($department), 'Could not create fixture department.');
    $historical = ['id' => $department['id'], 'name' => $department['name']];
    exportIntegrationAssert(RoomAccess::saveEntries($room, [['email' => 'export-fixture@example.test',
        'department_id' => $department['id']]]) === true, 'Could not save room assignments.');

    $first = exportIntegrationBooking($seat, '2026-03-29 00:00:00', 'confirmed', $historical);
    $last = exportIntegrationBooking($seat, '2026-03-29 23:59:59', 'cancelled');
    delete_post_meta($last, 'rrze-rsvp-booking-end');
    exportIntegrationBooking($seat, '2026-03-28 23:59:59');
    exportIntegrationBooking($seat, '2026-03-30 00:00:00');
    $course = exportIntegrationBooking($seat, '2026-03-29 12:00:00');
    update_post_meta($course, 'rrze-rsvp-booking-course-date', 123);
    $draft = exportIntegrationBooking($seat, '2026-03-29 12:00:00');
    wp_update_post(['ID' => $draft, 'post_status' => 'draft']);
    $trashed = exportIntegrationBooking($seat, '2026-03-29 12:00:00');
    wp_trash_post($trashed);
    $denied = exportIntegrationBooking($seat, '2026-03-29 12:00:00');
    $GLOBALS['exportIntegrationDenied'] = $denied;
    update_post_meta($denied, BookingDepartment::META_KEY, 'must-not-be-read');
    add_filter('map_meta_cap', 'exportIntegrationDeny', 10, 4);
    $input = ['room_id' => (string) $room, 'from' => '2026-03-29', 'to' => '2026-03-29',
        'status' => 'all', 'sort' => 'start', 'include_personal' => '0'];
    $service = new BookingExport();
    $document = $service->prepare($input);
    exportIntegrationAssert(is_array($document) && array_column($document['rows'], 0) === [$first, $last],
        'SQL date boundaries, room joins, course exclusion or per-object rights failed.');
    exportIntegrationAssert($document['rows'][0][3] === '2026-03-29 00:00:00' && $document['rows'][1][4] === '',
        'Stored wall-clock times or unknown ends changed.');
    $filtered = $service->prepare(array_replace($input, ['status' => 'cancelled']));
    exportIntegrationAssert(is_array($filtered) && array_column($filtered['rows'], 0) === [$last]
        && end($filtered['headers']) === __('Department', 'rrze-rsvp') && $filtered['rows'][0][7] === '',
        'Current departments must add the column without retroactive assignment.');

    DepartmentCatalog::rename($department['id'], $historical['name'] . ' Neu');
    DepartmentCatalog::setArchived($department['id'], true);
    RoomAccess::saveEntries($room, []);
    $document = $service->prepare(array_replace($input, ['include_personal' => '1', 'sort' => 'department']));
    exportIntegrationAssert(is_array($document) && $document['rows'][0][10] === $historical['name']
        && $document['rows'][0][7] === 'Änne' && $document['rows'][0][9] === 'export-fixture@example.test'
        && $document['rows'][1][10] === '', 'Historical names, optional decryption or department ordering failed.');
    $admin = new Admin();
    $request = $input + ['_wpnonce' => wp_create_nonce('rrze-rsvp-export-bookings')];
    $download = $admin->submit($request);
    exportIntegrationAssert(is_array($download) && str_starts_with($download['csv'], "\xEF\xBB\xBF")
        && !str_contains($download['csv'], 'export-fixture@example.test'), 'Default file leaked personal columns or lost its BOM.');
    exportIntegrationAssert(is_wp_error($admin->submit(array_replace($request, ['_wpnonce' => 'invalid']))), 'Invalid nonce allowed a download.');
    update_post_meta($first, BookingDepartment::META_KEY, 'damaged');
    exportIntegrationAssert(is_wp_error($admin->submit($request)), 'Corrupt encrypted history allowed a partial export.');
    $options['general_room_access_enabled'] = 'off';
    update_option('rrze_rsvp', $options);
    $document = $service->prepare($input);
    exportIntegrationAssert(is_array($document) && count($document['headers']) === 7, 'Disabled feature still reads department history.');
    wp_set_current_user(0);
    exportIntegrationAssert(is_wp_error($admin->submit($request)), 'Unauthenticated user exported bookings.');
    wp_set_current_user((int) $admins[0]);
    if (is_multisite()) {
        $otherSites = get_sites(['site__not_in' => [get_current_blog_id()], 'number' => 1, 'fields' => 'ids']);
        if ($otherSites) {
            switch_to_blog((int) $otherSites[0]);
            try {
                $document = $service->prepare($input);
                exportIntegrationAssert(is_wp_error($document) || $document['rows'] === [], 'Another website exposed fixture bookings.');
            } finally {
                restore_current_blog();
            }
        }
    }
    echo "Booking export WordPress integration passed (real SQL, dates, room/status filters, rights, encrypted history, CSV, nonce, disabled feature and website isolation).\n";
} finally {
    remove_filter('map_meta_cap', 'exportIntegrationDeny', 10);
    foreach (array_reverse($GLOBALS['exportIntegrationIds']) as $id) wp_delete_post($id, true);
    foreach ($originalOptions as $option => $value) {
        if ($value === null) delete_option($option);
        else update_option($option, $value, in_array($option, [RoomAccess::CONTEXT_OPTION, DepartmentCatalog::OPTION_NAME], true) ? false : null);
    }
    wp_set_current_user($originalUser);
}
