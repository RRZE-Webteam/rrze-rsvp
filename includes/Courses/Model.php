<?php

namespace RRZE\RSVP\Courses;

defined('ABSPATH') || exit;

/** Shared storage names and pure course booking rules. */
final class Model
{
    public const COURSE_TYPE = 'rsvp_course';
    public const DATE_TYPE = 'rsvp_course_date';
    public const COURSE_SETTINGS_META = 'rrze-rsvp-course-settings';
    public const OCCURRENCE_META = 'rrze-rsvp-course-occurrence';
    public const BOOKING_DATE_META = 'rrze-rsvp-booking-course-date';
    public const BOOKING_COURSE_META = 'rrze-rsvp-booking-course';
    public const BOOKING_DATA_META = 'rrze-rsvp-course-booking-data';
    public const STATUS_META = 'rrze-rsvp-booking-status';
    public const ACTIVE_STATUSES = ['booked', 'customer-confirmed', 'confirmed'];

    /** @return array Default settings for a newly created course. */
    public static function defaults(): array
    {
        return ['access' => 'sso_or_email', 'auto_confirm' => false, 'retention_days' => 90];
    }

    /**
     * Decide whether a booking still holds capacity without relying on cron.
     *
     * @param array $booking Private booking DTO.
     * @param int $now UTC epoch seconds.
     * @return bool Whether this booking consumes one place.
     */
    public static function holdsPlace(array $booking, int $now): bool
    {
        if (($booking['post_status'] ?? 'publish') !== 'publish') {
            return false;
        }
        if (!in_array($booking['status'] ?? '', self::ACTIVE_STATUSES, true)) {
            return false;
        }
        return $booking['status'] !== 'booked' || (int) ($booking['expires_at'] ?? 0) > $now;
    }

    /**
     * Normalize addresses only by whitespace/case, without provider-specific rules.
     *
     * @param string $email Submitted email address.
     * @return string Canonical address used for duplicate detection.
     */
    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Return the next state once the participant identity has been established.
     *
     * @param array $settings Snapshotted course settings.
     * @return string Existing RSVP status code.
     */
    public static function verifiedStatus(array $settings): string
    {
        return !empty($settings['auto_confirm']) ? 'confirmed' : 'customer-confirmed';
    }

    /**
     * Count current occupants for administration and the public availability UI.
     *
     * @param array $bookings Private booking DTOs.
     * @param int $capacity Positive participant limit.
     * @param int $now UTC epoch seconds.
     * @return array Counts with a non-negative free count.
     */
    public static function counts(array $bookings, int $capacity, int $now): array
    {
        $confirmed = 0;
        $pending = 0;
        foreach ($bookings as $booking) {
            if (!self::holdsPlace($booking, $now)) {
                continue;
            }
            if ($booking['status'] === 'confirmed') {
                $confirmed++;
            } else {
                $pending++;
            }
        }
        return ['capacity' => $capacity, 'confirmed' => $confirmed, 'pending' => $pending,
            'held' => $confirmed + $pending, 'free' => max(0, $capacity - $confirmed - $pending)];
    }
}
