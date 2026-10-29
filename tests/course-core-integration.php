<?php

/**
 * Disposable real-database course tests.
 *
 * Run with: wp --skip-plugins --skip-themes --url=SITE eval-file tests/course-core-integration.php
 * Additional modes: setup, worker OCCURRENCE_ID IDENTITY, cleanup COURSE_ID.
 * No email is transmitted; fixtures are removed by the default suite's finally block.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/rrze-rsvp.php';
\RRZE\RSVP\plugin()->loaded();

use RRZE\RSVP\Courses\BookingService;
use RRZE\RSVP\Courses\Frontend;
use RRZE\RSVP\Courses\Model;
use RRZE\RSVP\Courses\PostTypes;
use RRZE\RSVP\Courses\Repository;

PostTypes::register();
(new \RRZE\RSVP\CPT\Bookings())->booking_post_type();
wp_set_current_user(1);

global $wpdb, $courseTestEvents, $courseTestBreakWrite, $courseTestBreakRead, $courseTestIds;
$courseTestEvents = [];
$courseTestBreakWrite = false;
$courseTestBreakRead = '';
$courseTestIds = [];
add_filter('pre_wp_mail', 'courseTestSuppressMail', PHP_INT_MAX);
add_action('rrze_rsvp_course_booking_event', 'courseTestRecordEvent', 10, 3);
add_filter('query', 'courseTestBreakMetadataWrite');

/** @return bool Treat any mail as delivered without transmitting it. */
function courseTestSuppressMail(): bool
{
    return true;
}

/**
 * Capture post-commit events and secrets in test memory.
 *
 * @param string $event Domain event.
 * @param array $booking Private booking DTO.
 * @param array $tokens Ephemeral secrets.
 * @return void
 */
function courseTestRecordEvent(string $event, array $booking, array $tokens): void
{
    global $courseTestEvents;
    $courseTestEvents[] = compact('event', 'booking', 'tokens');
}

/**
 * Inject a real SQL failure on the next booking status metadata INSERT.
 *
 * @param string $query SQL about to be executed.
 * @return string Original SQL or intentionally invalid test statement.
 */
function courseTestBreakMetadataWrite(string $query): string
{
    global $courseTestBreakWrite, $courseTestBreakRead, $wpdb;
    $breakRead = ($courseTestBreakRead === 'occupancy' && str_contains($query, 'SELECT p.ID FROM') && str_contains($query, 'ORDER BY p.ID'))
        || ($courseTestBreakRead === 'retained' && str_contains($query, 'SELECT p.ID FROM') && str_contains($query, 'LIMIT 1'))
        || ($courseTestBreakRead === 'settings' && str_contains($query, 'SELECT meta_value') && str_contains($query, Model::COURSE_SETTINGS_META))
        || ($courseTestBreakRead === 'postcommit' && !BookingService::isWriting() && str_contains($query, 'SELECT meta_value') && str_contains($query, Model::BOOKING_DATA_META));
    if ($breakRead) {
        $courseTestBreakRead = '';
        return "SELECT course_test_missing_column FROM {$wpdb->posts}";
    }
    if ($courseTestBreakRead === 'delete_meta' && str_starts_with($query, 'DELETE FROM') && str_contains($query, $wpdb->postmeta)) {
        $courseTestBreakRead = '';
        return "DELETE FROM {$wpdb->postmeta} WHERE course_test_missing_column = 1";
    }
    if ($courseTestBreakWrite && str_starts_with($query, 'INSERT INTO')
        && str_contains($query, $wpdb->postmeta) && str_contains($query, Model::STATUS_META)) {
        $courseTestBreakWrite = false;
        return "INSERT INTO {$wpdb->postmeta} (course_test_missing_column) VALUES (1)";
    }
    return $query;
}

/**
 * Assert a condition while preserving readable CLI output.
 *
 * @param bool $condition Expected truth.
 * @param string $message Description of the invariant.
 * @return void
 */
function courseTestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Require a successful service result.
 *
 * @param mixed $result Service result.
 * @return array Successful DTO.
 */
function courseTestSuccess($result): array
{
    if (is_wp_error($result)) {
        throw new RuntimeException($result->get_error_code() . ': ' . $result->get_error_message());
    }
    return $result;
}

/**
 * Create an isolated course with one future date.
 *
 * @param BookingService $service Service instance.
 * @param int $capacity Participant limit.
 * @return array Course/date IDs and initial occurrence DTO.
 */
function courseTestFixture(BookingService $service, int $capacity = 20): array
{
    global $courseTestIds;
    $id = wp_insert_post(['post_type' => Model::COURSE_TYPE, 'post_status' => 'publish', 'post_title' => '[RSVP CORE TEST] ' . wp_generate_uuid4()], true);
    courseTestAssert(!is_wp_error($id) && $id > 0, 'Fixture course creation failed.');
    $courseTestIds[] = (int) $id;
    courseTestSuccess($service->saveCourseSettings((int) $id, Model::defaults()));
    $date = courseTestSuccess($service->saveOccurrence((int) $id, ['start' => time() + 3 * DAY_IN_SECONDS, 'end' => time() + 3 * DAY_IN_SECONDS + 7200,
        'capacity' => $capacity, 'location' => 'Course core test room']));
    return ['course_id' => (int) $id, 'occurrence_id' => $date['id'], 'date' => $date];
}

/**
 * Provide a synthetic participant that cannot receive mail.
 *
 * @param string $identity Unique identifier within the test.
 * @return array Participant form data.
 */
function courseTestPerson(string $identity): array
{
    return ['firstname' => 'Core', 'lastname' => 'Test', 'email' => $identity . '@example.invalid', 'consent' => true, 'locale' => 'de_DE'];
}

/**
 * Exercise optional domain restrictions through persistence, booking and the SSO adapter.
 *
 * @param BookingService $service Actual transactional booking service.
 * @param Repository $repository Actual course/booking readers.
 * @return void
 */
