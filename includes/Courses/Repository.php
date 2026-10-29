<?php

namespace RRZE\RSVP\Courses;

use RRZE\RSVP\Functions;
use RuntimeException;
use WP_Error;

defined('ABSPATH') || exit;

/** Fresh database readers used inside and outside the occurrence transaction. */
class Repository
{
    private bool $strictReads = false;
    private bool $lastReadFailed = false;

    /**
     * Enable fail-closed reads for an operation which can return a storage error.
     *
     * Transactional writers always use strict reads, including new repositories
     * created by WordPress hooks while the transaction is active.
     *
     * @param bool $strict Whether failed SELECTs must throw.
     * @return bool Previous setting, restored by the caller in finally.
     */
    public function setStrictReads(bool $strict): bool
    {
        $previous = $this->strictReads;
        $this->strictReads = $strict;
        return $previous;
    }

    /**
     * Distinguish a failed SELECT from a valid empty result immediately after SQL.
     *
     * @return bool Whether the read succeeded.
     * @throws RuntimeException During a transaction or explicit strict read.
     */
    public function checkRead(): bool
    {
        global $wpdb;
        $this->lastReadFailed = $wpdb->last_error !== '';
        if ($this->lastReadFailed && ($this->strictReads || BookingService::isWriting())) {
            throw new RuntimeException('Could not read course storage.');
        }
        return !$this->lastReadFailed;
    }

    /**
     * Read a post directly; persistent object caches cannot supply transaction data.
     *
     * @param int $id Post ID.
     * @return array|null Raw post record.
     */
    public function post(int $id): ?array
    {
        global $wpdb;
        $post = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID = %d", $id), ARRAY_A);
        return $this->checkRead() ? ($post ?: null) : null;
    }

