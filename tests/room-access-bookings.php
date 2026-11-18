<?php
/** Exercise real public room adapters and final writes with controlled authorization boundaries. */
declare(strict_types=1);

require __DIR__ . '/fixtures/room-access-bookings.php';
require dirname(__DIR__) . '/includes/RoomReservations.php';
require dirname(__DIR__) . '/includes/Shortcodes/Bookings.php';

use RRZE\RSVP\Access\{BookingDepartment, RoomAccess};
use RRZE\RSVP\Auth\{IdM, LDAP};
use RRZE\RSVP\Blocks\Context;
use RRZE\RSVP\{Email, Functions, RoomAccessSettingsFixture, RoomReservations, RoomSlots};
use RRZE\RSVP\Shortcodes\Bookings;

/** @param bool $condition Expected result. @param string $message Failure context. @return void */
function roomAccessCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** @return Bookings Adapter with public boundaries configured and no WordPress hooks registered. */
function roomAccessAdapter(): Bookings
{
    $reflection = new ReflectionClass(Bookings::class);
    $adapter = $reflection->newInstanceWithoutConstructor();
    foreach (['settings' => new RoomAccessSettingsFixture(), 'shortcodesettings' => [],
        'options' => (object) ['email_notification_if_new' => 'no', 'email_notification_email' => ''],
        'email' => new Email(), 'nonce' => ''] as $property => $value) {
        $reflection->getProperty($property)->setValue($adapter, $value);
    }
    return $adapter;
}

/** @return void Restore one published room, one seat, no bookings, and an SSO-only policy. */
function roomAccessReset(): void
{
    $_GET = $_POST = $_REQUEST = [];
    $GLOBALS['post'] = (object) ['ID' => 30];
    $GLOBALS['wpdb'] = new RoomAccessDatabaseFixture();
    $GLOBALS['room_access_inserts'] = [];
    $GLOBALS['room_access_publications'] = $GLOBALS['room_access_deletions'] = [];
    $GLOBALS['room_access_meta'] = [10 => [
        'rrze-rsvp-room-bookingmode' => 'reservation', 'rrze-rsvp-room-sso-required' => 'on',
        'rrze-rsvp-room-auto-confirmation' => 'on', 'rrze-rsvp-room-days-in-advance' => 30,
    ], 20 => ['rrze-rsvp-seat-room' => 10]];
    IdM::$authenticated = true;
    IdM::$email = LDAP::$email = 'allowed@example.invalid';
    LDAP::$authenticated = false;
    RoomSlots::$stopRendering = RoomSlots::$flexible = false;
    RoomSlots::$duringAvailability = null;
    Email::$sent = 0;
    RoomAccess::$enabled = true;
    RoomAccess::$calls = [];
    RoomAccess::$resolved = 0;
    RoomAccess::$department = null;
    BookingDepartment::$fail = false;
    BookingDepartment::$snapshots = [];
    RoomAccess::$decision = /** @param int $room Room ID. @param array|null $identity Verified profile. @return true|WP_Error */
        static fn(int $room, ?array $identity): true|WP_Error => $room === 10
            && in_array($identity['source'] ?? '', ['sso', 'ldap'], true)
            && ($identity['email'] ?? '') === 'allowed@example.invalid'
            ? true : new WP_Error('room_access_denied', 'Private allowlist diagnostic: secret@example.invalid');
}

/** @param bool $block Whether to submit a bound block. @return array Valid room form with an untrusted POST email. */
function roomAccessInput(bool $block = false): array
{
    return array_merge([
        'rrze_rsvp_post_nonce_field' => wp_create_nonce($block ? 'fixture-block:submit' : 'post_nonce'),
        'rsvp_room' => '10', 'rsvp_seat' => '20', 'rsvp_date' => '2026-11-19', 'rsvp_time' => '09:00',
        'rsvp_firstname' => 'Posted', 'rsvp_lastname' => 'Person', 'rsvp_email' => 'forged@example.invalid',
        'rsvp_dsgvo' => '1',
    ], $block ? ['rsvp_instance' => 'fixture'] : []);
}

