<?php

/**
 * Captured-mail and token-page integration tests using disposable local WordPress fixtures.
 * Run: wp --skip-plugins --skip-themes --url=SITE eval-file tests/course-notifications-integration.php
 * Every wp_mail call is intercepted before transmission; fixtures are removed in finally.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/rrze-rsvp.php';
\RRZE\RSVP\plugin()->loaded();

use RRZE\RSVP\Courses\ActionPage;
use RRZE\RSVP\Courses\BookingService;
use RRZE\RSVP\Courses\Model;
use RRZE\RSVP\Courses\Notifications;
use RRZE\RSVP\Courses\PostTypes;
use RRZE\RSVP\Courses\Repository;

PostTypes::register();
(new \RRZE\RSVP\CPT\Bookings())->booking_post_type();
wp_set_current_user(1);

global $courseMailMessages, $courseMailEvents, $courseMailFailure, $courseMailCourse;
$courseMailMessages = [];
$courseMailEvents = [];
$courseMailFailure = false;
$courseMailCourse = 0;
remove_all_actions('rrze_rsvp_course_booking_event');
add_filter('pre_wp_mail', 'courseMailCapture', PHP_INT_MAX, 2);
add_action('rrze_rsvp_course_booking_event', 'courseMailEvent', 20, 3);
$mailTransport = new \RRZE\RSVP\Email();
(new Notifications($mailTransport, [
    'email_notification_email' => 'organizers@example.invalid',
    'email_notification_if_new' => 'yes',
    'email_notification_if_cancel' => 'yes',
    'email_sender_email' => 'sender@example.invalid',
]))->onLoaded();

/**
 * Capture mail and calendar files while guaranteeing that no test email leaves WordPress.
 *
 * @param mixed $pre Earlier mail short-circuit result.
 * @param array $mail WordPress mail arguments.
 * @return bool Configured simulated transport result.
 * @throws RuntimeException Only when testing a transport exception.
 */
function courseMailCapture($pre, array $mail): bool
{
    global $courseMailMessages, $courseMailFailure;
    $mail['locale'] = get_locale();
    $mail['calendar'] = '';
    foreach ((array) $mail['attachments'] as $path) {
        if (is_string($path) && is_file($path)) {
            $mail['calendar'] .= file_get_contents($path);
        }
    }
    $courseMailMessages[] = $mail;
    if ($courseMailFailure === 'throw') {
        throw new RuntimeException('Synthetic mail transport exception.');
    }
    return !$courseMailFailure;
}

/**
 * Keep secrets in test memory only, to exercise the actual token boundary.
 *
 * @param string $event Domain event.
 * @param array $booking Private booking snapshot.
 * @param array $tokens Ephemeral tokens.
 * @return void
 */
function courseMailEvent(string $event, array $booking, array $tokens): void
{
    global $courseMailEvents;
    $courseMailEvents[] = compact('event', 'booking', 'tokens');
}

/**
 * @param bool $condition Expected truth.
 * @param string $message Failure explanation.
 * @return void
 */
function courseMailAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * @param array|WP_Error $result Service result.
 * @return array Successful result.
 */
function courseMailSuccess($result): array
{
    if (is_wp_error($result)) {
        throw new RuntimeException($result->get_error_code() . ': ' . $result->get_error_message());
    }
    return $result;
}

/**
 * Remove only this process's explicitly tagged course and its linked test records.
 * Direct fixture cleanup intentionally bypasses retention, never production data.
 *
 * @return void
 */
