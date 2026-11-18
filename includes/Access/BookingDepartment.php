<?php

declare(strict_types=1);

namespace RRZE\RSVP\Access;

use WP_Error;

defined('ABSPATH') || exit;

/** Stores the department decision once, independently of later permission or catalog edits. */
final class BookingDepartment
{
    public const META_KEY = '_rrze_rsvp_booking_department';

    /**
     * Save the final authorization decision on a new draft without replacing any existing data.
     *
     * Call only with the department returned by the same RoomAccess::resolve() that
     * authorized this new public booking. Caller-provided metadata is not a decision.
     *
     * @param int $bookingId Newly allocated draft booking ID.
     * @param int $roomId Room selected in the final authorization decision.
     * @param array{id:string,name:string}|null $department Exact department or no assignment.
     * @return true|WP_Error Whether the immutable snapshot is durably stored.
     */
    public static function saveNew(int $bookingId, int $roomId, #[\SensitiveParameter] ?array $department): true|WP_Error
    {
        try {
            if ($bookingId < 1 || $roomId < 1 || get_post_type($bookingId) !== 'booking'
                || get_post_status($bookingId) !== 'draft' || self::raw($bookingId) !== null) {
                return self::saveFailed();
            }
            if ($department === null) {
                return true;
            }
            if (!self::isDepartment($department)) {
                return self::saveFailed();
            }
            $cipher = new BookingDepartmentCipher(RoomAccess::siteContext(), $bookingId);
            $encrypted = $cipher->encrypt($department, $roomId);
            if ($cipher->decrypt($encrypted) !== $department
                || !add_post_meta($bookingId, self::META_KEY, wp_slash($encrypted), true)
                || self::raw($bookingId) !== $encrypted) {
                return self::saveFailed();
            }
            return true;
        } catch (\Throwable) {
            return self::saveFailed();
        }
    }

    /**
     * Read the historical snapshot without looking up current permissions or department names.
     *
     * @param int $bookingId Existing booking ID on the current website.
     * @return array{id:string,name:string}|null|WP_Error Snapshot, genuine absence or explicit storage error.
     */
    public static function read(int $bookingId): array|null|WP_Error
    {
        try {
            if ($bookingId < 1 || get_post_type($bookingId) !== 'booking') {
                return self::unavailable();
            }
            $stored = self::raw($bookingId);
            if ($stored === null) {
                return null;
            }
            $department = (new BookingDepartmentCipher(RoomAccess::siteContext(), $bookingId))->decrypt($stored);
            return self::isDepartment($department) ? $department : self::unavailable();
        } catch (\Throwable) {
            return self::unavailable();
        }
    }

    /**
     * Distinguish absent metadata from corrupt, duplicate or unreadable stored values.
     *
     * @param int $bookingId Booking whose protected metadata is read.
     * @return string|null Exactly one ciphertext or genuine absence.
     * @throws \RuntimeException When the database or stored shape is invalid.
     */
    private static function raw(int $bookingId): ?string
    {
        global $wpdb;
        wp_cache_delete($bookingId, 'post_meta');
        $values = get_post_meta($bookingId, self::META_KEY, false);
        if (isset($wpdb) && ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException('Booking-department storage could not be read.');
        }
        if ($values === []) {
            return null;
        }
        if (!is_array($values) || count($values) !== 1 || !is_string($values[0]) || $values[0] === '') {
            throw new \RuntimeException('Invalid booking-department metadata.');
        }
        return $values[0];
    }

    /**
     * Validate the historical v1 schema without changing an authorized department name.
     *
     * @param array<mixed> $department Input or decrypted snapshot.
     * @return bool Whether ID and name have the canonical department-catalog shape.
     */
    private static function isDepartment(#[\SensitiveParameter] array $department): bool
    {
        if (count($department) !== 2 || !is_string($department['id'] ?? null) || !is_string($department['name'] ?? null)
            || !preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $department['id'])) {
            return false;
        }
        $name = $department['name'];
        return $name !== '' && preg_match('//u', $name) === 1 && preg_match_all('/./us', $name) <= 200
            && !preg_match('/[\x00-\x1F\x7F<>]|\A\s|\s\z/u', $name)
            && preg_replace('/\s+/u', ' ', $name) === $name;
    }

    /** @return WP_Error Non-disclosing failure of a new snapshot write. */
    private static function saveFailed(): WP_Error
    {
        return new WP_Error('booking_department_save_failed', __('The department at booking could not be saved.', 'rrze-rsvp'), ['status' => 503]);
    }

    /** @return WP_Error Explicit failure instead of treating corrupted historical data as unassigned. */
    private static function unavailable(): WP_Error
    {
        return new WP_Error('booking_department_unavailable', __('The department recorded at booking cannot be read. Restore the encryption key or contact the website administrator.', 'rrze-rsvp'), ['status' => 503]);
    }
}
