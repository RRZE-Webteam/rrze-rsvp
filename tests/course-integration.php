<?php

/**
 * Exercise course guards and legacy integration against disposable database rows.
 *
 * Run: wp --skip-plugins --skip-themes --url=SITE eval-file tests/course-integration.php
 * Queries for legacy cleanup are restricted to this test's own booking IDs.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/rrze-rsvp.php';
\RRZE\RSVP\plugin()->loaded();

use RRZE\RSVP\Actions;
use RRZE\RSVP\Courses\BookingService;
use RRZE\RSVP\Courses\Guards;
use RRZE\RSVP\Courses\Model;
use RRZE\RSVP\Courses\Module;
use RRZE\RSVP\Courses\PostTypes;
use RRZE\RSVP\Courses\Repository;
use RRZE\RSVP\Functions;
use RRZE\RSVP\Schedule;

PostTypes::register();
(new \RRZE\RSVP\CPT\Bookings())->booking_post_type();
(new Module())->upgradeRoles();
wp_set_current_user(1);

$courseIntegrationIds = [];
$courseIntegrationBookingIds = [];
$courseIntegrationScope = false;
$guards = new Guards();
$guards->onLoaded();
add_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);
add_action('pre_get_posts', 'courseIntegrationScopeQuery', PHP_INT_MAX);

/**
 * Limit legacy cleanup to disposable fixtures, never to other local bookings.
 *
 * @param WP_Query $query Database query being prepared.
 * @return void
 */
function courseIntegrationScopeQuery(WP_Query $query): void
{
    global $courseIntegrationScope, $courseIntegrationBookingIds;
    if ($courseIntegrationScope && in_array('booking', (array) $query->get('post_type'), true)) {
        $query->set('post__in', $courseIntegrationBookingIds ?: [-1]);
    }
}

/**
 * Assert an integration invariant with a useful failure description.
 *
 * @param bool $condition Expected truth.
 * @param string $message Failure description.
 * @return void
 */
function courseIntegrationAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Require a successful service result.
 *
 * @param array|WP_Error $result Operation result.
 * @return array Successful operation data.
 */
function courseIntegrationResult($result): array
{
    if (is_wp_error($result)) {
        throw new RuntimeException($result->get_error_code() . ': ' . $result->get_error_message());
    }
    return $result;
}

/**
 * Run the real retention transaction only for this suite's disposable date.
 *
 * @param BookingService $service Course service under test.
 * @param int $dateId Disposable occurrence ID.
 * @return array Purge result.
 */
function courseIntegrationPurge(BookingService $service, int $dateId): array
{
    return courseIntegrationResult((new ReflectionMethod(BookingService::class, 'forOccurrence'))
        ->invoke($service, $dateId, 'purgeBookings', []));
}

/** @return string A 12-hour locale setting used without altering stored site options. */
function courseIntegrationTwelveHourFormat(): string
{
    return 'g:i a';
}