function courseMailCleanup(): void
{
    global $wpdb, $courseMailCourse;
    if (!is_int($courseMailCourse) || !$courseMailCourse || !str_starts_with((string) get_post_field('post_title', $courseMailCourse), '[RSVP MAIL TEST]')) {
        return;
    }
    $ids = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", Model::BOOKING_COURSE_META, (string) $courseMailCourse));
    $ids = array_merge($ids, $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d", Model::DATE_TYPE, $courseMailCourse)), [$courseMailCourse]);
    foreach (array_unique($ids) as $id) {
        $wpdb->delete($wpdb->postmeta, ['post_id' => (int) $id], ['%d']);
        $wpdb->delete($wpdb->posts, ['ID' => (int) $id], ['%d']);
        clean_post_cache((int) $id);
    }
}

$service = new BookingService();
$repository = new Repository();
$page = new ActionPage($service);
$originalLocale = get_locale();
try {
    $courseMailCourse = wp_insert_post(['post_type' => Model::COURSE_TYPE, 'post_status' => 'publish', 'post_title' => '[RSVP MAIL TEST] ' . wp_generate_uuid4()], true);
    courseMailAssert(is_int($courseMailCourse) && $courseMailCourse > 0, 'Cannot create test course.');
    courseMailSuccess($service->saveCourseSettings($courseMailCourse, Model::defaults()));
    $date = courseMailSuccess($service->saveOccurrence($courseMailCourse, ['start' => time() + 3 * DAY_IN_SECONDS,
        'end' => time() + 3 * DAY_IN_SECONDS + 7200, 'capacity' => 20, 'location' => 'Test, Building; Room A']));
    $person = ['firstname' => 'Mail', 'lastname' => 'Test', 'email' => 'course-test@example.invalid', 'consent' => true, 'locale' => 'en_US'];
    $request = wp_generate_uuid4();
    $booking = courseMailSuccess($service->book($date['id'], $person, $request));
    courseMailAssert(count($courseMailMessages) === 1, 'Unverified guests receive exactly one email and no organizer notification.');
    courseMailAssert($courseMailMessages[0]['locale'] === 'en_US' && get_locale() === $originalLocale, 'Mail must use and restore the booking locale.');
    $tokens = end($courseMailEvents)['tokens'];
    $arguments = ['action' => 'verify', 'bookingId' => (string) $booking['id'], 'token' => $tokens['verify']];
    $form = $page->render('GET', $arguments);
    courseMailAssert(str_contains($form, 'method="post"') && !str_contains($form, $person['email']), 'GET displays only a generic explicit confirmation form.');
    courseMailAssert($repository->getBooking($booking['id'])['status'] === 'booked' && count($courseMailMessages) === 1, 'Opening the mail link must not verify or send another email.');
    $page->render('POST', ['action' => 'verify', 'bookingId' => $booking['id'], 'token' => $tokens['cancel']]);
    courseMailAssert($repository->getBooking($booking['id'])['status'] === 'booked', 'Cancellation tokens cannot verify email addresses.');
    $page->render('POST', $arguments);
    courseMailAssert($repository->getBooking($booking['id'])['status'] === 'customer-confirmed' && count($courseMailMessages) === 3, 'Verification sends one pending-approval message and one organizer notice.');
    $page->render('POST', $arguments);
    courseMailSuccess($service->book($date['id'], $person, $request));
    courseMailAssert(count($courseMailMessages) === 3, 'Verification replay and request replay must not send duplicate notifications.');
    courseMailSuccess($service->approve($booking['id']));
    courseMailAssert(count($courseMailMessages) === 4, 'Manual approval sends the participant confirmation without repeating the new-registration organizer mail.');
    $confirmation = end($courseMailMessages);
    courseMailAssert(str_contains($confirmation['calendar'], 'METHOD:PUBLISH') && str_contains($confirmation['calendar'], 'ORGANIZER:mailto:sender@example.invalid'), 'Confirmed booking gets a complete calendar attachment.');
    $cancelToken = end($courseMailEvents)['tokens']['cancel'];
    $page->render('POST', ['action' => 'cancel', 'bookingId' => $booking['id'], 'token' => $cancelToken]);
    courseMailAssert($repository->getBooking($booking['id'])['status'] === 'cancelled' && count($courseMailMessages) === 6, 'Self-cancellation sends participant and organizer notices.');
    $cancellation = $courseMailMessages[4];
    preg_match('/UID:([^\r\n]+)/', $confirmation['calendar'], $confirmedUid);
    preg_match('/UID:([^\r\n]+)/', $cancellation['calendar'], $cancelledUid);
    courseMailAssert($confirmedUid[1] === $cancelledUid[1] && str_contains($cancellation['calendar'], 'METHOD:CANCEL'), 'Cancellation reuses the confirmation UID.');
    foreach (array_merge((array) $confirmation['attachments'], (array) $cancellation['attachments']) as $attachment) {
        courseMailAssert(!is_file($attachment), 'Temporary calendar attachment must be deleted after transport returns.');
    }
    $page->render('POST', ['action' => 'cancel', 'bookingId' => $booking['id'], 'token' => $cancelToken]);
    courseMailAssert(count($courseMailMessages) === 6, 'Cancellation replay must not send duplicate mail.');

    // A failed transmission leaves the committed hold intact and an explicit resend retries it.
    $courseMailFailure = true;
    $failed = courseMailSuccess($service->book($date['id'], array_merge($person, ['email' => 'retry@example.invalid', 'locale' => 'de_DE']), wp_generate_uuid4()));
    courseMailAssert($repository->getBooking($failed['id'])['status'] === 'booked', 'Transport failure must not undo a committed reservation.');
    courseMailAssert((bool) get_post_meta($failed['id'], Notifications::ERROR_META, true), 'Failed transport must be visible in administration.');
    $expiry = $failed['expires_at'];
    $oldVerify = end($courseMailEvents)['tokens']['verify'];
    $courseMailFailure = false;
    courseMailSuccess($service->resend($failed['id']));
    courseMailAssert(!get_post_meta($failed['id'], Notifications::ERROR_META, true), 'Successful resend clears the delivery error.');
    courseMailAssert($repository->getBooking($failed['id'])['expires_at'] === $expiry, 'Resending must not extend the reservation deadline.');
    courseMailAssert(is_wp_error($service->verify($failed['id'], $oldVerify)), 'Resend rotates the old verification token.');
    courseMailAssert(get_locale() === $originalLocale, 'German mail also restores the original locale.');
    courseMailSuccess($service->cancel($failed['id'], 'rejected'));
    $unverifiedCancellation = $courseMailMessages[count($courseMailMessages) - 2];
    courseMailAssert($unverifiedCancellation['calendar'] === '', 'Never-confirmed bookings must not send calendar cancellations.');

    $courseMailFailure = 'throw';
    $exceptionBooking = courseMailSuccess($service->book($date['id'], array_merge($person, ['email' => 'transport-exception@example.invalid']), wp_generate_uuid4()));
    courseMailAssert($repository->getBooking($exceptionBooking['id'])['status'] === 'booked'
        && (bool) get_post_meta($exceptionBooking['id'], Notifications::ERROR_META, true), 'Transport exceptions also preserve the reservation and create a visible error.');
    courseMailAssert(get_locale() === $originalLocale, 'A throwing transport must restore the caller locale.');
    $courseMailFailure = false;
    courseMailSuccess($service->resend($exceptionBooking['id']));
    courseMailAssert(!get_post_meta($exceptionBooking['id'], Notifications::ERROR_META, true), 'A resend can recover after a transport exception.');

    // SSO and automatic confirmation use the same transport, with no verification email.
    courseMailSuccess($service->saveCourseSettings($courseMailCourse, array_merge(Model::defaults(), ['auto_confirm' => true])));
    $beforeSso = count($courseMailMessages);
    $sso = courseMailSuccess($service->book($date['id'], array_merge($person, ['email' => 'sso@example.invalid']), wp_generate_uuid4(), true));
    courseMailAssert($sso['status'] === 'confirmed' && count($courseMailMessages) === $beforeSso + 2, 'Automatic SSO registration sends confirmation and organizer mail directly.');
    courseMailAssert(str_contains($courseMailMessages[$beforeSso]['calendar'], 'METHOD:PUBLISH'), 'Automatic SSO confirmation attaches the same calendar format.');
    $guest = courseMailSuccess($service->book($date['id'], array_merge($person, ['email' => 'automatic-guest@example.invalid']), wp_generate_uuid4()));
    $guestTokens = end($courseMailEvents)['tokens'];
    $autoVerified = courseMailSuccess($service->verify($guest['id'], $guestTokens['verify']));
    courseMailAssert($autoVerified['status'] === 'confirmed', 'Automatic guest confirmation follows email verification.');
    foreach ($courseMailMessages as $mail) {
        courseMailAssert(str_contains($mail['subject'], '[RSVP MAIL TEST]'), 'Every subject, including organizer mail, must identify the course.');
    }
    courseMailAssert(get_locale() === $originalLocale, 'The complete notification suite must preserve the calling locale.');
    courseMailAssert(has_filter('wp_mail_from', [$mailTransport, 'filterEmail']) === false
        && has_filter('wp_mail_from_name', [$mailTransport, 'filterName']) === false, 'Transport sender filters do not remain installed, including after exceptions.');
    echo "Course notification integration tests passed (captured mail only, no delivery).\n";
} finally {
    courseMailCleanup();
    remove_filter('pre_wp_mail', 'courseMailCapture', PHP_INT_MAX);
}