/** @param Bookings $adapter Public adapter. @param array $input Form payload. @return RoomAccessResponse Terminal response. */
function roomAccessSubmit(Bookings $adapter, array $input): RoomAccessResponse
{
    $_POST = $_REQUEST = $input;
    try {
        $adapter->bookingSubmitted();
    } catch (RoomAccessResponse $response) {
        return $response;
    }
    throw new RuntimeException('Submission did not produce a response.');
}

/** @param RoomAccessResponse $response Terminal response. @return void Ensure rejection has no write, mail or private detail. */
function roomAccessAssertDenied(RoomAccessResponse $response): void
{
    roomAccessCheck($response->status === 403, 'Protected booking must return HTTP 403.');
    roomAccessCheck(!$GLOBALS['room_access_inserts'] && Email::$sent === 0, 'Denied booking must not write a post or send mail.');
    roomAccessCheck(!str_contains($response->body, 'secret@example.invalid')
        && !str_contains($response->body, 'Private allowlist'), 'Private access diagnostics leaked into the response.');
}

foreach ([false, true] as $block) {
    foreach (['reservation', 'no-check', 'consultation', 'check-only'] as $mode) {
        roomAccessReset();
        IdM::$email = 'denied@example.invalid';
        LDAP::$authenticated = true;
        $GLOBALS['room_access_meta'][10]['rrze-rsvp-room-bookingmode'] = $mode;
        $input = roomAccessInput($block);
        $input['rsvp_email'] = 'allowed@example.invalid';
        $input['rsvp_instant'] = '1';
        roomAccessAssertDenied(roomAccessSubmit(roomAccessAdapter(), $input));
        roomAccessCheck(RoomAccess::$calls[0][1] === ['source' => 'sso', 'email' => IdM::$email],
            'SSO must win over both the allowed LDAP identity and forged POST email.');
    }

    roomAccessReset();
    $response = roomAccessSubmit(roomAccessAdapter(), roomAccessInput($block));
    roomAccessCheck($response->status === 302 && count($GLOBALS['room_access_inserts']) === 1 && Email::$sent === 1,
        'An allowed SSO identity must complete a booking and its normal notification.');
    $insert = $GLOBALS['room_access_inserts'][0];
    roomAccessCheck($insert['meta_input']['rrze-rsvp-booking-guest-email'] === Functions::crypt('allowed@example.invalid'),
        'Stored booking email must come from SSO, never the posted mailbox.');
    roomAccessCheck(count(RoomAccess::$calls) === 2 && RoomAccess::$calls[0] === RoomAccess::$calls[1],
        'Both the adapter and final writer must authorize the server-resolved identity.');

    roomAccessReset();
    IdM::$authenticated = false;
    LDAP::$authenticated = true;
    $GLOBALS['room_access_meta'][10]['rrze-rsvp-room-sso-required'] = '';
    $GLOBALS['room_access_meta'][10]['rrze-rsvp-room-ldap-required'] = 'on';
    $response = roomAccessSubmit(roomAccessAdapter(), roomAccessInput($block));
    roomAccessCheck($response->status === 302 && RoomAccess::$calls[0][1]['source'] === 'ldap',
        'A verified LDAP identity must be passed through both booking paths.');

    roomAccessReset();
    LDAP::$authenticated = true;
    LDAP::$email = 'denied@example.invalid';
    $GLOBALS['room_access_meta'][10]['rrze-rsvp-room-sso-required'] = '';
    $GLOBALS['room_access_meta'][10]['rrze-rsvp-room-ldap-required'] = 'on';
    $response = roomAccessSubmit(roomAccessAdapter(), roomAccessInput($block));
    roomAccessCheck($response->status === 302 && RoomAccess::$calls[0][1] === ['source' => 'sso', 'email' => IdM::$email],
        'An LDAP-required room with both sessions must authorize the higher-priority allowed SSO identity.');
    roomAccessCheck($GLOBALS['room_access_inserts'][0]['meta_input']['rrze-rsvp-booking-guest-email']
        === Functions::crypt('allowed@example.invalid'), 'The LDAP room must retain its authoritative SSO booking email.');

    roomAccessReset();
    IdM::$email = 'denied@example.invalid';
    LDAP::$authenticated = true;
    $GLOBALS['room_access_meta'][10]['rrze-rsvp-room-sso-required'] = '';
    $GLOBALS['room_access_meta'][10]['rrze-rsvp-room-ldap-required'] = 'on';
    roomAccessAssertDenied(roomAccessSubmit(roomAccessAdapter(), roomAccessInput($block)));
    roomAccessCheck(RoomAccess::$calls[0][1] === ['source' => 'sso', 'email' => IdM::$email],
        'An allowed LDAP identity must not replace denied SSO identity in an LDAP-required room.');
}

