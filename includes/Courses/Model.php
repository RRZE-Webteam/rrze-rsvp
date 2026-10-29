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
        return ['access' => 'sso_or_email', 'auto_confirm' => false, 'retention_days' => 90, 'allowed_email_domains' => []];
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
     * Normalize an optional list of exact email domains without discarding invalid entries.
     *
     * Accept ASCII hostnames (including punycode), an optional leading @, and
     * whitespace/comma/semicolon separators. Wildcards, URLs and addresses are invalid.
     *
     * @param mixed $input Textarea value or an array of domain strings.
     * @return array|null Unique lowercase domains; null if any entry is invalid.
     */
    public static function normalizeEmailDomains($input): ?array
    {
        if (is_string($input)) {
            $input = preg_split('/[\s,;]+/', trim($input), -1, PREG_SPLIT_NO_EMPTY);
        }
        if (!is_array($input)) {
            return null;
        }
        $domains = [];
        foreach ($input as $value) {
            if (!is_string($value)) {
                return null;
            }
            $domain = strtolower(trim($value));
            if (str_starts_with($domain, '@')) {
                $domain = substr($domain, 1);
            }
            if (strlen($domain) > 253 || !str_contains($domain, '.') || str_ends_with($domain, '.')
                || !filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                return null;
            }
            $domains[] = $domain;
        }
        return array_values(array_unique($domains));
    }

    /**
     * Apply a course's exact-domain restriction to a participant email address.
     *
     * @param string $email Email address, validated separately by the booking service.
     * @param array $settings Course settings; an absent or empty list allows all domains.
     * @return bool Whether the domain is permitted; malformed settings fail closed.
     */
    public static function allowsEmail(string $email, array $settings): bool
    {
        $domains = self::normalizeEmailDomains(array_key_exists('allowed_email_domains', $settings) ? $settings['allowed_email_domains'] : []);
        if ($domains === null) {
            return false;
        }
        if ($domains === []) {
            return true;
        }
        $parts = explode('@', self::normalizeEmail($email));
        return count($parts) === 2 && $parts[0] !== '' && in_array($parts[1], $domains, true);
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
