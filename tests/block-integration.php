<?php
/**
 * Local WordPress integration of block forms, routing and private editor readers.
 * Run explicitly: wp --skip-plugins --skip-themes eval-file tests/block-integration.php
 * Creates only synthetic fixtures and removes them in finally; all mail is intercepted.
 */

use RRZE\RSVP\Blocks\{BookingBlock, Context, Editor};
use RRZE\RSVP\Courses\{Model, PostTypes};
use RRZE\RSVP\Shortcodes\Bookings;
use RRZE\RSVP\{Functions, ICS, RoomSlots, Settings};

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this integration test through WP-CLI.');
}
if (!defined('DOING_AJAX')) define('DOING_AJAX', true);
require_once dirname(__DIR__) . '/rrze-rsvp.php';
RRZE\RSVP\plugin()->loaded();
(new RRZE\RSVP\CPT\Rooms())->room_post_type();
(new RRZE\RSVP\CPT\Seats())->seats_post_type();
(new RRZE\RSVP\CPT\Bookings())->booking_post_type();
PostTypes::register();
$adapter = new Bookings(new Settings(dirname(__DIR__) . '/rrze-rsvp.php'));
$block = new BookingBlock();
$block->onLoaded($adapter);
$block->register();
(new RRZE\RSVP\Courses\Frontend())->registerBlock();
$editor = new Editor();
rest_get_server();
$GLOBALS['ids'] = [];
$ids =& $GLOBALS['ids'];
$GLOBALS['mails'] = 0;
$mails =& $GLOBALS['mails'];
$_SESSION = [];
$originalUser = get_current_user_id();