roomAccessReset();
IdM::$authenticated = false;
$GLOBALS['room_access_meta'][10]['rrze-rsvp-room-sso-required'] = '';
$input = roomAccessInput();
$input['rsvp_email'] = 'allowed@example.invalid';
roomAccessAssertDenied(roomAccessSubmit(roomAccessAdapter(), $input));
roomAccessCheck(RoomAccess::$calls[0][1] === null, 'Anonymous form data cannot become a trusted identity.');

foreach (['disabled', 'empty'] as $openPolicy) {
    roomAccessReset();
    IdM::$authenticated = false;
    $GLOBALS['room_access_meta'][10]['rrze-rsvp-room-sso-required'] = '';
    if ($openPolicy === 'disabled') RoomAccess::$enabled = false;
    else RoomAccess::$decision = /** @return true An empty list imposes no identity restriction. */ static fn(): true => true;
    $response = roomAccessSubmit(roomAccessAdapter(), roomAccessInput());
    roomAccessCheck($response->status === 302 && count($GLOBALS['room_access_inserts']) === 1,
        'Disabled enforcement and an empty list must preserve anonymous room booking.');
}

roomAccessReset();
RoomSlots::$flexible = true;
RoomSlots::$duringAvailability = /** @return void Simulate removal of the caller between form validation and insertion. */
    static function (): void {
        RoomAccess::$decision = /** @return WP_Error Revoked permission. */
            static fn(): WP_Error => new WP_Error('room_access_denied', 'Private allowlist diagnostic: secret@example.invalid');
    };
$input = roomAccessInput(true);
$input['rsvp_duration'] = '30';
roomAccessAssertDenied(roomAccessSubmit(roomAccessAdapter(), $input));
roomAccessCheck(count(RoomAccess::$calls) === 2 && $GLOBALS['wpdb']->released === 1,
    'Final authorization must reject a revoked grant and release the reservation lock.');

roomAccessReset();
RoomAccess::$decision = /** @return WP_Error Unreadable storage must not disclose its diagnostic or become public access. */
    static fn(): WP_Error => new WP_Error('room_access_unavailable', 'Private allowlist diagnostic: secret@example.invalid');
roomAccessAssertDenied(roomAccessSubmit(roomAccessAdapter(), roomAccessInput()));

foreach ([false, true] as $block) {
    roomAccessReset();
    IdM::$email = 'denied@example.invalid';
    $adapter = roomAccessAdapter();
    $html = $block ? $adapter->renderBlock(new Context()) : $adapter->shortcodeBooking(['room' => 10]);
    roomAccessCheck(str_contains($html, 'role="alert"') && !str_contains($html, '<form')
        && !str_contains($html, 'secret@example.invalid'), 'Denied identities must not receive booking forms or access-list data.');

    roomAccessReset();
    RoomSlots::$stopRendering = true;
    $adapter = roomAccessAdapter();
    try {
        $block ? $adapter->renderBlock(new Context()) : $adapter->shortcodeBooking(['room' => 10, 'days' => '']);
        throw new RuntimeException('Allowed renderer did not reach availability.');
    } catch (RoomAccessReachedForm $expected) {}
}

