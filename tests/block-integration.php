<?php
/**
 * Local WordPress integration of block forms, routing and private editor readers.
 * Run explicitly: wp --skip-plugins --skip-themes eval-file tests/block-integration.php
 * Creates only synthetic fixtures and removes them in finally; all mail is intercepted.
 */

use RRZE\RSVP\Blocks\{BookingBlock, Context, Editor};
use RRZE\RSVP\Courses\{Model, PostTypes};
use RRZE\RSVP\Shortcodes\Bookings;
use RRZE\RSVP\Settings;

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
/** @param mixed $return Existing mail override. @return bool Stop transport, including admin notifications. */
function blockInterceptMail($return): bool
{
    $GLOBALS['mails']++;
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
add_filter('pre_wp_mail', 'blockInterceptMail');
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
    WP_CLI::success('Block integration passed: real readers, mixed/duplicate IDs, bound submission, tampering, receipt isolation, retry, intercepted mail.');
} finally {
    remove_filter('wp_redirect', 'blockRedirect', -100);
    remove_filter('wp_die_handler', 'blockDieHandler');
    remove_filter('wp_die_ajax_handler', 'blockDieHandler');
    foreach (array_reverse($ids) as $id) wp_delete_post($id, true);
    remove_filter('pre_wp_mail', 'blockInterceptMail');
    wp_set_current_user($originalUser);
    $_GET = $_POST = $_REQUEST = [];
}
