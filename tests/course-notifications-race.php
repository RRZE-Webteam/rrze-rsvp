<?php

/**
 * Deterministic delivery-order and real advisory-lock tests with disposable fixtures.
 * Run: wp --skip-plugins --skip-themes --url=SITE eval-file tests/course-notifications-race.php
 * All mail is captured locally before transmission, including recursive service events.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/rrze-rsvp.php';
\RRZE\RSVP\plugin()->loaded();

use RRZE\RSVP\Courses\BookingService;
use RRZE\RSVP\Courses\Model;
use RRZE\RSVP\Courses\Notifications;
use RRZE\RSVP\Courses\PostTypes;
use RRZE\RSVP\Courses\Repository;

PostTypes::register();
(new \RRZE\RSVP\CPT\Bookings())->booking_post_type();
wp_set_current_user(1);
global $courseMailRaceMessages, $courseMailRaceBefore, $courseMailRaceDuring, $courseMailRaceEvents, $courseMailRaceCourse, $wpdb;
$courseMailRaceMessages = [];
$courseMailRaceBefore = '';
$courseMailRaceDuring = 0;
$courseMailRaceEvents = [];
$courseMailRaceCourse = 0;
remove_all_actions('rrze_rsvp_course_booking_event');
add_filter('pre_wp_mail', 'courseMailRaceCapture', PHP_INT_MAX, 2);
add_action('rrze_rsvp_course_booking_event', 'courseMailRaceInterleave', 9, 3);
$notifications = new Notifications(new \RRZE\RSVP\Email(), ['email_notification_if_new' => 'no', 'email_notification_if_cancel' => 'no']);
$notifications->onLoaded();

/**
 * Capture the calendar before the transport returns; optionally cancel inside wp_mail.
 *
 * @param mixed $previous Previous short-circuit result.
 * @param array $mail Mail arguments.
 * @return bool Always accept without transmitting.
 */
function courseMailRaceCapture($previous, array $mail): bool
{
    global $courseMailRaceMessages, $courseMailRaceDuring;
    $calendar = '';
    foreach ((array) $mail['attachments'] as $path) {
        if (is_file($path)) {
            $calendar .= file_get_contents($path);
        }
    }
    $courseMailRaceMessages[] = ['subject' => $mail['subject'], 'message' => $mail['message'], 'calendar' => $calendar];
    if ($courseMailRaceDuring && str_contains($calendar, 'METHOD:PUBLISH')) {
        $bookingId = $courseMailRaceDuring;
        $courseMailRaceDuring = 0;
        courseMailRaceSuccess((new BookingService())->cancel($bookingId));
    }
    return true;
}

/**
 * Emulate a competing committed command before the notification callback acquires its lock.
 *
 * @param string $event Domain event.
 * @param array $booking Private booking DTO supplied before listener interleaving.
 * @param array $tokens Ephemeral token version.
 * @return void
 */
function courseMailRaceInterleave(string $event, array $booking, array $tokens): void
{
    global $courseMailRaceBefore, $courseMailRaceEvents;
    $courseMailRaceEvents[] = compact('event', 'booking', 'tokens');
    if ($courseMailRaceBefore === 'cancel' && $event === 'confirmed') {
        $courseMailRaceBefore = '';
        courseMailRaceSuccess((new BookingService())->cancel($booking['id']));
    } elseif ($courseMailRaceBefore === 'resend' && $event === 'verification_required') {
        $courseMailRaceBefore = '';
        courseMailRaceSuccess((new BookingService())->resend($booking['id']));
    }
}

/**
 * @param bool $condition Expected truth.
 * @param string $message Invariant description.
 * @return void
 */
function courseMailRaceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param array|WP_Error $result Service response.
 * @return array Successful response.
 */
function courseMailRaceSuccess($result): array
{
    if (is_wp_error($result)) {
        throw new RuntimeException($result->get_error_code() . ': ' . $result->get_error_message());
    }
    return $result;
}

/**
 * Remove only tagged disposable test rows, bypassing retention exclusively for fixtures.
 *
 * @return void
 */