function courseTestEmailDomains(BookingService $service, Repository $repository): void
{
    global $courseTestEvents;
    $fixture = courseTestFixture($service, 6);
    $courseId = $fixture['course_id'];
    $dateId = $fixture['occurrence_id'];
    $saved = courseTestSuccess($service->saveCourseSettings($courseId, ['allowed_email_domains' => "@EXAMPLE.invalid, @second.invalid\nexample.invalid"]));
    courseTestAssert($saved['settings']['allowed_email_domains'] === ['example.invalid', 'second.invalid'], 'Settings persist a normalized, deduplicated domain list.');
    $invalid = $service->saveCourseSettings($courseId, ['allowed_email_domains' => 'example.invalid, *.second.invalid']);
    courseTestAssert(is_wp_error($invalid) && $invalid->get_error_code() === 'course_invalid_email_domains'
        && $repository->getCourse($courseId)['settings'] === $saved['settings'], 'Invalid input must preserve all previously saved settings.');

    $frontend = new class($repository, $service) extends Frontend {
        public array $serverIdentity = ['authenticated' => false, 'available' => false, 'participant' => []];

        /** @return array Synthetic server identity; never taken from submitted form data. */
        protected function identity(): array
        {
            return $this->serverIdentity;
        }
    };
    $html = $frontend->render(['courseId' => $courseId]);
    courseTestAssert(str_contains($html, '@example.invalid, @second.invalid')
        && preg_match('/<input[^>]+name="email"[^>]+aria-describedby="([^"]+)"/', $html, $description)
        && str_contains($html, 'id="' . $description[1] . '"'), 'Visitors see allowed domains and the email field references the notice for assistive technology.');
    $beforeEvents = count($courseTestEvents);
    foreach ([false, true] as $trusted) {
        $deniedPerson = array_merge(courseTestPerson('denied'), ['email' => 'denied@other.invalid']);
        $denied = $service->book($dateId, $deniedPerson, 'domain-denied-request-' . (int) $trusted, $trusted);
        courseTestAssert(is_wp_error($denied) && $denied->get_error_code() === 'course_email_domain_not_allowed', 'The service must reject other domains for guests and trusted SSO alike.');
    }
    $frontend->serverIdentity = ['authenticated' => true, 'available' => true, 'participant' => $deniedPerson];
    $request = new WP_REST_Request('POST');
    $request->set_body_params(['occurrenceId' => $dateId, 'requestId' => 'domain-spoof-request-1', 'participant' => courseTestPerson('spoof')]);
    $spoof = $frontend->book($request);
    courseTestAssert(is_wp_error($spoof) && $spoof->get_error_code() === 'course_email_domain_not_allowed', 'An allowed submitted email cannot override a disallowed verified SSO profile.');
    courseTestAssert($repository->getBookingsForOccurrence($dateId) === [] && count($courseTestEvents) === $beforeEvents
        && courseTestSuccess($service->availability($dateId))['free'] === 6, 'Rejected domains create no record, hold or notification.');

    $guest = courseTestSuccess($service->book($dateId, courseTestPerson('domain-guest'), 'domain-guest-request1'));
    $guestEvent = end($courseTestEvents);
    courseTestAssert($guest['status'] === 'booked', 'An allowed guest still has to verify ownership of the address.');
    $ssoPerson = array_merge(courseTestPerson('domain-sso'), ['email' => 'domain-sso@SECOND.invalid']);
    $frontend->serverIdentity['participant'] = $ssoPerson;
    $request->set_body_params(['occurrenceId' => $dateId, 'requestId' => 'domain-sso-request-01', 'participant' => $deniedPerson]);
    $sso = $frontend->book($request);
    courseTestAssert($sso instanceof WP_REST_Response && $sso->get_status() === 201
        && $sso->get_data()['status'] === 'customer-confirmed', 'An allowed SSO profile uses its own domain and the normal approval workflow.');

    courseTestSuccess($service->saveCourseSettings($courseId, ['allowed_email_domains' => ['second.invalid']]));
    $replay = courseTestSuccess($service->book($dateId, courseTestPerson('domain-guest'), 'domain-guest-request1'));
    courseTestAssert($replay['id'] === $guest['id'] && !$replay['created'], 'Policy changes must not break retries of existing registrations.');
    $verified = courseTestSuccess($service->verify($guest['id'], $guestEvent['tokens']['verify']));
    courseTestAssert($verified['status'] === 'customer-confirmed', 'An existing hold retains its original conditions after domains change.');
    $newAttempt = $service->book($dateId, courseTestPerson('domain-new'), 'domain-new-request01');
    courseTestAssert(is_wp_error($newAttempt) && $newAttempt->get_error_code() === 'course_email_domain_not_allowed', 'New registrations must use the newly saved restriction.');
    courseTestSuccess($service->saveCourseSettings($courseId, ['allowed_email_domains' => '']));
    courseTestSuccess($service->book($dateId, courseTestPerson('domain-new'), 'domain-new-request01'));
    $frontend->serverIdentity = ['authenticated' => false, 'available' => false, 'participant' => []];
    courseTestAssert(!str_contains($frontend->render(['courseId' => $courseId]), 'email-domains'), 'Clearing the optional list removes the frontend restriction notice.');
}

/**
 * Delete only tagged test courses and their directly referenced disposable fixtures.
 *
 * @param int $courseId Test course ID.
 * @return void
 */
