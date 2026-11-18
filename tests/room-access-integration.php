<?php
/**
 * Exercise encrypted permissions and reservation writes against local WordPress.
 *
 * Run: wp --skip-plugins --skip-themes --url=SITE eval-file tests/room-access-integration.php
 * Uses disposable posts, sends no mail, and restores site options in finally.
 */
use RRZE\RSVP\Access\RoomAccess;
use RRZE\RSVP\{Functions, RoomReservations};

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this test explicitly through WP-CLI.');
}
require_once dirname(__DIR__) . '/rrze-rsvp.php';
RRZE\RSVP\plugin()->loaded();
(new RRZE\RSVP\CPT\Rooms())->room_post_type();
(new RRZE\RSVP\CPT\Seats())->seats_post_type();
(new RRZE\RSVP\CPT\Bookings())->booking_post_type();
$originalOptions = get_option('rrze_rsvp', false);
$originalContext = get_option(RoomAccess::CONTEXT_OPTION, false);
$fixtureIds = [];

/**
 * Stop the test on an unexpected observable result without leaking stored data.
 *
 * @param bool $condition Expected condition.
 * @param string $message Non-sensitive failure explanation.
 * @return void
 */
function roomAccessIntegrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $options = is_array($originalOptions) ? $originalOptions : [];
    $options['general_room_access_enabled'] = 'on';
    update_option('rrze_rsvp', $options);
    foreach (['room', 'room', 'seat'] as $type) {
        $id = wp_insert_post(['post_type' => $type, 'post_status' => 'publish',
            'post_title' => '[RSVP access integration] ' . wp_generate_uuid4()], true);
        roomAccessIntegrationAssert(is_int($id) && $id > 0, 'Fixture creation failed.');
        $fixtureIds[] = $id;
    }
    [$room, $otherRoom, $seat] = $fixtureIds;
    update_post_meta($seat, 'rrze-rsvp-seat-room', $room);
    foreach ([$room, $otherRoom] as $id) {
        update_post_meta($id, 'rrze-rsvp-room-sso-required', 'on');
        update_post_meta($id, 'rrze-rsvp-room-bookingmode', 'no-check');
        update_post_meta($id, 'rrze-rsvp-room-days-in-advance', 7);
        update_post_meta($id, 'rrze-rsvp-room-timeslots', [[
            'rrze-rsvp-room-weekday' => ['1', '2', '3', '4', '5', '6', '7'],
            'rrze-rsvp-room-starttime' => '09:00', 'rrze-rsvp-room-endtime' => '10:00',
        ]]);
    }
    roomAccessIntegrationAssert(RoomAccess::save($room, [' Allowed@Example.Test ', 'allowed@example.test']) === true, 'Encrypted save failed.');
    roomAccessIntegrationAssert(RoomAccess::read($room) === ['allowed@example.test'], 'Normalization roundtrip failed.');
    $raw = (string) get_post_meta($room, RoomAccess::META_KEY, true);
    roomAccessIntegrationAssert(!str_contains($raw, 'allowed@example.test'), 'Plaintext was stored.');
    update_post_meta($otherRoom, RoomAccess::META_KEY, wp_slash($raw));
    roomAccessIntegrationAssert(is_wp_error(RoomAccess::authorize($otherRoom, ['source' => 'sso', 'email' => 'allowed@example.test'])), 'Copied ciphertext authorized another room.');

    $date = current_datetime()->modify('+1 day')->format('Y-m-d');
    $meta = ['rrze-rsvp-booking-guest-email' => Functions::crypt('allowed@example.test'),
        'rrze-rsvp-booking-status' => 'confirmed', 'rrze-rsvp-booking-seat' => $seat];
    roomAccessIntegrationAssert(is_wp_error(RoomReservations::create($room, $seat, $date, '09:00', null, false, $meta)), 'Writer accepted an unverified identity.');
    roomAccessIntegrationAssert(is_wp_error(RoomReservations::create($room, $seat, $date, '09:00', null, false, $meta,
        ['source' => 'sso', 'email' => 'denied@example.test'])), 'Writer accepted a denied identity.');
    $booking = RoomReservations::create($room, $seat, $date, '09:00', null, false, $meta,
        ['source' => 'sso', 'email' => 'allowed@example.test']);
    roomAccessIntegrationAssert(is_int($booking) && $booking > 0, 'Allowed reservation failed.');
    $fixtureIds[] = $booking;
    roomAccessIntegrationAssert(get_post_status($booking) === 'publish', 'Allowed booking was not published.');

    // The adapter still enforces LDAP login; the allowlist keeps SSO identity priority.
    update_post_meta($room, 'rrze-rsvp-room-sso-required', 'off');
    update_post_meta($room, 'rrze-rsvp-room-ldap-required', 'on');
    $options['ldap_server'] = 'ldap://fixture.invalid';
    update_option('rrze_rsvp', $options);
    roomAccessIntegrationAssert(RoomAccess::authorize($room, ['source' => 'sso', 'email' => 'allowed@example.test']) === true,
        'SSO identity priority failed on a configured LDAP room.');

    // Mutate the database directly after a cached read to simulate another request.
    get_post_meta($room, RoomAccess::META_KEY, true);
    global $wpdb;
    $wpdb->update($wpdb->postmeta, ['meta_value' => 'damaged-fixture'], ['post_id' => $room, 'meta_key' => RoomAccess::META_KEY]);
    roomAccessIntegrationAssert(is_wp_error(RoomAccess::authorize($room, ['source' => 'sso', 'email' => 'allowed@example.test'])),
        'Authorization reused stale metadata after a concurrent change.');
    roomAccessIntegrationAssert(is_wp_error(RoomAccess::save($room, [])), 'Unreadable data was cleared by an ordinary save.');
    update_post_meta($room, RoomAccess::META_KEY, wp_slash($raw));
    roomAccessIntegrationAssert(RoomAccess::save($room, []) === true && RoomAccess::authorize($room, null) === true,
        'Explicitly clearing a readable list did not remove the restriction.');
    echo "Room-access WordPress integration passed (encrypted DB, reservation writer, SSO priority, stale cache, fail-closed recovery).\n";
} finally {
    foreach (array_reverse($fixtureIds) as $id) {
        wp_delete_post($id, true);
    }
    if ($originalOptions === false) {
        delete_option('rrze_rsvp');
    } else {
        update_option('rrze_rsvp', $originalOptions);
    }
    if ($originalContext === false) {
        delete_option(RoomAccess::CONTEXT_OPTION);
    } else {
        update_option(RoomAccess::CONTEXT_OPTION, $originalContext, false);
    }
}