/** @param mixed $condition Expected truth. @param string $message Failure context. @return void */
function blockCheck($condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
/** @param string $type Post type. @param string $title Synthetic label. @param array $extra Optional post fields. @return int Created ID. */
function blockFixture(string $type, string $title, array $extra = []): int
{
    global $ids;
    $id = wp_insert_post(array_merge(['post_type' => $type, 'post_title' => $title, 'post_status' => 'publish'], $extra), true);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    $ids[] = $id;
    return $id;
}
/** @param mixed $return Existing mail override. @param array $mail Outgoing message. @return bool Capture mail locally. */
function blockInterceptMail($return, array $mail): bool
{
    $GLOBALS['mails']++;
    $GLOBALS['blockTestMessages'][] = $mail['message'];
    return true;
}
/** Result captured before a redirect or wp_die can terminate the integration process. */
final class BlockResponse extends RuntimeException
{
    /** @param string $result Redirect URL or error text. */
    public function __construct(public string $result) { parent::__construct($result); }
}
/** @param string $url Redirect target. @return never Capture the completed booking response. */
function blockRedirect(string $url): never { throw new BlockResponse($url); }
/** @return callable Throwing error handler used only during this test. */
function blockDieHandler(): callable
{
    return 'blockDie';
}
/** @param mixed $message Rejection message. @return never Capture rejection instead of exiting WP-CLI. */
function blockDie($message): never { throw new BlockResponse((string) $message); }
/** @param Bookings $adapter Room adapter. @param array $input Simulated form fields. @return string Response. */
function blockSubmit(Bookings $adapter, array $input): string
{
    $_POST = $_REQUEST = $input;
    try {
        $adapter->bookingSubmitted();
    } catch (BlockResponse $response) {
        return $response->result;
    }
    throw new RuntimeException('Expected a submission result.');
}
/** @param Bookings $adapter Adapter. @param string $method AJAX handler. @param array $input Request fields. @return string Captured fragment/JSON. */
function blockAjax(Bookings $adapter, string $method, array $input): string
{
    $_POST = $_REQUEST = $input;
    ob_start();
    try {
        $adapter->$method();
    } catch (BlockResponse $response) {
        return (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
    throw new RuntimeException('Expected AJAX termination.');
}
add_filter('pre_wp_mail', 'blockInterceptMail', 10, 2);
add_filter('wp_redirect', 'blockRedirect', -100);
add_filter('wp_die_handler', 'blockDieHandler');
add_filter('wp_die_ajax_handler', 'blockDieHandler');
try {
    $page = blockFixture('page', 'RSVP synthetic integration source', ['post_status' => 'draft']);
    $room = blockFixture('room', 'RSVP synthetic test room');
    $other = blockFixture('room', 'RSVP synthetic second room');
    foreach ([$room, $other] as $id) {
        update_post_meta($id, 'rrze-rsvp-room-bookingmode', 'reservation');
        update_post_meta($id, 'rrze-rsvp-room-days-in-advance', 30);
        update_post_meta($id, 'rrze-rsvp-room-auto-confirmation', 'on');
        update_post_meta($id, 'rrze-rsvp-room-timeslots', [[
            'rrze-rsvp-room-weekday' => ['1','2','3','4','5','6','7'],
            'rrze-rsvp-room-starttime' => '09:00', 'rrze-rsvp-room-endtime' => '12:00',
        ]]);
    }
    $seat = blockFixture('seat', 'Synthetic seat A');
    $foreignSeat = blockFixture('seat', 'Synthetic seat B');
    update_post_meta($seat, 'rrze-rsvp-seat-room', $room);
    update_post_meta($foreignSeat, 'rrze-rsvp-seat-room', $other);
    $course = blockFixture(Model::COURSE_TYPE, 'Synthetic editor course');
    $dateId = blockFixture(Model::DATE_TYPE, 'Synthetic course date', ['post_parent' => $course]);
    update_post_meta($dateId, Model::OCCURRENCE_META, ['start' => time() + 86400, 'end' => time() + 90000, 'capacity' => 3, 'location' => 'Synthetic campus']);
    $GLOBALS['post'] = get_post($page);
    $GLOBALS['wp_query']->queried_object = $GLOBALS['post'];
    $GLOBALS['wp_query']->queried_object_id = $page;
    $_GET = $_POST = $_REQUEST = [];
    wp_set_current_user(0);
    wp_update_post(['ID' => $course, 'post_password' => 'fixture-only']);
    $frontend = new \RRZE\RSVP\Courses\Frontend();
    $request = new WP_REST_Request();
    $request->set_param('id', $course);
    $request->set_param('occurrenceId', $dateId);
    $listing = $frontend->occurrences($request);
    $submission = $frontend->book($request);
    blockCheck(is_wp_error($listing) && $listing->get_error_data()['status'] === 404
        && is_wp_error($submission) && $submission->get_error_data()['status'] === 404,
        'Public course endpoints must treat a protected course as unavailable.');
    foreach ([$frontend->render(['courseId' => $course]), BookingBlock::render(['mode' => 'course', 'resourceId' => $course])] as $hiddenOffer) {
        blockCheck(!str_contains($hiddenOffer, 'Synthetic editor course') && !str_contains($hiddenOffer, 'Synthetic campus')
            && !str_contains($hiddenOffer, '<form'), 'Both booking blocks must hide protected course details and registration.');
    }
    wp_update_post(['ID' => $course, 'post_password' => '']);
    blockCheck(count($frontend->occurrences($request)->get_data()['occurrences']) === 1,
        'Removing the password restores the published occurrence.');
    $attrs = ['mode' => 'seat', 'resourceId' => $room, 'instanceId' => 'f340e368-3e85-4567-a456-426614174000'];
    $first = Context::create($attrs, $page);
    $second = Context::create($attrs, $page);
    $mixed = Context::create(array_merge($attrs, ['resourceId' => $other]), $page);
    $_GET = ['room_id' => $other];
    $html = $adapter->renderBlock($first) . $adapter->renderBlock($second) . $adapter->renderBlock($mixed)
        . BookingBlock::render(['mode' => 'course', 'resourceId' => $course]) . BookingBlock::render(['mode' => 'course', 'resourceId' => $course]);
    $document = new DOMDocument();
    @$document->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($document);
    $domIds = [];
    foreach ($xpath->query('//*[@id]') as $element) {
        blockCheck(!isset($domIds[$element->getAttribute('id')]), 'Duplicate DOM ID: ' . $element->getAttribute('id'));
        $domIds[$element->getAttribute('id')] = true;
    }
    foreach ($xpath->query('//*[@for or @aria-describedby or @aria-labelledby]') as $element) {
        foreach (['for', 'aria-describedby', 'aria-labelledby'] as $name) {
            foreach (preg_split('/\s+/', trim($element->getAttribute($name)), -1, PREG_SPLIT_NO_EMPTY) as $reference) {
                blockCheck(isset($domIds[$reference]), 'Missing ID reference: ' . $reference);
            }
        }
    }
    blockCheck(substr_count($html, 'name="rsvp_room"') === 3, 'Three independent room forms');
    blockCheck(str_contains($adapter->renderBlock($first), 'value="' . $room . '" id="' . $first->prefix() . '-rsvp_room"'), 'GET room override ignored');
    blockCheck($mails === 0, 'Rendering sent mail');
    // Authorized passive previews use actual repositories; anonymous calls fail.
    $request = new WP_REST_Request('GET', '/rrze-rsvp/v1/editor/preview');
    $request->set_param('mode', 'course'); $request->set_param('id', $course);
    blockCheck(is_wp_error($editor->permission($request)), 'Anonymous editor access');
    $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
    blockCheck((bool) $admins, 'Need an existing administrator for read authorization tests');
    wp_set_current_user((int) $admins[0]);
    blockCheck($editor->permission($request) === true, 'Administrator resource access');
    $preview = $editor->preview($request);
    blockCheck($preview instanceof WP_REST_Response && $preview->get_data()['dates'][0]['remaining'] === 3, 'Real course capacity preview');
    $request->set_param('mode', 'seat'); $request->set_param('id', $room);
    blockCheck(count($editor->preview($request)->get_data()['times']) > 0, 'Real room availability preview');
    blockCheck($mails === 0, 'Preview sent mail');
    wp_set_current_user(0);
    $tomorrow = current_datetime()->modify('+1 day')->format('Y-m-d');
    $input = array_merge($first->fields(), [
        'rrze_rsvp_post_nonce_field' => wp_create_nonce($first->action() . ':submit'),
        'rsvp_room' => $room, 'rsvp_seat' => $seat, 'rsvp_date' => $tomorrow, 'rsvp_time' => '09:00',
        'rsvp_firstname' => 'Synthetic', 'rsvp_lastname' => 'Block Test', 'rsvp_email' => 'synthetic-block@example.invalid', 'rsvp_dsgvo' => '1',
    ]);
    $ajaxInput = array_merge($first->fields(), ['nonce' => wp_create_nonce('rsvp-ajax-nonce'),
        'room' => $room, 'date' => $tomorrow, 'time' => '09:00', 'seat' => $seat]);
    $ajax = json_decode(blockAjax($adapter, 'ajaxUpdateForm', $ajaxInput), true);
    blockCheck(str_contains($ajax['time'], $first->prefix() . '-rsvp_time_'), 'AJAX lost time namespace');
    blockCheck(str_contains($ajax['seat'], $first->prefix() . '-rsvp_seat_'), 'AJAX lost seat namespace');
    $outside = json_decode(blockAjax($adapter, 'ajaxUpdateForm', array_merge($ajaxInput,
        ['date' => current_datetime()->modify('+40 days')->format('Y-m-d')])), true);
    blockCheck(!str_contains($outside['time'], 'type="radio"'), 'AJAX trusts a date outside the horizon');
    blockCheck(blockAjax($adapter, 'ajaxUpdateForm', array_merge($ajaxInput, ['room' => $other])) === '', 'AJAX accepted foreign room');
    $calendar = blockAjax($adapter, 'ajaxUpdateCalendar', array_merge($ajaxInput, [
        'month' => current_datetime()->format('Y-m'), 'direction' => 'next', 'end' => '2099-12-31']));
    blockCheck(str_contains($calendar, 'data-end="' . current_datetime()->modify('+30 days')->format('Y-m-d') . '"'), 'Calendar trusts caller horizon');
    $errorUrl = blockSubmit($adapter, array_merge($input, ['rsvp_lastname' => '']));
    parse_str((string) wp_parse_url($errorUrl, PHP_URL_QUERY), $errors);
    blockCheck(isset($errors['transient-data']), 'Expected validation feedback');
    $_POST = []; $_GET = $errors;
    $adapter->prepareBlockRequest();
    blockCheck(str_contains($adapter->renderBlock($first), __('Your last name is required.', 'rrze-rsvp')), 'Target feedback missing');
    blockCheck(!str_contains($adapter->renderBlock($second), __('Your last name is required.', 'rrze-rsvp')), 'Feedback leaked to duplicate');
    blockCheck(get_transient($errors['transient-data']) === false, 'Feedback not consumed before rendering');
    $_GET = [];
    $before = (int) wp_count_posts('booking')->publish;
    $stripped = array_diff_key($input, $first->fields());
    blockCheck(!str_starts_with(blockSubmit($adapter, $stripped), 'http'), 'Stripped context was accepted as a legacy request');
    blockSubmit($adapter, array_merge($input, ['rsvp_seat' => $foreignSeat]));
    blockSubmit($adapter, array_merge($input, ['rsvp_resource' => $other]));
    blockSubmit($adapter, array_merge($input, ['rsvp_date' => current_datetime()->modify('+31 days')->format('Y-m-d')]));
    blockCheck((int) wp_count_posts('booking')->publish === $before && $mails === 0, 'Invalid submission wrote a booking or sent mail');
    $url = blockSubmit($adapter, $input);
    parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $result);
    $booking = (int) ($result['id'] ?? 0);
    if ($booking) $ids[] = $booking;
    blockCheck($booking > 0 && isset($result['rsvp_receipt']) && !isset($result['booking']), 'Bound receipt absent or legacy token exported');
    blockCheck(wp_parse_url($url, PHP_URL_FRAGMENT) === $first->prefix(), 'Wrong return fragment');
    blockCheck((int) get_post_meta($booking, 'rrze-rsvp-booking-end', true) > (int) get_post_meta($booking, 'rrze-rsvp-booking-start', true), 'Invalid interval saved');
    blockCheck($mails > 0, 'Expected intercepted notification');
    $_GET = $result; $_POST = [];
    $receipt = $adapter->renderBlock($first);
    blockCheck(str_contains($receipt, 'synthetic-block@example.invalid'), 'Addressed receipt missing');
    blockCheck(!str_contains($adapter->renderBlock($second), 'synthetic-block@example.invalid'), 'Receipt leaked to duplicate block');
    blockCheck(!str_contains($adapter->shortcodeBooking(['room' => $room]), 'synthetic-block@example.invalid'), 'Receipt leaked to legacy shortcode');
    $_GET['id'] = $foreignSeat;
    blockCheck(!str_contains($adapter->renderBlock($first), 'synthetic-block@example.invalid'), 'Modified receipt accepted');
    update_post_meta($booking, 'rrze-rsvp-booking-status', 'checked-in');
    $_GET = $result;
    $checkedInReceipt = $adapter->renderBlock($first);
    blockCheck(str_contains($checkedInReceipt, __('Check in has been completed.', 'rrze-rsvp'))
        && str_contains($checkedInReceipt, 'action=checkout'), 'Checked-in receipt lacks confirmation or checkout');
    $sent = $mails;
    blockSubmit($adapter, $input);
    blockCheck((int) wp_count_posts('booking')->publish === $before + 1 && $mails === $sent, 'Repeated submission created duplicate booking/mail');
    // Reuse the real form, AJAX entry point, booking writer and receipt with an independent flexible room.
    $_GET = $_POST = $_REQUEST = [];
    foreach ([RoomSlots::ENABLED => 'on', RoomSlots::STEP => 20, RoomSlots::MIN => 20, RoomSlots::MAX => 80] as $key => $value) {
        update_post_meta($other, $key, $value);
    }
    $flexInput = array_merge($ajaxInput, $mixed->fields(), ['room' => $other, 'seat' => $foreignSeat, 'time' => '09:20']);
    $choices = json_decode(blockAjax($adapter, 'ajaxUpdateForm', $flexInput + ['rsvp_duration' => '60']), true);
    foreach ([20, 40, 60, 80] as $minutes) {
        blockCheck(str_contains($choices['duration'], 'value="' . $minutes . '"'), 'Day lacks a valid flexible duration');
    }
    blockCheck(str_contains($choices['time'], '09:20 – 10:20') && str_contains($choices['seat'], 'checked'), 'One-hour slot and selected seat');
    $_GET = ['room_id' => $other, 'seat_id' => $foreignSeat, 'timeslot' => '09:20', 'bookingdate' => $tomorrow];
    $deepLink = $adapter->shortcodeBooking(['room' => $other]);
    blockCheck(str_contains($deepLink, 'value="20" selected') && str_contains($deepLink, '09:20 – 09:40')
        && str_contains($deepLink, 'value="' . $foreignSeat . '" name="rsvp_seat"'), 'Room link preselects minimum duration, start and seat');
    $_GET = [];
    $post = array_merge($input, $mixed->fields(), [
        'rrze_rsvp_post_nonce_field' => wp_create_nonce($mixed->action() . ':submit'),
        'rsvp_room' => $other, 'rsvp_seat' => $foreignSeat, 'rsvp_time' => '09:20', 'rsvp_duration' => '60',
        'rsvp_email' => 'flexible-test@example.invalid',
    ]);
    foreach (['', '30', '100'] as $invalid) {
        $url = blockSubmit($adapter, array_replace($post, ['rsvp_duration' => $invalid]));
        blockCheck(!str_contains($url, 'rsvp_receipt='), 'Invalid duration created a booking');
    }
    $url = blockSubmit($adapter, array_replace($post, ['rsvp_email' => $input['rsvp_email']]));
    blockCheck(!str_contains($url, 'rsvp_receipt='), 'An existing longer booking for this guest must exclude an enclosed booking on another seat');
    $GLOBALS['blockTestMessages'] = [];
    $url = blockSubmit($adapter, $post);
    parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $flexReceipt);
    $flexId = (int) ($flexReceipt['id'] ?? 0);
    blockCheck($flexId > 0, 'Could not book one hour');
    $ids[] = $flexId;
    $details = Functions::getBooking($flexId);
    blockCheck($details['start'] === strtotime("$tomorrow 09:20 UTC") && $details['end'] === strtotime("$tomorrow 10:20 UTC"),
        'Stored timestamps must reflect the selected hour');
    $_GET = $flexReceipt;
    $_POST = [];
    blockCheck(str_contains($adapter->renderBlock($mixed), $details['time']), 'Receipt shows the selected interval');
    blockCheck(str_contains(html_entity_decode(implode(' ', $GLOBALS['blockTestMessages'])), $details['time']),
        'Booking email shows the selected interval');
    $details['booking_mode'] = 'reservation';
    $ics = ICS::generate($details, 'fixture.ics');
    blockCheck(str_contains($ics, 'DTEND;TZID=Europe/Berlin:' . str_replace('-', '', $tomorrow) . 'T102000'), 'ICS ends at the selected time');
    $matrix = Functions::getRoomOccupancyDays($other, new DateTimeImmutable($tomorrow, wp_timezone()))->current();
    blockCheck($matrix[$foreignSeat] === ['09:00 - 09:20' => 'available', '09:20 - 10:20' => 'confirmed', '10:20 - 12:00' => 'available'],
        'Display divides the opening at actual booking boundaries');
    $_GET = $_POST = $_REQUEST = [];
    wp_set_current_user((int) $admins[0]);
    (new RRZE\RSVP\Actions())->onLoaded();
    // Under WP-CLI the plugin loads after init. Attach the same CMB2 saver as the booking editor.
    CMB2_Bootstrap_2110::initiate()->include_cmb();
    (new RRZE\RSVP\Metaboxes())->booking();
    $box = cmb2_get_metabox('rrze-rsvp-booking-details');
    $hook = new CMB2_Hookup($box);
    add_action('save_post', [$hook, 'save_post'], 10, 2);
    $draft = blockFixture('booking', 'Synthetic manual reservation', ['post_status' => 'draft']);
    $adminDate = gmdate('d.m.Y', strtotime($tomorrow));
    $_POST = $_REQUEST = [
        $box->nonce() => wp_create_nonce($box->nonce()), 'rrze-rsvp-booking-seat' => $foreignSeat,
        'rrze-rsvp-booking-start' => ['date' => $adminDate, 'time' => '09:40'],
        'rrze-rsvp-booking-end' => ['date' => $adminDate, 'time' => '12:00'],
        'rsvp_duration' => '40', 'rrze-rsvp-booking-status' => 'confirmed',
        'rrze-rsvp-booking-guest-firstname' => 'Synthetic', 'rrze-rsvp-booking-guest-lastname' => 'Manual',
        'rrze-rsvp-booking-guest-email' => 'manual-test@example.invalid',
    ];
    try {
        wp_update_post(['ID' => $draft, 'post_status' => 'publish']);
        throw new RuntimeException('The admin editor accepted an overlapping booking.');
    } catch (BlockResponse $rejection) {
        blockCheck(get_post_status($draft) === 'draft', 'Rejected manual booking was published');
    }
    $_POST['rrze-rsvp-booking-start']['time'] = '10:20';
    wp_update_post(['ID' => $draft, 'post_status' => 'publish']);
    blockCheck((int) get_post_meta($draft, 'rrze-rsvp-booking-end', true) === strtotime("$tomorrow 11:00 UTC")
        && get_post_meta($draft, 'rrze-rsvp-booking-flexible', true) === '1', 'Manual booking must use the authoritative end and policy');
    $_POST = $_REQUEST = [];
    $protected = Functions::getTimeslotBlockingBookings($other);
    $openings = get_post_meta($other, 'rrze-rsvp-room-timeslots', true);
    blockCheck(!Functions::getBookingsNotCoveredByTimeslots($openings, $protected), 'Flexible bookings are covered by their opening');
    $openings[0]['rrze-rsvp-room-starttime'] = '09:40';
    blockCheck(count(Functions::getBookingsNotCoveredByTimeslots($openings, $protected)) === 1,
        'The room editor protects the full reserved interval');
    $_POST = [RoomSlots::ENABLED => 'on', RoomSlots::MIN => '30', RoomSlots::MAX => '80', RoomSlots::STEP => '20'];
    try {
        wp_update_post(['ID' => $other]);
        throw new RuntimeException('Server accepted a duration not divisible by the interval.');
    } catch (BlockResponse $rejection) {
        blockCheck(get_post_meta($other, RoomSlots::MIN, true) === '20', 'Invalid settings changed the stored rule');
    }
    $_POST = $_REQUEST = [];
    update_post_meta($other, 'rrze-rsvp-room-bookingmode', 'consultation');
    $consultation = json_decode(blockAjax($adapter, 'ajaxUpdateForm', [
        'nonce' => wp_create_nonce('rsvp-ajax-nonce'), 'date' => $tomorrow, 'room' => $other, 'rsvp_duration' => 20,
    ]), true);
    blockCheck(isset($consultation['duration']) && !isset($consultation['seat']), 'Flexible consultations choose their seat automatically');
    update_post_meta($other, 'rrze-rsvp-room-bookingmode', 'check-only');
    blockCheck(RoomSlots::rule($other) === null, 'On-site-only rooms keep fixed times');
    update_post_meta($other, 'rrze-rsvp-room-bookingmode', 'reservation');
    $display = RRZE\RSVP\PublicDisplay::snapshot($other, 'occupancy_nextavailable', new DateTimeImmutable("$tomorrow 10:50", wp_timezone()), 0);
    blockCheck($display['start'] === '11:00' && $display['end'] === '11:20', 'Next available display uses a full bookable duration on the grid');
    delete_post_meta($other, RoomSlots::ENABLED);
    blockCheck(!Functions::getRoomAvailability($other, $tomorrow, $tomorrow), 'Switching back to fixed duration still respects a partial booking');
    // Pin the site's local clock to its morning half-day, so this works at any test run time.
    $zone = (int) gmdate('G') < 12 ? 'UTC' : 'Etc/GMT+12';
    $siteZone = /** @return string Site timezone for this on-site scenario. */
        static fn() => $zone;
    add_filter('pre_option_timezone_string', $siteZone);
    try {
        $now = current_time('timestamp');
        $today = gmdate('Y-m-d', $now);
        $grid = intdiv($now, 1200) * 1200;
        $starts = gmdate('H:i', $grid);
        update_post_meta($other, RoomSlots::ENABLED, 'on');
        update_post_meta($other, 'rrze-rsvp-room-instant-check-in', 'on');
        update_post_meta($other, 'rrze-rsvp-room-timeslots', [[
            'rrze-rsvp-room-weekday' => [gmdate('N', $now)],
            'rrze-rsvp-room-starttime' => '00:00', 'rrze-rsvp-room-endtime' => '23:59',
        ]]);
        $_GET = ['room_id' => $other, 'seat_id' => $foreignSeat, 'timeslot' => $starts,
            'bookingdate' => $today, 'instant' => '1', 'nonce' => wp_create_nonce('rsvp-availability')];
        $onsite = $adapter->shortcodeBooking(['room' => $other]);
        blockCheck(str_contains($onsite, $starts . ' – ' . gmdate('H:i', $grid + 2400)),
            'On-site form states the end of a 40-minute booking before submission');
        $legacyInput = array_diff_key($post, $mixed->fields());
        $url = blockSubmit($adapter, array_replace($legacyInput, ['rrze_rsvp_post_nonce_field' => wp_create_nonce('post_nonce'),
            'rsvp_date' => $today, 'rsvp_time' => $starts, 'rsvp_duration' => 40, 'rsvp_instant' => 1]));
        parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $onsiteResult);
        $onsiteId = (int) ($onsiteResult['id'] ?? 0);
        blockCheck($onsiteId > 0, 'On-site booking failed');
        $ids[] = $onsiteId;
        blockCheck(get_post_meta($onsiteId, 'rrze-rsvp-booking-status', true) === 'checked-in'
            && (int) get_post_meta($onsiteId, 'rrze-rsvp-booking-end', true) === $grid + 2400,
            'On-site booking must check in immediately and end at the displayed time');
    } finally {
        remove_filter('pre_option_timezone_string', $siteZone);
    }
    WP_CLI::success('Block integration passed, including flexible room forms, legacy links, manual reservations, protected schedules, receipts, mail, ICS and occupancy.');
} finally {
    remove_filter('wp_redirect', 'blockRedirect', -100);
    remove_filter('wp_die_handler', 'blockDieHandler');
    remove_filter('wp_die_ajax_handler', 'blockDieHandler');
    foreach (array_reverse($ids) as $id) wp_delete_post($id, true);
    remove_filter('pre_wp_mail', 'blockInterceptMail');
    wp_set_current_user($originalUser);
    $_GET = $_POST = $_REQUEST = [];
}