try {
    $service = new BookingService();
    $repository = new Repository();
    $courseId = wp_insert_post([
        'post_type' => Model::COURSE_TYPE,
        'post_status' => 'publish',
        'post_title' => '[RSVP INTEGRATION TEST] ' . wp_generate_uuid4(),
    ], true);
    courseIntegrationAssert(!is_wp_error($courseId) && $courseId > 0, 'Course fixture failed.');
    $courseIntegrationIds[] = $courseId;
    courseIntegrationResult($service->saveCourseSettings($courseId, Model::defaults()));
    $date = courseIntegrationResult($service->saveOccurrence($courseId, [
        'start' => time() + 7 * DAY_IN_SECONDS,
        'end' => time() + 7 * DAY_IN_SECONDS + HOUR_IN_SECONDS,
        'location' => 'Disposable integration test venue',
        'capacity' => 2,
    ]));
    $courseIntegrationIds[] = $date['id'];
    $booking = courseIntegrationResult($service->book($date['id'], [
        'firstname' => 'Integration', 'lastname' => 'Test',
        'email' => 'rsvp-integration@example.test', 'consent' => true,
    ], wp_generate_uuid4(), true));
    $id = $booking['id'];
    $courseIntegrationIds[] = $id;
    $courseIntegrationBookingIds[] = $id;

    courseIntegrationAssert(Functions::getBooking($id)['type'] === 'course', 'Reader must branch to course data.');
    courseIntegrationAssert(!Functions::canDeleteBooking($id), 'Native deletion must respect retention.');
    courseIntegrationAssert(update_post_meta($id, Model::STATUS_META, 'confirmed') === false, 'Direct status write escaped guard.');
    courseIntegrationAssert(update_post_meta($id, Model::BOOKING_DATE_META, 0) === false, 'Relationship removal escaped guard.');
    courseIntegrationAssert(delete_post_meta($id, Model::BOOKING_DATA_META) === false, 'Snapshot deletion escaped guard.');
    global $wpdb;
    $statusMetaId = (int) $wpdb->get_var($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $id, Model::STATUS_META));
    $dateMetaId = (int) $wpdb->get_var($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $id, Model::BOOKING_DATE_META));
    courseIntegrationAssert(!update_metadata_by_mid('post', $statusMetaId, 'confirmed'), 'Custom-field status update escaped guard.');
    courseIntegrationAssert(!delete_metadata_by_mid('post', $dateMetaId), 'Custom-field relationship deletion escaped guard.');
    $harmlessMetaId = add_post_meta($courseId, 'course-test-harmless', 'test');
    courseIntegrationAssert(!update_metadata_by_mid('post', $harmlessMetaId, $date['id'], Model::BOOKING_DATE_META), 'Renamed custom field escaped relationship guard.');
    courseIntegrationAssert(is_wp_error(wp_update_post(['ID' => $id, 'post_status' => 'trash'], true)), 'Native post update escaped guard.');
    courseIntegrationAssert(!wp_delete_post($id, true) && !wp_trash_post($id), 'Native delete/trash escaped guard.');
    courseIntegrationAssert(!wp_delete_post($courseId, true) && !wp_trash_post($date['id']), 'Referenced course/date was removed.');
    courseIntegrationAssert(is_wp_error(wp_update_post(['ID' => $date['id'], 'post_parent' => 0], true)), 'Native date reparent escaped guard.');
    courseIntegrationAssert($repository->getBooking($id)['status'] === 'customer-confirmed', 'Guards changed the booking.');

    $actions = new Actions();
    $_POST['rrze-rsvp-booking-status'] = 'checked-in';
    $actions->transitionBookingStatus('publish', 'publish', get_post($id));
    unset($_POST['rrze-rsvp-booking-status']);
    courseIntegrationAssert($repository->getBooking($id)['status'] === 'customer-confirmed', 'Legacy transition affected a course.');
    wp_set_current_user(0);
    courseIntegrationAssert(is_wp_error($service->approve($id)), 'Anonymous manager action was accepted.');
    courseIntegrationAssert(Functions::getBooking($id) === [], 'Legacy public reader exposed private course details.');
    $previousGet = $_GET;
    $_GET = ['id' => $id, 'nonce' => 'fixture', 'booking' => wp_create_nonce('booked-fixture')];
    $legacyShortcode = (new ReflectionClass(\RRZE\RSVP\Shortcodes\Bookings::class))->newInstanceWithoutConstructor();
    courseIntegrationAssert((new ReflectionMethod($legacyShortcode, 'bookedNotice'))->invoke($legacyShortcode) === '', 'Legacy receipt accepted a course booking.');
    $_GET = $previousGet;
    wp_set_current_user(1);

    $listing = new \RRZE\RSVP\CPT\Bookings();
    $timeFilter = wp_date('H:i', $date['start']) . ' - ' . wp_date('H:i', $date['end']);
    add_filter('pre_option_time_format', 'courseIntegrationTwelveHourFormat');
    ob_start();
    $listing->addFilters('booking');
    $filtersHtml = ob_get_clean();
    remove_filter('pre_option_time_format', 'courseIntegrationTwelveHourFormat');
    courseIntegrationAssert(strpos($filtersHtml, 'value="' . $timeFilter . '"') !== false, 'Localized time labels must retain canonical filter values.');
    $_GET = ['rsvp_booking_date' => wp_date('Y-m-d', $date['start']), 'rsvp_booking_timeslot' => $timeFilter];
    (new ReflectionMethod($listing, 'setFilterParams'))->invoke($listing);
    courseIntegrationAssert(in_array($id, (new ReflectionMethod($listing, 'getBookingIDsByFilter'))->invoke($listing)), 'Course date/time filter excluded a matching booking.');
    $_GET = $previousGet;

    $sortLegacyId = wp_insert_post(['post_type' => 'booking', 'post_status' => 'publish', 'post_title' => '[RSVP INTEGRATION TEST] sort'], true);
    courseIntegrationAssert(!is_wp_error($sortLegacyId), 'Sort fixture failed.');
    $courseIntegrationIds[] = $sortLegacyId;
    $localCourseStart = (new DateTimeImmutable(wp_date('Y-m-d H:i:s', $date['start']), new DateTimeZone('UTC')))->getTimestamp();
    update_post_meta($sortLegacyId, 'rrze-rsvp-booking-start', $localCourseStart - 1800);
    $sortQuery = new WP_Query();
    $sortQuery->set('post_type', 'booking');
    $sortQuery->set('order', 'ASC');
    $sortQuery->set('rsvp_mixed_booking_date_sort', true);
    $orderSql = $listing->orderBookingDates($wpdb->postmeta . '.meta_value+0 ASC', $sortQuery);
    $sorted = $wpdb->get_col($wpdb->prepare("SELECT {$wpdb->posts}.ID FROM {$wpdb->posts}
        INNER JOIN {$wpdb->postmeta} ON {$wpdb->postmeta}.post_id = {$wpdb->posts}.ID AND {$wpdb->postmeta}.meta_key = 'rrze-rsvp-booking-start'
        WHERE {$wpdb->posts}.ID IN (%d, %d) ORDER BY " . $orderSql, $id, $sortLegacyId));
    courseIntegrationAssert(array_map('intval', $sorted) === [$sortLegacyId, $id], 'Mixed room/course sorting must use displayed local times.');

    $redirect = $actions->bookingBulkActionsHandler(admin_url('edit.php?post_type=booking'), 'cancel_booking', [$id]);
    courseIntegrationAssert($repository->getBooking($id)['status'] === 'cancelled', 'Bulk cancellation did not reach service.');
    courseIntegrationAssert($service->availability($date['id'])['free'] === 2, 'Bulk cancellation did not free capacity.');

    // Simulate an old record directly in this test's rows to exercise real cron queries.
    global $wpdb;
    $old = time() - 40 * DAY_IN_SECONDS;
    $wpdb->update($wpdb->posts, ['post_date' => gmdate('Y-m-d H:i:s', $old), 'post_date_gmt' => gmdate('Y-m-d H:i:s', $old)], ['ID' => $id]);
    $wpdb->update($wpdb->postmeta, ['meta_value' => (string) $old], ['post_id' => $id, 'meta_key' => 'rrze-rsvp-booking-start']);
    $data = $repository->meta($id, Model::BOOKING_DATA_META, []);
    $data['snapshot']['start'] = $old;
    $data['snapshot']['end'] = $old + HOUR_IN_SECONDS;
    $wpdb->update($wpdb->postmeta, ['meta_value' => maybe_serialize($data)], ['post_id' => $id, 'meta_key' => Model::BOOKING_DATA_META]);
    clean_post_cache($id);

    $legacyId = wp_insert_post(['post_type' => 'booking', 'post_status' => 'publish', 'post_title' => '[RSVP INTEGRATION TEST] legacy',
        'post_date' => gmdate('Y-m-d H:i:s', $old), 'post_date_gmt' => gmdate('Y-m-d H:i:s', $old)], true);
    courseIntegrationAssert(!is_wp_error($legacyId) && $legacyId > 0, 'Legacy fixture failed.');
    $courseIntegrationIds[] = $legacyId;
    $courseIntegrationBookingIds[] = $legacyId;
    update_post_meta($legacyId, 'rrze-rsvp-booking-start', $old);
    update_post_meta($legacyId, Model::STATUS_META, 'cancelled');

    $courseIntegrationScope = true;
    $schedule = new Schedule();
    foreach (['deleteOldBookings', 'deleteCancelledBookings', 'cancelUnconfirmedBookings', 'cancelNotCheckedInBookings', 'checkOutNotCheckedOutBookings'] as $method) {
        (new ReflectionMethod(Schedule::class, $method))->invoke($schedule);
    }
    $courseIntegrationScope = false;
    courseIntegrationAssert((bool) get_post($id), 'Legacy cron deleted a retained course booking.');
    courseIntegrationAssert(!get_post($legacyId), 'Legacy cleanup no longer handles ordinary bookings.');
    courseIntegrationAssert($repository->getBooking($id)['status'] === 'cancelled', 'Legacy cron changed a course status.');
    courseIntegrationPurge($service, $date['id']);
    courseIntegrationAssert((bool) get_post($id), '90-day retention ended too early.');
    courseIntegrationResult($service->saveCourseSettings($courseId, ['retention_days' => 28]));
    courseIntegrationPurge($service, $date['id']);
    courseIntegrationAssert(!$repository->post($id), 'Configured course retention did not remove the booking.');
    courseIntegrationAssert(!get_post_meta($id), 'Purged booking retained metadata.');

    echo "Course integration passed: guarded writes/deletion, permissions, legacy actions, bulk cancellation and independent retention.\n";
} finally {
    $courseIntegrationScope = false;
    remove_action('pre_get_posts', 'courseIntegrationScopeQuery', PHP_INT_MAX);
    foreach (['add_post_metadata', 'update_post_metadata', 'delete_post_metadata'] as $hook) {
        remove_filter($hook, [$guards, 'protectMeta'], 10);
    }
    remove_filter('update_post_metadata_by_mid', [$guards, 'protectMetaById'], 10);
    remove_filter('delete_post_metadata_by_mid', [$guards, 'protectMetaById'], 10);
    remove_filter('wp_insert_post_empty_content', [$guards, 'protectPost'], 10);
    remove_filter('pre_trash_post', [$guards, 'protectDeletion'], 10);
    remove_filter('pre_delete_post', [$guards, 'protectDeletion'], 10);
    foreach (array_reverse($courseIntegrationIds) as $fixtureId) {
        if (get_post($fixtureId)) {
            wp_delete_post($fixtureId, true);
        }
    }
}
