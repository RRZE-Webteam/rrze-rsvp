<?php

namespace RRZE\RSVP;

use RRZE\RSVP\Access\{BookingDepartment, RoomAccess};

defined('ABSPATH') || exit;

/** Serialize room reservation writers on the site-local booking day. */
final class RoomReservations
{
    /**
     * Recheck access and availability, freeze its department, then publish one complete reservation.
     * @param int $room Published room ID.
     * @param int $seat Verified seat belonging to the room.
     * @param string $date ISO local date.
     * @param string $time Grid start in H:i format.
     * @param int|null $duration Submitted flexible duration, or null for a fixed reservation.
     * @param bool $instant Request to book the running grid interval.
     * @param array $meta Validated booking status and encrypted guest details.
     * @param array{source: string, email: string}|null $identity Server-verified SSO/LDAP identity, never POST data.
     * @return int|\WP_Error Published booking ID or a booking-form error code.
     */
    public static function create(int $room, int $seat, string $date, string $time, ?int $duration,
        bool $instant, array $meta, ?array $identity = null): int|\WP_Error
    {
        $lock = self::lock($date);
        if (!$lock) {
            return new \WP_Error('save_error');
        }
        try {
            // The protected snapshot is derived only from the final access decision.
            unset($meta[BookingDepartment::META_KEY]);
            $rule = RoomSlots::rule($room);
            if (($rule !== null) !== ($duration !== null)
                || ($rule && $instant && !RoomSlots::instantAllowed($room))) {
                return new \WP_Error('seat_unavailable');
            }
            $currentInterval = get_post_meta($room, 'rrze-rsvp-room-bookingmode', true) === 'check-only' || ($rule && $instant);
            $choices = RoomSlots::available($room, $date, $date, false, $duration,
                seat: $seat, instant: $currentInterval, onlyStart: $time);
            $until = RoomSlots::end($choices['slots'][$date] ?? [], $time, $seat);
            if (!$until) {
                return new \WP_Error('seat_unavailable');
            }
            $start = strtotime("$date $time UTC");
            $end = strtotime("$date $until UTC");
            if (self::guestBusy($meta['rrze-rsvp-booking-guest-email'], $start, $end)) {
                return new \WP_Error('multiple_booking');
            }
            $meta['rrze-rsvp-booking-seat'] = $seat;
            $meta['rrze-rsvp-booking-start'] = $start;
            $meta['rrze-rsvp-booking-end'] = $end;
            if ($rule) {
                $meta['rrze-rsvp-booking-flexible'] = 1;
            }
            // Re-read current access immediately before writing; a displayed form is not a grant.
            $access = RoomAccess::resolve($room, $identity);
            if (is_wp_error($access)) {
                return $access;
            }
            // A partial write must never appear as a published reservation.
            $id = wp_insert_post(wp_slash(['post_type' => 'booking', 'post_status' => 'draft', 'meta_input' => $meta]), true);
            if (!$id || is_wp_error($id)) {
                return new \WP_Error('save_error');
            }
            // wp_insert_post does not propagate individual post-meta write failures.
            foreach ($meta as $key => $value) {
                if (get_post_meta($id, $key, true) !== (string) $value) {
                    wp_delete_post($id, true);
                    return new \WP_Error('save_error');
                }
            }
            if (is_wp_error(BookingDepartment::saveNew((int) $id, $room, $access['department']))) {
                wp_delete_post($id, true);
                return new \WP_Error('save_error');
            }
            $published = wp_update_post(['ID' => $id, 'post_status' => 'publish'], true);
            if (!$published || is_wp_error($published)) {
                wp_delete_post($id, true);
                return new \WP_Error('save_error');
            }
            return $id;
        } finally {
            self::unlock($lock);
        }
    }

    /**
     * @param string $date ISO local day.
     * @return string Acquired connection-scoped lock name, or empty on contention.
     */
    public static function lock(string $date): string
    {
        global $wpdb;
        $name = 'rsvp-day-' . md5(DB_NAME . ':' . $wpdb->prefix . ':' . $date);
        return (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $name)) === '1' ? $name : '';
    }

    /**
     * @param string $name Lock acquired by lock().
     * @return void Release the writing connection.
     */
    public static function unlock(string $name): void
    {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }

    /**
     * @param string $email Encrypted guest identity.
     * @param int $start Inclusive start.
     * @param int $end Exclusive end.
     * @param int $exclude Current booking.
     * @return bool Whether this person has a concurrent reservation.
     */
    public static function guestBusy(string $email, int $start, int $end, int $exclude = 0): bool
    {
        return $email !== '' && (bool) get_posts([
            'post_type' => 'booking', 'post_status' => 'publish', 'fields' => 'ids', 'numberposts' => 1,
            'cache_results' => false, 'post__not_in' => $exclude ? [$exclude] : [],
            'meta_query' => [
                ['key' => 'rrze-rsvp-booking-guest-email', 'value' => $email],
                ['key' => 'rrze-rsvp-booking-status', 'value' => Functions::SEAT_RESERVING_STATUSES, 'compare' => 'IN'],
                ...RoomSlots::overlap($start, $end),
            ],
        ]);
    }
}