function courseTestCleanup(int $courseId): void
{
    global $wpdb;
    $title = (string) get_post_field('post_title', $courseId);
    if (!str_starts_with($title, '[RSVP CORE TEST]')) {
        throw new RuntimeException('Refusing to clean up an untagged course.');
    }
    $bookings = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", Model::BOOKING_COURSE_META, (string) $courseId));
    foreach ($bookings as $id) {
        wp_delete_post((int) $id, true);
    }
    $dates = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d", Model::DATE_TYPE, $courseId));
    foreach ($dates as $id) {
        wp_delete_post((int) $id, true);
    }
    wp_delete_post($courseId, true);
}

$service = new BookingService();
$repository = new Repository();
$mode = $args[0] ?? 'suite';
if ($mode === 'setup') {
    $fixture = courseTestFixture($service, 1);
    echo wp_json_encode($fixture) . PHP_EOL;
    return;
}
/**
 * Keep the transactional lock briefly so parallel worker requests overlap in real SQL.
 *
 * @param string $operation Service operation name.
 * @return void
 */
function courseTestHoldLock(string $operation): void
{
    if ($operation === 'createBooking') {
        usleep(500000);
    }
}

if ($mode === 'worker') {
    // Exercise the same write guards as live requests in the two-process race.
    (new \RRZE\RSVP\Courses\Guards())->onLoaded();
    add_action('rrze_rsvp_course_before_commit', 'courseTestHoldLock');
    $id = (int) ($args[1] ?? 0);
    $identity = (string) ($args[2] ?? 'worker');
    $result = $service->book($id, courseTestPerson($identity), 'parallel-request-' . $identity, true);
    echo wp_json_encode(is_wp_error($result) ? ['error' => $result->get_error_code()] : $result) . PHP_EOL;
    return;
}
if ($mode === 'cleanup') {
    courseTestCleanup((int) ($args[1] ?? 0));
    echo "Disposable course fixtures removed.\n";
    return;
}