    /**
     * Read a single metadata value directly from the current connection.
     *
     * @param int $id Post ID.
     * @param string $key Metadata key.
     * @param mixed $default Value returned for absent metadata.
     * @return mixed Unserialized metadata.
     */
    public function meta(int $id, string $key, $default = '')
    {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id LIMIT 1", $id, $key
        ));
        $this->checkRead();
        return $value === null ? $default : maybe_unserialize($value);
    }

    /**
     * Read course information and normalized settings.
     *
     * @param int $id Course ID.
     * @return array|WP_Error Course DTO or not-found error.
     */
    public function getCourse(int $id)
    {
        $post = $this->post($id);
        if (!$post || $post['post_type'] !== Model::COURSE_TYPE) {
            return new WP_Error('course_not_found', __('Course not found.', 'rrze-rsvp'), ['status' => 404]);
        }
        $settings = array_merge(Model::defaults(), (array) $this->meta($id, Model::COURSE_SETTINGS_META, []));
        $settings['auto_confirm'] = (bool) $settings['auto_confirm'];
        $settings['retention_days'] = max(1, (int) $settings['retention_days']);
        return ['id' => $id, 'title' => $post['post_title'], 'description' => $post['post_content'],
            'post_status' => $post['post_status'], 'settings' => $settings,
            'published_at' => strtotime($post['post_date_gmt'] . ' UTC') ?: 0];
    }

    /**
     * Read one internally managed date.
     *
     * @param int $id Occurrence post ID.
     * @return array|WP_Error Occurrence DTO or not-found error.
     */
    public function getOccurrence(int $id)
    {
        $post = $this->post($id);
        $data = $this->meta($id, Model::OCCURRENCE_META, []);
        if (!$post || $post['post_type'] !== Model::DATE_TYPE || !is_array($data) || empty($data['start'])) {
            return new WP_Error('course_date_not_found', __('Course date not found.', 'rrze-rsvp'), ['status' => 404]);
        }
        return array_merge(['start' => 0, 'end' => 0, 'room_id' => 0, 'location' => '', 'capacity' => 20,
            'registration_start' => 0, 'registration_end' => 0, 'state' => 'open'], $data,
            ['id' => $id, 'course_id' => (int) $post['post_parent'], 'post_status' => $post['post_status']]);
    }

    /**
     * List a course's dates without relying on row positions from the editor.
     *
     * @param int $courseId Course ID.
     * @param bool $futureOnly Exclude dates which have already ended.
     * @return array Occurrence DTOs ordered by UTC start and stable ID.
     */
    public function getOccurrences(int $courseId, bool $futureOnly = false): array
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d AND post_status <> 'trash'", Model::DATE_TYPE, $courseId
        ));
        if (!$this->checkRead()) {
            return [];
        }
        $result = [];
        foreach ($ids as $id) {
            $date = $this->getOccurrence((int) $id);
            if (!is_wp_error($date) && (!$futureOnly || $date['end'] > time())) {
                $result[] = $date;
            }
        }
        usort($result, [self::class, 'compareOccurrences']);
        return $result;
    }

    /**
     * Compare dates without an anonymous undocumented sorting callback.
     *
     * @param array $a First occurrence.
     * @param array $b Second occurrence.
     * @return int Sort direction.
     */
    public static function compareOccurrences(array $a, array $b): int
    {
        return [$a['start'], $a['id']] <=> [$b['start'], $b['id']];
    }

    /**
     * Determine whether a booking is governed by the course service.
     *
     * @param int $id Booking ID.
     * @return bool Whether the direct occurrence reference exists.
     */
    public function isCourseBooking(int $id): bool
    {
        $linked = (int) $this->meta($id, Model::BOOKING_DATE_META, 0) > 0;
        // Legacy guards must reject an unknown record if its classification failed.
        return $linked || $this->lastReadFailed;
    }

    /**
     * Read a private booking DTO; callers must never expose this wholesale publicly.
     *
     * @param int $id Booking ID.
     * @return array|WP_Error Decrypted private booking data.
     */
    public function getBooking(int $id)
    {
        $post = $this->post($id);
        $data = $this->meta($id, Model::BOOKING_DATA_META, []);
        if (!$post || $post['post_type'] !== 'booking' || !$this->isCourseBooking($id) || !is_array($data)) {
            return new WP_Error('course_booking_not_found', __('Course booking not found.', 'rrze-rsvp'), ['status' => 404]);
        }
        $snapshot = $data['snapshot'] ?? [];
        $created = strtotime($post['post_date_gmt'] . ' UTC') ?: 0;
        return [
            'id' => $id, 'type' => 'course', 'post_status' => $post['post_status'],
            'course_id' => (int) $this->meta($id, Model::BOOKING_COURSE_META),
            'occurrence_id' => (int) $this->meta($id, Model::BOOKING_DATE_META),
            'status' => (string) $this->meta($id, Model::STATUS_META),
            'start' => (int) ($snapshot['start'] ?? 0), 'end' => (int) ($snapshot['end'] ?? 0),
            'course_title' => (string) ($snapshot['course_title'] ?? ''),
            'location' => (string) ($snapshot['location'] ?? ''), 'room_id' => (int) ($snapshot['room_id'] ?? 0),
            'guest_firstname' => (string) Functions::crypt((string) $this->meta($id, 'rrze-rsvp-booking-guest-firstname'), 'decrypt'),
            'guest_lastname' => (string) Functions::crypt((string) $this->meta($id, 'rrze-rsvp-booking-guest-lastname'), 'decrypt'),
            'guest_email' => (string) Functions::crypt((string) $this->meta($id, 'rrze-rsvp-booking-guest-email'), 'decrypt'),
            'notes' => (string) $this->meta($id, 'rrze-rsvp-booking-notes'),
            'created_at' => $created, 'booking_date_timestamp' => $created,
            'expires_at' => (int) ($data['expires_at'] ?? 0),
            'cancel_reason' => (string) ($data['cancel_reason'] ?? ''),
            'settings' => $snapshot['settings'] ?? Model::defaults(),
            'ics_uid' => (string) ($data['ics_uid'] ?? ''), 'locale' => (string) ($data['locale'] ?? 'de_DE'),
        ];
    }

    /**
     * Read all bookings attached to a date, including historical records.
     *
     * The optional locking read establishes current-read semantics in InnoDB.
     *
     * @param int $occurrenceId Date ID.
     * @param bool $lock Select matching post rows FOR UPDATE inside a transaction.
     * @return array Private booking DTOs.
     */
    public function getBookingsForOccurrence(int $occurrenceId, bool $lock = false): array
    {
        global $wpdb;
        $sql = $wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
            WHERE p.post_type = 'booking' AND m.meta_key = %s AND m.meta_value = %s ORDER BY p.ID", Model::BOOKING_DATE_META, (string) $occurrenceId);
        $ids = $wpdb->get_col($sql . ($lock ? ' FOR UPDATE' : ''));
        if (!$this->checkRead()) {
            return [];
        }
        $result = [];
        foreach (array_unique($ids) as $id) {
            $booking = $this->getBooking((int) $id);
            if (!is_wp_error($booking)) {
                $result[] = $booking;
            }
        }
        return $result;
    }

    /**
     * Check whether retained bookings prevent removal of a course or date.
     *
     * @param int $postId Course/date ID.
     * @return bool Whether at least one reference exists in any post status.
     */
    public function hasBookings(int $postId): bool
    {
        global $wpdb;
        $post = $this->post($postId);
        if (!$post || !in_array($post['post_type'], [Model::COURSE_TYPE, Model::DATE_TYPE], true)) {
            return $this->lastReadFailed;
        }
        $key = $post['post_type'] === Model::COURSE_TYPE ? Model::BOOKING_COURSE_META : Model::BOOKING_DATE_META;
        $hasBookings = (bool) $wpdb->get_var($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
            WHERE p.post_type = 'booking' AND m.meta_key = %s AND m.meta_value = %s LIMIT 1", $key, (string) $postId));
        return $this->checkRead() ? $hasBookings : true;
    }

    /**
     * Detect active future course bookings which still refer to a room.
     *
     * @param int $roomId Existing RSVP room ID.
     * @return bool Whether deleting the venue would invalidate active bookings.
     */
    public function hasActiveRoomBookings(int $roomId): bool
    {
        $previous = $this->setStrictReads(true);
        try {
            return $this->readActiveRoomBookings($roomId);
        } catch (RuntimeException $error) {
            return true;
        } finally {
            $this->setStrictReads($previous);
        }
    }

    /**
     * Check venue references with errors handled by the fail-closed public guard.
     *
     * @param int $roomId Venue post ID.
     * @return bool Whether active course bookings use this venue.
     */
    private function readActiveRoomBookings(int $roomId): bool
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status <> 'trash'", Model::DATE_TYPE));
        if (!$this->checkRead()) {
            return true;
        }
        foreach ($ids as $id) {
            $date = $this->getOccurrence((int) $id);
            if (is_wp_error($date) || (int) $date['room_id'] !== $roomId || (int) $date['end'] <= time()) {
                continue;
            }
            foreach ($this->getBookingsForOccurrence((int) $id) as $booking) {
                if (Model::holdsPlace($booking, time())) {
                    return true;
                }
            }
        }
        return false;
    }
}