function courseMailRaceCleanup(): void
{
    global $wpdb, $courseMailRaceCourse;
    if (!is_int($courseMailRaceCourse) || !$courseMailRaceCourse
        || !str_starts_with((string) get_post_field('post_title', $courseMailRaceCourse), '[RSVP MAIL RACE TEST]')) {
        return;
    }
    $ids = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", Model::BOOKING_COURSE_META, (string) $courseMailRaceCourse));
    $ids = array_merge($ids, $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d", Model::DATE_TYPE, $courseMailRaceCourse)), [$courseMailRaceCourse]);
    foreach (array_unique($ids) as $id) {
        $wpdb->delete($wpdb->postmeta, ['post_id' => (int) $id], ['%d']);
        $wpdb->delete($wpdb->posts, ['ID' => (int) $id], ['%d']);
        clean_post_cache((int) $id);
    }
}

$service = new BookingService();
$repository = new Repository();
$blocker = null;
$lock = '';
try {
    $courseMailRaceCourse = wp_insert_post(['post_type' => Model::COURSE_TYPE, 'post_status' => 'publish', 'post_title' => '[RSVP MAIL RACE TEST] ' . wp_generate_uuid4()], true);
    courseMailRaceAssert(is_int($courseMailRaceCourse) && $courseMailRaceCourse > 0, 'Course fixture creation failed.');
    courseMailRaceSuccess($service->saveCourseSettings($courseMailRaceCourse, Model::defaults()));
    $date = courseMailRaceSuccess($service->saveOccurrence($courseMailRaceCourse, ['start' => time() + DAY_IN_SECONDS,
        'end' => time() + DAY_IN_SECONDS + 7200, 'location' => 'Mail race fixture', 'capacity' => 20]));
    $person = ['firstname' => 'Race', 'lastname' => 'Test', 'email' => 'cancel-first@example.invalid', 'consent' => true, 'locale' => 'en_US'];

    $cancelFirst = courseMailRaceSuccess($service->book($date['id'], $person, wp_generate_uuid4(), true));
    $courseMailRaceMessages = [];
    $courseMailRaceBefore = 'cancel';
    courseMailRaceSuccess($service->approve($cancelFirst['id']));
    courseMailRaceAssert($repository->getBooking($cancelFirst['id'])['status'] === 'cancelled', 'Concurrent cancellation is committed.');
    courseMailRaceAssert(count($courseMailRaceMessages) === 1 && $courseMailRaceMessages[0]['calendar'] === '', 'Cancellation winning before delivery suppresses the stale confirmation and sends no unnecessary calendar cancellation.');
    $staleConfirmedEvent = $courseMailRaceEvents[count($courseMailRaceEvents) - 2];
    $notifications->notify('confirmed', $repository->getBooking($cancelFirst['id']), $staleConfirmedEvent['tokens']);
    courseMailRaceAssert(count($courseMailRaceMessages) === 1, 'An old event paired with a fresh cancelled DTO must also be suppressed.');

    $confirmFirst = courseMailRaceSuccess($service->book($date['id'], array_merge($person, ['email' => 'confirm-first@example.invalid']), wp_generate_uuid4(), true));
    $courseMailRaceMessages = [];
    $courseMailRaceDuring = $confirmFirst['id'];
    courseMailRaceSuccess($service->approve($confirmFirst['id']));
    courseMailRaceAssert($repository->getBooking($confirmFirst['id'])['status'] === 'cancelled', 'Cancellation during the mail transport is committed without an open booking transaction.');
    courseMailRaceAssert(count($courseMailRaceMessages) === 2
        && str_contains($courseMailRaceMessages[0]['calendar'], 'METHOD:PUBLISH')
        && str_contains($courseMailRaceMessages[1]['calendar'], 'METHOD:CANCEL'), 'Reentrant cancellation must run after confirmation delivery records its calendar marker.');
    preg_match('/UID:([^\r\n]+)/', $courseMailRaceMessages[0]['calendar'], $firstUid);
    preg_match('/UID:([^\r\n]+)/', $courseMailRaceMessages[1]['calendar'], $secondUid);
    courseMailRaceAssert($firstUid[1] === $secondUid[1], 'Ordered confirmation and cancellation use the same UID.');

    $courseMailRaceMessages = [];
    $courseMailRaceBefore = 'resend';
    $resent = courseMailRaceSuccess($service->book($date['id'], array_merge($person, ['email' => 'rotated@example.invalid']), wp_generate_uuid4()));
    courseMailRaceAssert(count($courseMailRaceMessages) === 1, 'A newer resend suppresses the initial email carrying obsolete tokens even though status did not change.');
    $latestEvent = end($courseMailRaceEvents);
    courseMailRaceAssert(str_contains($courseMailRaceMessages[0]['message'], $latestEvent['tokens']['verify']), 'Only the latest verification token is mailed.');

    // A second real DB connection proves the lock is cross-connection, not just a PHP flag.
    $lock = (new ReflectionMethod($notifications, 'lockName'))->invoke($notifications, $resent['id']);
    $blocker = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    courseMailRaceAssert((string) $blocker->get_var($blocker->prepare('SELECT GET_LOCK(%s, 0)', $lock)) === '1', 'A second database connection must acquire the fixture mail lock.');
    $beforeBlocked = count($courseMailRaceMessages);
    courseMailRaceSuccess($service->resend($resent['id']));
    courseMailRaceAssert(count($courseMailRaceMessages) === $beforeBlocked
        && (bool) get_post_meta($resent['id'], Notifications::ERROR_META, true), 'Lock timeout sends no uncoordinated mail and leaves a resendable error.');
    courseMailRaceAssert($repository->getBooking($resent['id'])['status'] === 'booked', 'Mail contention must not undo a successful booking command.');
    $blocker->get_var($blocker->prepare('SELECT RELEASE_LOCK(%s)', $lock));
    $lock = '';
    courseMailRaceSuccess($service->resend($resent['id']));
    courseMailRaceAssert(count($courseMailRaceMessages) === $beforeBlocked + 1
        && !get_post_meta($resent['id'], Notifications::ERROR_META, true), 'Explicit resend recovers after the competing delivery releases its lock.');
    echo "Course mail race tests passed (cancel-first, confirm-first, stale token, real lock contention).\n";
} finally {
    if ($blocker !== null && $lock !== '') {
        $blocker->get_var($blocker->prepare('SELECT RELEASE_LOCK(%s)', $lock));
    }
    if ($blocker !== null) {
        $blocker->close();
    }
    courseMailRaceCleanup();
    remove_filter('pre_wp_mail', 'courseMailRaceCapture', PHP_INT_MAX);
}