try {
    courseTestAssert($service->supportsTransactions(), 'Actual posts and postmeta tables must be InnoDB.');
    $fixture = courseTestFixture($service);
    $courseId = $fixture['course_id'];
    $dateId = $fixture['occurrence_id'];
    courseTestSuccess($service->saveCourseSettings($courseId, ['auto_confirm' => true]));
    for ($i = 0; $i < 14; $i++) {
        courseTestSuccess($service->book($dateId, courseTestPerson('confirmed-' . $i), 'request-confirmed-' . $i, true));
    }
    courseTestSuccess($service->saveCourseSettings($courseId, ['auto_confirm' => false]));
    for ($i = 0; $i < 4; $i++) {
        $pending = courseTestSuccess($service->book($dateId, courseTestPerson('pending-' . $i), 'request-pending-' . $i, true));
    }
    $availability = courseTestSuccess($service->availability($dateId));
    courseTestAssert($availability['confirmed'] === 14 && $availability['pending'] === 4 && $availability['free'] === 2, 'Real database occupancy must equal 14 + 4 + 2.');
    $eventCount = count($courseTestEvents);
    $replayed = courseTestSuccess($service->book($dateId, courseTestPerson('pending-3'), 'request-pending-3', true));
    courseTestAssert($replayed['id'] === $pending['id'] && count($courseTestEvents) === $eventCount, 'Retry must not create a new booking or notification.');
    $duplicate = $service->book($dateId, courseTestPerson('pending-3'), 'another-request-pending3', true);
    courseTestAssert(is_wp_error($duplicate) && $duplicate->get_error_code() === 'course_duplicate', 'A second active booking for the same email must be rejected.');
    $lower = $fixture['date'];
    $lower['capacity'] = 17;
    courseTestAssert(is_wp_error($service->saveOccurrence($courseId, $lower, $dateId)), 'Cannot lower limit below reserved places.');
    $changed = $fixture['date'];
    $changed['start'] += 60;
    courseTestAssert(is_wp_error($service->saveOccurrence($courseId, $changed, $dateId)), 'Cannot reschedule an occupied date.');
    courseTestAssert(is_wp_error($service->deleteCourse($courseId, true)), 'Retained bookings prevent course deletion.');
    courseTestAssert(is_wp_error($service->deleteOccurrence($dateId, true)), 'Retained bookings prevent date deletion.');
    courseTestSuccess($service->approve($pending['id']));
    courseTestAssert(courseTestSuccess($service->availability($dateId))['held'] === 18, 'Approval must not take another place.');
    courseTestSuccess($service->cancel($pending['id'], 'rejected'));
    courseTestAssert(courseTestSuccess($service->availability($dateId))['free'] === 3, 'Cancellation must release a place.');
    courseTestAssert(is_wp_error($service->deleteBooking($pending['id'])), 'Cancellation must not bypass retention.');
    $guest = courseTestSuccess($service->book($dateId, courseTestPerson('guest'), 'guest-request-0001'));
    $guestEvent = end($courseTestEvents);
    $guestData = $repository->meta($guest['id'], Model::BOOKING_DATA_META);
    courseTestAssert($guest['status'] === 'booked' && isset($guestEvent['tokens']['verify']) && !in_array($guestEvent['tokens']['verify'], $guestData, true), 'Guest verification secret is emitted but never stored in plaintext.');
    courseTestAssert($repository->getBooking($guest['id'])['locale'] === 'de_DE', 'Locale case must be preserved.');
    courseTestAssert(is_wp_error($service->verify($guest['id'], $guestEvent['tokens']['cancel'])), 'Tokens cannot be reused across purposes.');
    courseTestAssert(is_wp_error($service->approve($guest['id'])), 'An administrator cannot bypass email ownership verification.');
    $verified = courseTestSuccess($service->verify($guest['id'], $guestEvent['tokens']['verify']));
    courseTestAssert($verified['status'] === 'customer-confirmed', 'Verified guest waits for the snapshotted approval rule.');
    $eventCount = count($courseTestEvents);
    courseTestSuccess($service->verify($guest['id'], $guestEvent['tokens']['verify']));
    courseTestAssert(count($courseTestEvents) === $eventCount, 'Repeated verification must not emit a second email.');
    courseTestSuccess($service->resend($guest['id']));
    $resent = end($courseTestEvents);
    courseTestSuccess($service->cancel($guest['id'], 'customer', false, $resent['tokens']['cancel']));
    $expired = courseTestSuccess($service->book($dateId, courseTestPerson('expired'), 'expired-request-01'));
    $expiredEvent = end($courseTestEvents);
    $data = $repository->meta($expired['id'], Model::BOOKING_DATA_META);
    $data['expires_at'] = time() - 1;
    update_post_meta($expired['id'], Model::BOOKING_DATA_META, $data);
    $heldBeforeExpiry = courseTestSuccess($service->availability($dateId))['held'];
    courseTestAssert(is_wp_error($service->verify($expired['id'], $expiredEvent['tokens']['verify'])), 'Expired verification cannot reclaim a place.');
    // Exercise maintenance only on this fixture, never on unrelated local courses.
    courseTestSuccess((new ReflectionMethod(BookingService::class, 'forOccurrence'))->invoke($service, $dateId, 'expireBookings', []));
    courseTestAssert($repository->getBooking($expired['id'])['status'] === 'cancelled', 'Cron materializes expired status.');
    courseTestAssert(courseTestSuccess($service->availability($dateId))['held'] === $heldBeforeExpiry, 'Cron does not change capacity already freed by elapsed expiry.');
    $beforeIds = array_column($repository->getBookingsForOccurrence($dateId), 'id');
    $beforeEvents = count($courseTestEvents);
    $courseTestBreakWrite = true;
    $oldSuppression = $wpdb->suppress_errors(true);
    $failure = $service->book($dateId, courseTestPerson('rollback'), 'rollback-request-1', true);
    $wpdb->suppress_errors($oldSuppression);
    courseTestAssert(is_wp_error($failure) && $failure->get_error_code() === 'course_storage_error', 'Real SQL failure must surface as a service error.');
    courseTestAssert(array_column($repository->getBookingsForOccurrence($dateId), 'id') === $beforeIds && count($courseTestEvents) === $beforeEvents, 'Rollback must remove partial booking and suppress mail.');
    courseTestSuccess($service->cancelOccurrence($dateId));
    courseTestAssert(courseTestSuccess($service->availability($dateId))['reason'] === 'cancelled', 'Cancellation closes the date.');
    courseTestAssert(count($repository->getBookingsForOccurrence($dateId)) === count($beforeIds), 'Cancelled historical bookings stay retained.');
    $data = $repository->meta($guest['id'], Model::BOOKING_DATA_META);
    $data['snapshot']['end'] = time() - 91 * DAY_IN_SECONDS;
    update_post_meta($guest['id'], Model::BOOKING_DATA_META, $data);
    $courseTestBreakRead = 'delete_meta';
    $oldSuppression = $wpdb->suppress_errors(true);
    $failedPurge = (new ReflectionMethod(BookingService::class, 'forOccurrence'))->invoke($service, $dateId, 'purgeBookings', []);
    $wpdb->suppress_errors($oldSuppression);
    courseTestAssert(is_wp_error($failedPurge) && $repository->post($guest['id']) && $repository->meta($guest['id'], Model::BOOKING_DATA_META), 'A failed metadata DELETE must roll back the post deletion and retain tokens together.');
    courseTestSuccess((new ReflectionMethod(BookingService::class, 'forOccurrence'))->invoke($service, $dateId, 'purgeBookings', []));
    courseTestAssert(!$repository->post($guest['id']) && !$repository->meta($guest['id'], Model::BOOKING_DATA_META), 'Purge removes record and token/PII metadata after its retention deadline.');
    courseTestEmailDomains($service, $repository);
    $ssoFixture = courseTestFixture($service, 1);
    courseTestSuccess($service->saveCourseSettings($ssoFixture['course_id'], ['access' => 'sso', 'auto_confirm' => true]));
    courseTestAssert(is_wp_error($service->book($ssoFixture['occurrence_id'], courseTestPerson('no-sso'), 'no-sso-request-01')), 'Required SSO cannot fall back to guest identity.');
    $sso = courseTestSuccess($service->book($ssoFixture['occurrence_id'], courseTestPerson('yes-sso'), 'yes-sso-request-1', true));
    courseTestAssert($sso['status'] === 'confirmed', 'Trusted SSO permits immediate automatic approval.');
    $roomId = wp_insert_post(['post_type' => 'room', 'post_status' => 'publish', 'post_title' => '[RSVP CORE TEST] venue'], true);
    courseTestAssert(!is_wp_error($roomId) && $roomId > 0, 'Disposable room could not be created.');
    try {
        $venue = courseTestFixture($service, 1);
        $venueValues = $venue['date'];
        $venueValues['room_id'] = $roomId;
        $savedVenue = courseTestSuccess($service->saveOccurrence($venue['course_id'], $venueValues, $venue['occurrence_id']));
        courseTestSuccess($service->book($venue['occurrence_id'], courseTestPerson('venue'), 'venue-request-001', true));
        wp_update_post(['ID' => $roomId, 'post_status' => 'draft']);
        $venueValues['capacity'] = 2;
        $updatedVenue = courseTestSuccess($service->saveOccurrence($venue['course_id'], $venueValues, $venue['occurrence_id']));
        courseTestAssert($updatedVenue['capacity'] === 2 && $updatedVenue['location'] === $savedVenue['location'], 'An unchanged room venue must retain its snapshot and allow capacity edits after the room is unpublished.');
    } finally {
        wp_delete_post((int) $roomId, true);
    }
    foreach (['occupancy', 'settings'] as $readFailure) {
        $courseTestBreakRead = $readFailure;
        $oldSuppression = $wpdb->suppress_errors(true);
        $failedRead = $service->book($ssoFixture['occurrence_id'], courseTestPerson('read-failure'), 'read-failure-request1', true);
        $wpdb->suppress_errors($oldSuppression);
        courseTestAssert(is_wp_error($failedRead) && $failedRead->get_error_code() === 'course_storage_error', 'Failed occupancy/settings SELECT must abort registration, never use empty/default data.');
        courseTestAssert(courseTestSuccess($service->availability($ssoFixture['occurrence_id']))['held'] === 1, 'Failed read must not overbook a full date.');
    }
    $courseTestBreakRead = 'occupancy';
    $oldSuppression = $wpdb->suppress_errors(true);
    $failedAvailability = $service->availability($ssoFixture['occurrence_id']);
    $wpdb->suppress_errors($oldSuppression);
    courseTestAssert(is_wp_error($failedAvailability) && $failedAvailability->get_error_code() === 'course_storage_error', 'Public availability must return an error for a failed SELECT without a fatal exception.');
    $courseTestBreakRead = 'retained';
    $oldSuppression = $wpdb->suppress_errors(true);
    $failedDeletion = $service->deleteCourse($ssoFixture['course_id'], true);
    $wpdb->suppress_errors($oldSuppression);
    courseTestAssert(is_wp_error($failedDeletion) && $repository->post($ssoFixture['course_id']), 'Failed retained-booking lookup must not delete a referenced course.');
    $mailFixture = courseTestFixture($service, 1);
    $courseTestBreakRead = 'postcommit';
    $oldSuppression = $wpdb->suppress_errors(true);
    $beforeMailEvents = count($courseTestEvents);
    $mailFailure = courseTestSuccess($service->book($mailFixture['occurrence_id'], courseTestPerson('postcommit'), 'postcommit-request1', true));
    $wpdb->suppress_errors($oldSuppression);
    courseTestAssert($repository->getBooking($mailFailure['id'])['status'] === 'customer-confirmed', 'Post-commit read failures must preserve the successfully stored booking.');
    courseTestAssert(count($courseTestEvents) === $beforeMailEvents && get_post_meta($mailFailure['id'], 'rrze-rsvp-course-mail-error', true), 'Failed post-commit preparation must be visible as a resendable mail error.');
    // Move only this fixture into the past to verify the shared manager deadline.
    $pastDate = $repository->meta($ssoFixture['occurrence_id'], Model::OCCURRENCE_META);
    $pastDate['start'] = time() - 7200;
    $pastDate['end'] = time() - 3600;
    update_post_meta($ssoFixture['occurrence_id'], Model::OCCURRENCE_META, $pastDate);
    $pastBooking = $repository->meta($sso['id'], Model::BOOKING_DATA_META);
    $pastBooking['snapshot']['start'] = $pastDate['start'];
    $pastBooking['snapshot']['end'] = $pastDate['end'];
    update_post_meta($sso['id'], Model::BOOKING_DATA_META, $pastBooking);
    $lateCancel = $service->cancelOccurrence($ssoFixture['occurrence_id']);
    courseTestAssert(is_wp_error($lateCancel) && $lateCancel->get_error_code() === 'course_cancellation_closed', 'Cancelling an entire date must not bypass the staff cancellation deadline.');
    courseTestAssert($repository->getBooking($sso['id'])['status'] === 'confirmed' && $repository->getOccurrence($ssoFixture['occurrence_id'])['state'] === 'open', 'Rejected late cancellation must leave historical participation unchanged.');
    echo "Course real-database lifecycle, email-domain, rollback, capacity and retention tests passed.\n";
} finally {
    $courseTestBreakRead = '';
    $courseTestBreakWrite = false;
    foreach (array_reverse($courseTestIds) as $id) {
        courseTestCleanup($id);
    }
}