roomAccessReset();
IdM::$authenticated = false;
$html = roomAccessAdapter()->shortcodeBooking(['room' => 10]);
roomAccessCheck(str_contains($html, 'Sign in to continue'), 'Anonymous users must retain the existing room login flow.');

foreach (['ajaxUpdateCalendar', 'ajaxUpdateForm'] as $method) {
    roomAccessReset();
    IdM::$email = 'denied@example.invalid';
    $_POST = ['room' => '10', 'nonce' => 'fixture'];
    try {
        roomAccessAdapter()->$method();
        throw new RuntimeException('Unauthorized AJAX returned a form fragment.');
    } catch (RoomAccessResponse $response) {
        roomAccessAssertDenied($response);
    }
}

roomAccessReset();
$result = RoomReservations::create(10, 20, '2026-11-19', '09:00', null, false,
    ['rrze-rsvp-booking-guest-email' => Functions::crypt('allowed@example.invalid')]);
roomAccessCheck(is_wp_error($result) && !$GLOBALS['room_access_inserts'] && $GLOBALS['wpdb']->released === 1,
    'Calling the writer directly without a verified identity must not bypass access restrictions.');

foreach ([false, true] as $block) {
    roomAccessReset();
    RoomAccess::$department = ['id' => 'c8585f41-29a7-482f-a427-b31c4b67503d', 'name' => 'Original Department'];
    RoomSlots::$duringAvailability = /** @return void Change assignment before the final access decision. */
        static function (): void {
            RoomAccess::$department = ['id' => 'eb177c45-97bd-47d0-a5d5-76506e157ee7', 'name' => 'Final Department'];
        };
    $input = roomAccessInput($block);
    $input['department'] = 'Forged Department';
    $input[BookingDepartment::META_KEY] = 'Forged snapshot';
    $response = roomAccessSubmit(roomAccessAdapter(), $input);
    roomAccessCheck($response->status === 302 && RoomAccess::$resolved === 1
        && BookingDepartment::$snapshots[101]['department'] === RoomAccess::$department,
        'Both public forms must freeze the department from their single final access decision.');
    roomAccessCheck(count($GLOBALS['room_access_publications']) === 1
        && !isset($GLOBALS['room_access_inserts'][0]['meta_input'][BookingDepartment::META_KEY]),
        'Posted department values must never become snapshot metadata.');

    roomAccessReset();
    RoomAccess::$department = ['id' => 'c8585f41-29a7-482f-a427-b31c4b67503d', 'name' => 'Original Department'];
    BookingDepartment::$fail = true;
    $response = roomAccessSubmit(roomAccessAdapter(), roomAccessInput($block));
    roomAccessCheck($response->status === 302 && str_contains($response->body, 'save_error')
        && $GLOBALS['room_access_deletions'] === [101] && !$GLOBALS['room_access_publications']
        && !isset($GLOBALS['room_access_meta'][101]), 'A failed snapshot must roll back its draft before publication.');
    roomAccessCheck(Email::$sent === 0 && !BookingDepartment::$snapshots,
        'Snapshot failure must not send booking mail or retain an assignment.');
}

roomAccessReset();
$result = RoomReservations::create(10, 20, '2026-11-19', '09:00', null, false,
    ['rrze-rsvp-booking-guest-email' => Functions::crypt('allowed@example.invalid'),
        BookingDepartment::META_KEY => 'Injected caller snapshot'],
    ['source' => 'sso', 'email' => 'allowed@example.invalid']);
roomAccessCheck(is_int($result) && !BookingDepartment::$snapshots
    && !isset($GLOBALS['room_access_inserts'][0]['meta_input'][BookingDepartment::META_KEY]),
    'No assignment must remain absent even when a direct caller supplies protected snapshot metadata.');

echo "room-access-bookings: all checks passed\n";
