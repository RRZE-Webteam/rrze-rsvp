<?php

namespace RRZE\RSVP\Courses;

use RRZE\RSVP\Functions;
use RuntimeException;
use Throwable;
use WP_Error;

defined('ABSPATH') || exit;

/** The only writer for course settings, dates, participant holds and status changes. */
class BookingService
{
    private Repository $repository;
    private static int $writeDepth = 0;
    private static array $engineSupport = [];
    private array $touched = [];
    private array $events = [];

    /**
     * @param Repository|null $repository Optional fresh-database repository.
     */
    public function __construct(?Repository $repository = null)
    {
        $this->repository = $repository ?? new Repository();
    }

    /** @return bool Whether a trusted transactional service write is in progress. */
    public static function isWriting(): bool
    {
        return self::$writeDepth > 0;
    }

    /** @return bool Whether both current-site storage tables support transactions. */
    public function supportsTransactions(): bool
    {
        global $wpdb;
        $key = $wpdb->posts . ':' . $wpdb->postmeta;
        if (!array_key_exists($key, self::$engineSupport)) {
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s, %s)',
                $wpdb->posts, $wpdb->postmeta
            ), ARRAY_A);
            self::$engineSupport[$key] = count((array) $rows) === 2;
            foreach ((array) $rows as $row) {
                self::$engineSupport[$key] = self::$engineSupport[$key] && strtolower((string) $row['ENGINE']) === 'innodb';
            }
        }
        return self::$engineSupport[$key];
    }

    /**
     * Read display availability; write commands always repeat this inside their lock.
     *
     * @param int $occurrenceId Course date ID.
     * @return array|WP_Error Public availability and counts without participant data.
     */
    public function availability(int $occurrenceId)
    {
        $previous = $this->repository->setStrictReads(true);
        try {
            return $this->readAvailability($occurrenceId);
        } catch (Throwable $error) {
            return $this->error('storage_error', __('Availability could not be read. Please try again.', 'rrze-rsvp'), 503);
        } finally {
            $this->repository->setStrictReads($previous);
        }
    }

    /**
     * Calculate availability with strict reads enabled by the public boundary.
     *
     * @param int $occurrenceId Course date ID.
     * @return array|WP_Error Public counts or a missing-date error.
     */
    private function readAvailability(int $occurrenceId)
    {
        $date = $this->repository->getOccurrence($occurrenceId);
        if (is_wp_error($date)) {
            return $date;
        }
        $course = $this->repository->getCourse($date['course_id']);
        if (is_wp_error($course)) {
            return $course;
        }
        $counts = Model::counts($this->repository->getBookingsForOccurrence($occurrenceId), (int) $date['capacity'], time());
        $reason = $this->availabilityReason($course, $date, $counts, time());
        return array_merge($counts, ['occurrence_id' => $occurrenceId, 'course_id' => $date['course_id'],
            'bookable' => $reason === 'available', 'reason' => $reason, 'start' => $date['start'],
            'end' => $date['end'], 'location' => $date['location']]);
    }

    /**
     * Save course options without changing settings snapshotted in existing bookings.
     *
     * @param int $courseId Course post ID.
     * @param array $settings Complete supported settings.
     * @return array|WP_Error Updated course DTO.
     */
    public function saveCourseSettings(int $courseId, array $settings)
    {
        if (!current_user_can('edit_post', $courseId)) {
            return $this->error('forbidden', __('You cannot edit this course.', 'rrze-rsvp'), 403);
        }
        return $this->transaction($courseId, 0, 'writeCourseSettings', [$settings]);
    }

    /**
     * Create/update one stable course date through the capacity lock.
     *
     * @param int $courseId Parent course ID.
     * @param array $data Full occurrence values; UTC times are required.
     * @param int $occurrenceId Existing occurrence ID or zero for a new date.
     * @return array|WP_Error Saved occurrence DTO.
     */
    public function saveOccurrence(int $courseId, array $data, int $occurrenceId = 0)
    {
        if (!current_user_can('edit_post', $courseId)) {
            return $this->error('forbidden', __('You cannot edit this course.', 'rrze-rsvp'), 403);
        }
        return $this->transaction($courseId, $occurrenceId, 'writeOccurrence', [$data]);
    }

    /**
     * Reserve a participant place, with idempotency scoped to one occurrence.
     *
     * @param int $occurrenceId Course date ID.
     * @param array $participant firstname, lastname, email, consent, optional notes/locale.
     * @param string $requestId Stable random client request ID, reused only for retries.
     * @param bool $trustedIdentity True only after server-side SSO verification.
     * @return array|WP_Error Public booking result; no access tokens or personal details.
     */
    public function book(int $occurrenceId, array $participant, string $requestId, bool $trustedIdentity = false)
    {
        $participant['email'] = Model::normalizeEmail((string) ($participant['email'] ?? ''));
        $participant['firstname'] = sanitize_text_field((string) ($participant['firstname'] ?? ''));
        $participant['lastname'] = sanitize_text_field((string) ($participant['lastname'] ?? ''));
        if (!$participant['firstname'] || !$participant['lastname'] || !is_email($participant['email'])
            || empty($participant['consent']) || !preg_match('/^[A-Za-z0-9_-]{16,128}$/D', $requestId)) {
            return $this->error('invalid_participant', __('Please provide your name, a valid email address and consent.', 'rrze-rsvp'), 422);
        }
        return $this->forOccurrence($occurrenceId, 'createBooking', [$participant, $requestId, $trustedIdentity]);
    }

    /**
     * Verify ownership of the booking email without taking another participant place.
     *
     * @param int $bookingId Course booking ID.
     * @param string $token Purpose-scoped email token.
     * @return array|WP_Error Current public booking result.
     */
    public function verify(int $bookingId, string $token)
    {
        return $this->forBooking($bookingId, 'verifyBooking', [$bookingId, $token]);
    }

    /**
     * Approve an identity-verified booking before the course begins.
     *
     * @param int $bookingId Booking ID.
     * @return array|WP_Error Current public booking result.
     */
    public function approve(int $bookingId)
    {
        if (!current_user_can('edit_post', $bookingId)) {
            return $this->error('forbidden', __('You cannot manage this booking.', 'rrze-rsvp'), 403);
        }
        return $this->forBooking($bookingId, 'approveBooking', [$bookingId]);
    }

    /**
     * Cancel through an authorized manager or a participant's cancellation token.
     *
     * @param int $bookingId Booking ID.
     * @param string $reason admin, rejected or customer.
     * @param bool $manager Whether manager authorization is required.
     * @param string $token Participant cancellation token, required when manager=false.
     * @return array|WP_Error Current public booking result.
     */
    public function cancel(int $bookingId, string $reason = 'admin', bool $manager = true, string $token = '')
    {
        if (($manager && !current_user_can('edit_post', $bookingId))
            || !in_array($reason, $manager ? ['admin', 'rejected'] : ['customer'], true)) {
            return $this->error('forbidden', __('You cannot cancel this booking.', 'rrze-rsvp'), 403);
        }
        return $this->forBooking($bookingId, 'cancelBooking', [$bookingId, $reason, $manager, $token]);
    }

    /**
     * Close a date and cancel all its active bookings atomically.
     *
     * @param int $occurrenceId Course date ID.
     * @return array|WP_Error Date and number of newly cancelled bookings.
     */
    public function cancelOccurrence(int $occurrenceId)
    {
        $date = $this->repository->getOccurrence($occurrenceId);
        if (is_wp_error($date)) {
            return $date;
        }
        if (!current_user_can('edit_post', $date['course_id'])) {
            return $this->error('forbidden', __('You cannot edit this course.', 'rrze-rsvp'), 403);
        }
        return $this->forOccurrence($occurrenceId, 'cancelDate');
    }

    /**
     * Rotate action tokens for a manager-requested resend, without extending a hold.
     *
     * @param int $bookingId Booking ID.
     * @return array|WP_Error Current public booking result.
     */
    public function resend(int $bookingId)
    {
        if (!current_user_can('edit_post', $bookingId)) {
            return $this->error('forbidden', __('You cannot manage this booking.', 'rrze-rsvp'), 403);
        }
        return $this->forBooking($bookingId, 'resendBooking', [$bookingId]);
    }

    /** @return array|WP_Error Number of expired verification holds processed by cron. */
    public function expireDue()
    {
        return $this->runMaintenance('expireBookings', 'expired');
    }

    /** @return array|WP_Error Number of retained bookings removed after their deadline. */
    public function purgeExpired()
    {
        return $this->runMaintenance('purgeBookings', 'deleted');
    }

    /**
     * Keep all course records until their own retention deadline, including cancellation.
     *
     * @param int $bookingId Booking ID.
     * @return bool Whether the configured retention deadline has passed.
     */
    public function canDeleteBooking(int $bookingId): bool
    {
        $booking = $this->repository->getBooking($bookingId);
        if (is_wp_error($booking)) {
            return false;
        }
        return $this->retentionDeadline($booking) <= time();
    }

    /**
     * Explicit deletion still obeys retention; normal administration should not offer it.
     *
     * @param int $bookingId Booking ID.
     * @return array|WP_Error Deleted record ID after its retention deadline.
     */
    public function deleteBooking(int $bookingId)
    {
        if (!current_user_can('delete_post', $bookingId)) {
            return $this->error('forbidden', __('You cannot delete this booking.', 'rrze-rsvp'), 403);
        }
        return $this->forBooking($bookingId, 'removeRetainedBooking', [$bookingId]);
    }

    /**
     * Remove an empty date under the same lock used by new registrations.
     *
     * @param int $occurrenceId Course date ID.
     * @param bool $force Permanently delete instead of moving to trash.
     * @return array|WP_Error Removal result.
     */
    public function deleteOccurrence(int $occurrenceId, bool $force = false)
    {
        $date = $this->repository->getOccurrence($occurrenceId);
        if (is_wp_error($date)) {
            return $date;
        }
        if (!current_user_can('delete_post', $date['course_id'])) {
            return $this->error('forbidden', __('You cannot delete this course date.', 'rrze-rsvp'), 403);
        }
        return $this->forOccurrence($occurrenceId, 'removeOccurrence', [$force]);
    }

    /**
     * Remove an empty course while serializing with all child date writers.
     *
     * @param int $courseId Course ID.
     * @param bool $force Permanently delete course and empty date children.
     * @return array|WP_Error Removal result.
     */
    public function deleteCourse(int $courseId, bool $force = false)
    {
        if (!current_user_can('delete_post', $courseId)) {
            return $this->error('forbidden', __('You cannot delete this course.', 'rrze-rsvp'), 403);
        }
        return $this->transaction($courseId, 0, 'removeCourse', [$force]);
    }

    /**
     * Remove a child date only after its absence of retained references is locked.
     *
     * @param array $course Course DTO.
     * @param array $date Date DTO.
     * @param bool $force Whether deletion is permanent.
     * @return array|WP_Error Removal result.
     */
    private function removeOccurrence(array $course, array $date, bool $force)
    {
        if ($this->repository->hasBookings($date['id'])) {
            return $this->error('retained', __('This course date still has retained bookings.', 'rrze-rsvp'), 409);
        }
        $this->touched[] = $date['id'];
        $removed = $force ? wp_delete_post($date['id'], true) : wp_trash_post($date['id']);
        if (!$removed) {
            throw new RuntimeException('Could not remove occurrence.');
        }
        return ['id' => $date['id'], 'deleted' => true];
    }

    /**
     * Remove a course, preserving dates on trash and deleting empty children on purge.
     *
     * @param array $course Course DTO.
     * @param array|null $date Unused date context.
     * @param bool $force Whether deletion is permanent.
     * @return array|WP_Error Removal result.
     */
    private function removeCourse(array $course, ?array $date, bool $force)
    {
        global $wpdb;
        if ($this->repository->hasBookings($course['id'])) {
            return $this->error('retained', __('This course still has retained bookings.', 'rrze-rsvp'), 409);
        }
        if ($force) {
            $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d ORDER BY ID FOR UPDATE", Model::DATE_TYPE, $course['id']));
            $this->repository->checkRead();
            foreach ($ids as $id) {
                if ($this->repository->hasBookings((int) $id)) {
                    return $this->error('retained', __('This course date still has retained bookings.', 'rrze-rsvp'), 409);
                }
                $this->removePost((int) $id);
            }
        }
        $this->touched[] = $course['id'];
        $removed = $force ? wp_delete_post($course['id'], true) : wp_trash_post($course['id']);
        if (!$removed) {
            throw new RuntimeException('Could not remove course.');
        }
        return ['id' => $course['id'], 'deleted' => true];
    }

    /**
     * Execute one operation with a course lock, then a date lock, on the same connection.
     *
     * @param int $courseId Parent post ID.
     * @param int $occurrenceId Date ID, or zero while creating a date/settings.
     * @param string $operation Private operation method name.
     * @param array $arguments Remaining operation arguments.
     * @return array|WP_Error Operation result after commit or rollback.
     */
    private function transaction(int $courseId, int $occurrenceId, string $operation, array $arguments = [])
    {
        global $wpdb;
        if (!$this->supportsTransactions()) {
            return $this->error('unsupported_storage', __('Course booking requires transactional database tables.', 'rrze-rsvp'), 503);
        }
        if (self::isWriting()) {
            return $this->error('nested_write', __('A course update is already running.', 'rrze-rsvp'), 409);
        }
        $this->touched = [$courseId, $occurrenceId];
        $this->events = [];
        $started = false;
        try {
            if ($wpdb->query('START TRANSACTION') === false) {
                throw new RuntimeException('Could not start transaction.');
            }
            $started = true;
            self::$writeDepth++;
            $coursePost = $wpdb->get_row($wpdb->prepare("SELECT ID, post_type FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $courseId), ARRAY_A);
            if (!$coursePost || $coursePost['post_type'] !== Model::COURSE_TYPE) {
                throw new RuntimeException('Course no longer exists.');
            }
            if ($occurrenceId) {
                $datePost = $wpdb->get_row($wpdb->prepare("SELECT ID, post_type, post_parent FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $occurrenceId), ARRAY_A);
                if (!$datePost || $datePost['post_type'] !== Model::DATE_TYPE || (int) $datePost['post_parent'] !== $courseId) {
                    throw new RuntimeException('Course date no longer exists.');
                }
            }
            $course = $this->repository->getCourse($courseId);
            $date = $occurrenceId ? $this->repository->getOccurrence($occurrenceId) : null;
            if (is_wp_error($course) || is_wp_error($date)) {
                $result = is_wp_error($course) ? $course : $date;
            } else {
                $result = $this->{$operation}($course, $date, ...$arguments);
            }
            if (is_wp_error($result)) {
                $wpdb->query('ROLLBACK');
                $this->events = [];
            } else {
                /** Allow integration tests to force a failure before any side effect escapes. */
                do_action('rrze_rsvp_course_before_commit', $operation, $courseId, $occurrenceId);
                if ($wpdb->query('COMMIT') === false) {
                    throw new RuntimeException('Could not commit transaction.');
                }
            }
        } catch (Throwable $error) {
            if ($started) {
                $wpdb->query('ROLLBACK');
            }
            $this->events = [];
            $result = $this->error('storage_error', __('The booking could not be saved. Please try again.', 'rrze-rsvp'), 503);
        } finally {
            if ($started && self::$writeDepth > 0) {
                self::$writeDepth--;
            }
            foreach (array_unique(array_filter($this->touched)) as $postId) {
                clean_post_cache($postId);
                wp_cache_delete($postId, 'post_meta');
            }
        }
        if (!is_wp_error($result)) {
            $events = $this->events;
            $this->events = [];
            foreach ($events as $event) {
                try {
                    $previous = $this->repository->setStrictReads(true);
                    try {
                        $booking = $this->repository->getBooking($event['id']);
                    } finally {
                        $this->repository->setStrictReads($previous);
                    }
                    if (is_wp_error($booking)) {
                        throw new RuntimeException('Could not prepare the booking notification.');
                    }
                    do_action('rrze_rsvp_course_booking_event', $event['event'], $booking, $event['tokens']);
                } catch (Throwable $error) {
                    update_post_meta($event['id'], 'rrze-rsvp-course-mail-error', __('A booking notification could not be sent. Please resend it.', 'rrze-rsvp'));
                }
            }
        }
        return $result;
    }

    /**
     * Resolve an occurrence before starting the locking transaction.
     *
     * @param int $id Date ID.
     * @param string $operation Operation name.
     * @param array $args Operation arguments.
     * @return array|WP_Error Transaction result.
     */
    private function forOccurrence(int $id, string $operation, array $args = [])
    {
        $date = $this->repository->getOccurrence($id);
        return is_wp_error($date) ? $date : $this->transaction($date['course_id'], $id, $operation, $args);
    }

    /**
     * Resolve a booking's immutable occurrence before taking the shared lock.
     *
     * @param int $id Booking ID.
     * @param string $operation Operation name.
     * @param array $args Operation arguments.
     * @return array|WP_Error Transaction result.
     */
    private function forBooking(int $id, string $operation, array $args)
    {
        $booking = $this->repository->getBooking($id);
        return is_wp_error($booking) ? $booking : $this->forOccurrence($booking['occurrence_id'], $operation, $args);
    }

    /**
     * Write validated course settings while its row is locked.
     *
     * @param array $course Course DTO.
     * @param array|null $date Unused date context.
     * @param array $settings Submitted settings.
     * @return array|WP_Error Updated course DTO.
     */
    private function writeCourseSettings(array $course, ?array $date, array $settings)
    {
        $settings = array_merge(Model::defaults(), $settings);
        $days = filter_var($settings['retention_days'], FILTER_VALIDATE_INT);
        if (!in_array($settings['access'], ['sso_or_email', 'sso'], true) || $days === false || $days < 1 || $days > 36500) {
            return $this->error('invalid_settings', __('Please check the course access and retention settings.', 'rrze-rsvp'), 422);
        }
        $domains = Model::normalizeEmailDomains($settings['allowed_email_domains']);
        if ($domains === null) {
            return $this->error('invalid_email_domains', __('Please enter valid email domains, such as fau.de or uk-erlangen.de. Separate them with commas or line breaks. Do not enter full email addresses, URLs or wildcards.', 'rrze-rsvp'), 422);
        }
        $settings = ['access' => $settings['access'], 'auto_confirm' => (bool) $settings['auto_confirm'], 'retention_days' => $days,
            'allowed_email_domains' => $domains];
        $this->writeMeta($course['id'], Model::COURSE_SETTINGS_META, $settings);
        return $this->repository->getCourse($course['id']);
    }

    /**
     * Validate and persist an occurrence without losing historical associations.
     *
     * @param array $course Course DTO.
     * @param array|null $old Existing date or null for a new row.
     * @param array $input Submitted occurrence values.
     * @return array|WP_Error Saved occurrence DTO.
     */
    private function writeOccurrence(array $course, ?array $old, array $input)
    {
        $data = array_merge(['start' => 0, 'end' => 0, 'room_id' => 0, 'location' => '', 'capacity' => 20,
            'registration_start' => 0, 'registration_end' => 0], $input);
        foreach (['start', 'end', 'room_id', 'capacity', 'registration_start', 'registration_end'] as $field) {
            $value = filter_var($data[$field], FILTER_VALIDATE_INT);
            if ($value === false || $value < 0) {
                return $this->error('invalid_date', __('Please enter valid course dates and capacity.', 'rrze-rsvp'), 422);
            }
            $data[$field] = $value;
        }
        if (!$data['start'] || $data['end'] <= $data['start'] || $data['capacity'] < 1
            || $data['registration_end'] > $data['start'] || $data['registration_start'] >= ($data['registration_end'] ?: $data['start'])) {
            return $this->error('invalid_date', __('Please check the course times, registration period and capacity.', 'rrze-rsvp'), 422);
        }
        $data['location'] = sanitize_text_field((string) $data['location']);
        if ($data['room_id']) {
            if ($old && $old['room_id'] === $data['room_id']) {
                // The saved venue is independent of later room publication or
                // address changes. Only selecting a new room requires validation.
                $data['location'] = $old['location'];
            } else {
                $room = $this->repository->post($data['room_id']);
                if (!$room || $room['post_type'] !== 'room' || $room['post_status'] !== 'publish') {
                    return $this->error('invalid_location', __('Please select a published room or enter a location.', 'rrze-rsvp'), 422);
                }
                $parts = [$room['post_title'], $this->repository->meta($data['room_id'], 'rrze-rsvp-room-street'),
                    trim($this->repository->meta($data['room_id'], 'rrze-rsvp-room-zip') . ' ' . $this->repository->meta($data['room_id'], 'rrze-rsvp-room-city'))];
                $data['location'] = implode(', ', array_filter($parts));
            }
        }
        if (!$data['location']) {
            return $this->error('invalid_location', __('Please enter the course location.', 'rrze-rsvp'), 422);
        }
        if ($old) {
            $counts = Model::counts($this->repository->getBookingsForOccurrence($old['id'], true), $old['capacity'], time());
            if ($data['capacity'] < $counts['held']) {
                return $this->error('capacity_below_bookings', __('Capacity cannot be lower than the number of reserved places.', 'rrze-rsvp'), 409);
            }
            foreach (['start', 'end', 'room_id', 'location'] as $field) {
                if ($counts['held'] && $data[$field] !== $old[$field]) {
                    return $this->error('date_locked', __('Dates and location cannot be changed while active bookings exist.', 'rrze-rsvp'), 409);
                }
            }
            $id = $old['id'];
        } else {
            $id = wp_insert_post(['post_type' => Model::DATE_TYPE, 'post_status' => 'publish', 'post_parent' => $course['id'],
                'post_title' => $course['title'] . ' ' . gmdate('Y-m-d H:i', $data['start'])], true);
            if (is_wp_error($id) || !$id) {
                throw new RuntimeException('Could not create occurrence.');
            }
            $id = (int) $id;
            $this->touched[] = $id;
        }
        $data = array_intersect_key($data, array_flip(['start', 'end', 'room_id', 'location', 'capacity', 'registration_start', 'registration_end']));
        $data['state'] = $old['state'] ?? 'open';
        $this->writeMeta($id, Model::OCCURRENCE_META, $data);
        return $this->repository->getOccurrence($id);
    }

    /**
     * Create a booking inside the occurrence lock.
     *
     * @param array $course Fresh course DTO.
     * @param array $date Locked occurrence DTO.
     * @param array $person Validated participant fields.
     * @param string $requestId Stable client request ID.
     * @param bool $trusted Trusted SSO identity flag from the server adapter.
     * @return array|WP_Error Public result.
     */
    private function createBooking(array $course, array $date, array $person, string $requestId, bool $trusted)
    {
        $bookings = $this->repository->getBookingsForOccurrence($date['id'], true);
        $requestHash = hash('sha256', $requestId);
        $emailHash = hash_hmac('sha256', $person['email'], wp_salt('auth'));
        foreach ($bookings as $booking) {
            $data = $this->repository->meta($booking['id'], Model::BOOKING_DATA_META, []);
            if (($data['request_hash'] ?? '') === $requestHash) {
                return hash_equals((string) ($data['email_hash'] ?? ''), $emailHash)
                    ? $this->result($booking, false)
                    : $this->error('request_conflict', __('This request has already been used. Please reload the form.', 'rrze-rsvp'), 409);
            }
        }
        if ($course['settings']['access'] === 'sso' && !$trusted) {
            return $this->error('sso_required', __('Please sign in using SSO to book this course.', 'rrze-rsvp'), 403);
        }
        // Recheck fresh course settings inside the lock, including trusted SSO identities.
        // Idempotent retries above only return an existing booking; they create no new hold.
        if (!Model::allowsEmail($person['email'], $course['settings'])) {
            return $this->error('email_domain_not_allowed', __('This email domain is not allowed for this course. Please use an email address with one of the listed domains. If you signed in with SSO, the email address in your SSO profile is used.', 'rrze-rsvp'), 403);
        }
        $counts = Model::counts($bookings, $date['capacity'], time());
        $reason = $this->availabilityReason($course, $date, $counts, time());
        if ($reason !== 'available') {
            return $this->error($reason, $reason === 'full' ? __('This course date is fully booked.', 'rrze-rsvp') : __('Registration for this course date is closed.', 'rrze-rsvp'), 409);
        }
        foreach ($bookings as $booking) {
            if (Model::holdsPlace($booking, time()) && Model::normalizeEmail($booking['guest_email']) === $person['email']) {
                return $this->error('duplicate', __('An active booking already exists for this email address and course date.', 'rrze-rsvp'), 409);
            }
        }
        $id = wp_insert_post(['post_type' => 'booking', 'post_status' => 'publish', 'post_title' => ''], true);
        if (is_wp_error($id) || !$id) {
            throw new RuntimeException('Could not create booking.');
        }
        $id = (int) $id;
        $this->touched[] = $id;
        $status = $trusted ? Model::verifiedStatus($course['settings']) : 'booked';
        $data = ['snapshot' => ['course_title' => $course['title'], 'start' => $date['start'], 'end' => $date['end'],
            'location' => $date['location'], 'room_id' => $date['room_id'], 'settings' => $course['settings']],
            'request_hash' => $requestHash, 'email_hash' => $emailHash,
            'expires_at' => $trusted ? 0 : min(time() + HOUR_IN_SECONDS, $date['start']),
            'cancel_reason' => '', 'ics_uid' => wp_generate_uuid4() . '@rrze-rsvp',
            'locale' => preg_match('/^[a-z]{2}_[A-Z]{2}$/D', (string) ($person['locale'] ?? '')) ? $person['locale'] : get_locale()];
        $tokens = $this->newTokens($data, $status);
        $this->writeMeta($id, Model::BOOKING_DATE_META, $date['id']);
        $this->writeMeta($id, Model::BOOKING_COURSE_META, $course['id']);
        $this->writeMeta($id, Model::BOOKING_DATA_META, $data);
        $this->writeMeta($id, Model::STATUS_META, $status);
        $this->writeMeta($id, 'rrze-rsvp-booking-start', $date['start']);
        $this->writeMeta($id, 'rrze-rsvp-booking-end', $date['end']);
        foreach (['firstname', 'lastname', 'email'] as $field) {
            $this->writeMeta($id, 'rrze-rsvp-booking-guest-' . $field, Functions::crypt($person[$field]));
        }
        $this->writeMeta($id, 'rrze-rsvp-booking-notes', sanitize_textarea_field((string) ($person['notes'] ?? '')));
        $this->writeMeta($id, 'rrze-rsvp-booking-dsgvo', 'on');
        $this->queueEvent($id, $status === 'booked' ? 'verification_required' : ($status === 'confirmed' ? 'confirmed' : 'approval_required'), $tokens);
        return $this->result($this->repository->getBooking($id), true);
    }

    /**
     * Verify a guest email under the occurrence lock.
     *
     * @param array $course Course DTO.
     * @param array $date Date DTO.
     * @param int $id Booking ID.
     * @param string $token Verification secret.
     * @return array|WP_Error Public result.
     */
    private function verifyBooking(array $course, array $date, int $id, string $token)
    {
        $booking = $this->repository->getBooking($id);
        if (is_wp_error($booking)) {
            return $booking;
        }
        $data = $this->repository->meta($id, Model::BOOKING_DATA_META, []);
        if (!$this->tokenMatches($data, 'verify', $token)) {
            return $this->error('invalid_token', __('This confirmation link is invalid.', 'rrze-rsvp'), 403);
        }
        if ($booking['status'] === 'cancelled') {
            return $this->error('expired', __('This booking is no longer active. Please make a new booking.', 'rrze-rsvp'), 410);
        }
        if ($booking['status'] !== 'booked') {
            return $this->result($booking, false);
        }
        if (!Model::holdsPlace($booking, time()) || time() >= $booking['start'] || $date['state'] !== 'open') {
            return $this->error('expired', __('This confirmation link has expired. Please make a new booking.', 'rrze-rsvp'), 410);
        }
        $status = Model::verifiedStatus($booking['settings']);
        $this->writeMeta($id, Model::STATUS_META, $status);
        $data['expires_at'] = 0;
        $tokens = $this->newTokens($data, $status);
        $this->writeMeta($id, Model::BOOKING_DATA_META, $data);
        $this->queueEvent($id, $status === 'confirmed' ? 'confirmed' : 'approval_required', $tokens);
        return $this->result($this->repository->getBooking($id), false);
    }

    /**
     * Approve a verified hold without changing reserved capacity.
     *
     * @param array $course Course DTO.
     * @param array $date Date DTO.
     * @param int $id Booking ID.
     * @return array|WP_Error Public result.
     */
    private function approveBooking(array $course, array $date, int $id)
    {
        $booking = $this->repository->getBooking($id);
        if (is_wp_error($booking)) {
            return $booking;
        }
        if ($booking['status'] === 'confirmed') {
            return $this->result($booking, false);
        }
        if ($booking['status'] !== 'customer-confirmed' || time() >= $booking['start'] || $date['state'] !== 'open') {
            return $this->error('invalid_transition', __('Only identity-verified bookings can be approved before the course begins.', 'rrze-rsvp'), 409);
        }
        $data = $this->repository->meta($id, Model::BOOKING_DATA_META, []);
        $tokens = $this->newTokens($data, 'confirmed');
        $this->writeMeta($id, Model::BOOKING_DATA_META, $data);
        $this->writeMeta($id, Model::STATUS_META, 'confirmed');
        $this->queueEvent($id, 'confirmed', $tokens);
        return $this->result($this->repository->getBooking($id), false);
    }

    /**
     * Apply cancellation, checking either the manager deadline or the participant token.
     *
     * @param array $course Course DTO.
     * @param array $date Date DTO.
     * @param int $id Booking ID.
     * @param string $reason Cancellation reason.
     * @param bool $manager Whether the caller is authorized staff.
     * @param string $token Participant secret.
     * @return array|WP_Error Public result.
     */
    private function cancelBooking(array $course, array $date, int $id, string $reason, bool $manager, string $token)
    {
        $booking = $this->repository->getBooking($id);
        if (is_wp_error($booking)) {
            return $booking;
        }
        $data = $this->repository->meta($id, Model::BOOKING_DATA_META, []);
        if (!$manager && !$this->tokenMatches($data, 'cancel', $token)) {
            return $this->error('invalid_token', __('This cancellation link is invalid.', 'rrze-rsvp'), 403);
        }
        if ($booking['status'] === 'cancelled') {
            return $this->result($booking, false);
        }
        if (time() >= ($manager ? $booking['end'] : $booking['start'])) {
            return $this->error('cancellation_closed', __('The cancellation deadline has passed.', 'rrze-rsvp'), 409);
        }
        $this->setCancelled($booking, $reason);
        return $this->result($this->repository->getBooking($id), false);
    }

    /**
     * Cancel all active registrations while closing the same locked date.
     *
     * @param array $course Course DTO.
     * @param array $date Date DTO.
     * @return array|WP_Error Date ID and cancellation count, or a passed deadline.
     */
    private function cancelDate(array $course, array $date)
    {
        if ($date['state'] === 'cancelled') {
            return ['occurrence_id' => $date['id'], 'cancelled' => 0];
        }
        if (time() >= $date['end']) {
            return $this->error('cancellation_closed', __('The cancellation deadline has passed.', 'rrze-rsvp'), 409);
        }
        $data = $this->repository->meta($date['id'], Model::OCCURRENCE_META, []);
        $data['state'] = 'cancelled';
        $this->writeMeta($date['id'], Model::OCCURRENCE_META, $data);
        $count = 0;
        foreach ($this->repository->getBookingsForOccurrence($date['id'], true) as $booking) {
            if (in_array($booking['status'], Model::ACTIVE_STATUSES, true)) {
                $this->setCancelled($booking, 'occurrence_cancelled');
                $count++;
            }
        }
        return ['occurrence_id' => $date['id'], 'cancelled' => $count];
    }

    /**
     * Regenerate only action secrets, preserving status and the original expiry.
     *
     * @param array $course Course DTO.
     * @param array $date Date DTO.
     * @param int $id Booking ID.
     * @return array|WP_Error Public result.
     */
    private function resendBooking(array $course, array $date, int $id)
    {
        $booking = $this->repository->getBooking($id);
        if (is_wp_error($booking)) {
            return $booking;
        }
        if ($booking['status'] === 'booked' && !Model::holdsPlace($booking, time())) {
            return $this->error('expired', __('The email confirmation period has expired.', 'rrze-rsvp'), 410);
        }
        $data = $this->repository->meta($id, Model::BOOKING_DATA_META, []);
        $tokens = $this->newTokens($data, $booking['status']);
        $this->writeMeta($id, Model::BOOKING_DATA_META, $data);
        $this->queueEvent($id, 'resend', $tokens);
        return $this->result($booking, false);
    }

    /**
     * Mark expired holds cancelled; capacity already excludes them before cron runs.
     *
     * @param array $course Course DTO.
     * @param array $date Date DTO.
     * @return array Count of changed records.
     */
    private function expireBookings(array $course, array $date): array
    {
        $count = 0;
        foreach ($this->repository->getBookingsForOccurrence($date['id'], true) as $booking) {
            if ($booking['status'] === 'booked' && $booking['expires_at'] <= time()) {
                $this->setCancelled($booking, 'expired');
                $count++;
            }
        }
        return ['expired' => $count];
    }

    /**
     * Remove all retained date bookings whose own snapshot end is old enough.
     *
     * @param array $course Course DTO.
     * @param array $date Date DTO.
     * @return array Number of deleted bookings.
     */
    private function purgeBookings(array $course, array $date): array
    {
        $count = 0;
        foreach ($this->repository->getBookingsForOccurrence($date['id'], true) as $booking) {
            if ($this->retentionDeadline($booking) <= time()) {
                $this->removePost($booking['id']);
                $count++;
            }
        }
        return ['deleted' => $count];
    }

    /**
     * Perform an authorized explicit deletion only once retention has elapsed.
     *
     * @param array $course Course DTO.
     * @param array $date Date DTO.
     * @param int $id Booking ID.
     * @return array|WP_Error Deleted booking ID or retention error.
     */
    private function removeRetainedBooking(array $course, array $date, int $id)
    {
        if (!$this->canDeleteBooking($id)) {
            return $this->error('retained', __('This course booking is still within its retention period.', 'rrze-rsvp'), 409);
        }
        $this->removePost($id);
        return ['id' => $id, 'deleted' => true];
    }

    /**
     * Run system maintenance one date at a time, never holding a whole-site transaction.
     *
     * @param string $operation Private date operation.
     * @param string $counter Result counter field.
     * @return array|WP_Error Aggregated maintenance result.
     */
    private function runMaintenance(string $operation, string $counter)
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID", Model::DATE_TYPE));
        if (!$this->repository->checkRead()) {
            return $this->error('storage_error', __('Course maintenance could not read the stored dates.', 'rrze-rsvp'), 503);
        }
        $total = 0;
        foreach ($ids as $id) {
            $result = $this->forOccurrence((int) $id, $operation);
            if (is_wp_error($result)) {
                return $result;
            }
            $total += $result[$counter];
        }
        return [$counter => $total];
    }

    /**
     * Compute a retained record's deadline from its original end and current course policy.
     *
     * @param array $booking Private booking DTO.
     * @return int UTC deletion deadline.
     */
    private function retentionDeadline(array $booking): int
    {
        $course = $this->repository->getCourse($booking['course_id']);
        $settings = is_wp_error($course) ? $booking['settings'] : $course['settings'];
        return $booking['end'] + max(1, (int) $settings['retention_days']) * DAY_IN_SECONDS;
    }

    /**
     * Calculate why an occurrence can or cannot accept a new registration.
     *
     * @param array $course Course DTO.
     * @param array $date Occurrence DTO.
     * @param array $counts Current counts.
     * @param int $now UTC current time.
     * @return string Stable availability reason.
     */
    private function availabilityReason(array $course, array $date, array $counts, int $now): string
    {
        if (!$this->supportsTransactions()) {
            return 'unsupported';
        }
        if ($course['post_status'] !== 'publish' || $date['post_status'] !== 'publish') {
            return 'unpublished';
        }
        if ($date['state'] !== 'open') {
            return 'cancelled';
        }
        if ($now < ($date['registration_start'] ?: $course['published_at'])
            || $now >= ($date['registration_end'] ?: $date['start']) || $now >= $date['start']) {
            return 'closed';
        }
        return $counts['free'] > 0 ? 'available' : 'full';
    }

    /**
     * Write a cancellation and enqueue its notification once.
     *
     * @param array $booking Booking DTO.
     * @param string $reason Internal cancellation reason.
     * @return void
     */
    private function setCancelled(array $booking, string $reason): void
    {
        $data = $this->repository->meta($booking['id'], Model::BOOKING_DATA_META, []);
        $data['cancel_reason'] = $reason;
        $data['cancelled_at'] = time();
        $this->writeMeta($booking['id'], Model::BOOKING_DATA_META, $data);
        $this->writeMeta($booking['id'], Model::STATUS_META, 'cancelled');
        $this->queueEvent($booking['id'], 'cancelled');
    }

    /**
     * Produce fresh purpose-scoped tokens; only hashes are persisted.
     *
     * @param array $data Mutable booking data array.
     * @param string $status Current booking status.
     * @return array Plain tokens passed only to the post-commit notification callback.
     */
    private function newTokens(array &$data, string $status): array
    {
        $tokens = [];
        if ($status === 'booked') {
            $tokens['verify'] = bin2hex(random_bytes(32));
            $data['verify_token_hash'] = hash('sha256', $tokens['verify']);
        }
        if (in_array($status, Model::ACTIVE_STATUSES, true)) {
            $tokens['cancel'] = bin2hex(random_bytes(32));
            $data['cancel_token_hash'] = hash('sha256', $tokens['cancel']);
        }
        return $tokens;
    }

    /**
     * Compare a token in constant time while rejecting malformed input first.
     *
     * @param array $data Private booking metadata.
     * @param string $purpose verify or cancel.
     * @param string $token Submitted opaque secret.
     * @return bool Whether the purpose hash matches.
     */
    private function tokenMatches(array $data, string $purpose, string $token): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/D', $token)
            && !empty($data[$purpose . '_token_hash'])
            && hash_equals($data[$purpose . '_token_hash'], hash('sha256', $token));
    }

    /**
     * Write metadata and fail the entire transaction if persistence did not succeed.
     *
     * @param int $id Post ID.
     * @param string $key Meta key.
     * @param mixed $value Value to store.
     * @return void
     */
    private function writeMeta(int $id, string $key, $value): void
    {
        $this->touched[] = $id;
        wp_cache_delete($id, 'post_meta');
        $written = update_post_meta($id, $key, wp_slash($value));
        if ($written === false && $this->repository->meta($id, $key, null) != $value) {
            throw new RuntimeException('Could not write course metadata.');
        }
    }

    /**
     * Delete a retained record together with WordPress-managed metadata.
     *
     * @param int $id Booking post ID.
     * @return void
     */
    private function removePost(int $id): void
    {
        global $wpdb;
        $this->touched[] = $id;
        if (!wp_delete_post($id, true)) {
            throw new RuntimeException('Could not remove retained booking.');
        }
        // WordPress does not propagate every metadata DELETE failure. Verify the
        // retention promise before committing, so tokens/PII cannot be orphaned.
        $remaining = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d", $id));
        $this->repository->checkRead();
        if ($remaining || $this->repository->post($id)) {
            throw new RuntimeException('Could not remove all retained booking data.');
        }
    }

    /**
     * Record a notification for dispatch only after successful database commit.
     *
     * @param int $id Booking ID.
     * @param string $event Notification event.
     * @param array $tokens Plain action tokens, never returned by public commands.
     * @return void
     */
    private function queueEvent(int $id, string $event, array $tokens = []): void
    {
        $this->events[] = ['id' => $id, 'event' => $event, 'tokens' => $tokens];
    }

    /**
     * Produce a minimal response safe for the submitting participant.
     *
     * @param array $booking Private booking DTO.
     * @param bool $created Whether this command created the record.
     * @return array Public command result.
     */
    private function result(array $booking, bool $created): array
    {
        return ['id' => $booking['id'], 'status' => $booking['status'], 'course_id' => $booking['course_id'],
            'occurrence_id' => $booking['occurrence_id'], 'expires_at' => $booking['expires_at'], 'created' => $created];
    }

    /**
     * Build a consistent public error without leaking internal database diagnostics.
     *
     * @param string $code Error suffix.
     * @param string $message Translated message.
     * @param int $status HTTP status for REST adapters.
     * @return WP_Error Structured domain failure.
     */
    private function error(string $code, string $message, int $status): WP_Error
    {
        return new WP_Error('course_' . $code, $message, ['status' => $status]);
    }
}
