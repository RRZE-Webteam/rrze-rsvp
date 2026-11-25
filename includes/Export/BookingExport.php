<?php

declare(strict_types=1);

namespace RRZE\RSVP\Export;

use DateTimeImmutable;
use DateTimeZone;
use RRZE\RSVP\Access\{BookingDepartment, RoomAccess};
use RRZE\RSVP\Functions;
use WP_Error;

defined('ABSPATH') || exit;

/** Builds a complete, authorized room-booking export in request-local memory. */
final class BookingExport
{
    public const LIMIT = 10000;
    private const BATCH_SIZE = 500;
    /** @var array<int, array{room_id:int,room:string,seat:string}> Request-local seat labels. */
    private array $places = [];

    /** @return array<string,string> Initial form values in the website's calendar. */
    public static function defaults(): array
    {
        $today = current_datetime();
        return ['room_id' => '0', 'from' => $today->modify('-27 days')->format('Y-m-d'),
            'to' => $today->format('Y-m-d'), 'status' => 'all', 'sort' => 'start', 'include_personal' => '0'];
    }

    /** @return array<string,string> Existing room-booking states with their editor labels. */
    public static function statuses(): array
    {
        return [
            'booked' => _x('Booked', 'Booking status in booking editor', 'rrze-rsvp'),
            'customer-confirmed' => _x('Confirmed by customer', 'Booking status in booking editor', 'rrze-rsvp'),
            'confirmed' => _x('Confirmed', 'Booking status in booking editor', 'rrze-rsvp'),
            'cancelled' => _x('Cancelled', 'Booking status in booking editor', 'rrze-rsvp'),
            'checked-in' => _x('Checked In', 'Booking status in booking editor', 'rrze-rsvp'),
            'checked-out' => _x('Checked Out', 'Booking status in booking editor', 'rrze-rsvp'),
        ];
    }

    /**
     * Validate all filters without accepting a user-supplied website or SQL fragment.
     * Dates intentionally use UTC-shaped local timestamps, like existing room bookings.
     *
     * @param array<string,mixed> $input Unslashed form values.
     * @return array<string,mixed>|WP_Error Canonical filters or a safe validation error.
     */
    public static function filters(array $input): array|WP_Error
    {
        $values = array_intersect_key($input, self::defaults()) + self::defaults();
        foreach ($values as $value) {
            if (!is_string($value)) return self::invalidFilters();
        }
        $room = filter_var($values['room_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $from = self::date($values['from']);
        $to = self::date($values['to']);
        if ($room === false || !$from || !$to || $from > $to
            || !in_array($values['status'], ['all', ...array_keys(self::statuses())], true)
            || !in_array($values['sort'], ['start', 'department'], true)
            || !in_array($values['include_personal'], ['0', '1'], true)) {
            return self::invalidFilters();
        }
        if ($room) {
            $post = get_post($room);
            if (!$post || $post->post_type !== 'room' || in_array($post->post_status, ['trash', 'auto-draft'], true)
                || !current_user_can('read_post', $room)) return self::invalidFilters();
        }
        if ($values['sort'] === 'department' && !RoomAccess::isEnabled()) {
            return new WP_Error('booking_export_sort', __('Department sorting requires enabled booking permissions. Select chronological sorting or enable booking permissions.', 'rrze-rsvp'));
        }
        return ['room_id' => $room, 'from' => $values['from'], 'to' => $values['to'],
            'start' => $from->getTimestamp(), 'until' => $to->modify('+1 day')->getTimestamp(),
            'status' => $values['status'], 'sort' => $values['sort'], 'personal' => $values['include_personal'] === '1'];
    }

    /**
     * Prepare every authorized row before allowing any download headers or file output.
     * Plaintext lives only in the returned document and this request's local variables.
     *
     * @param array<string,mixed> $input Unslashed filter fields.
     * @return array{headers:array,rows:array,from:string,to:string}|WP_Error Complete document or failure.
     */
    public function prepare(array $input): array|WP_Error
    {
        if (!current_user_can('edit_bookings')) return self::forbidden();
        $filters = self::filters($input);
        if (is_wp_error($filters)) return $filters;
        $this->places = [];
        $enabled = RoomAccess::isEnabled();
        $rows = [];
        $rooms = [];
        $hasDepartment = false;
        global $wpdb;
        try {
            // An upper ID and keyset pagination exclude later inserts and tolerate deletions.
            $ceiling = (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type = 'booking' AND post_status = 'publish'");
            if ($wpdb->last_error !== '') return self::unavailable();
            $lastId = 0;
            do {
                $ids = $this->candidateIds($filters, $lastId, $ceiling);
                if (is_wp_error($ids)) return $ids;
                foreach ($ids as $id) {
                    $lastId = (int) $id;
                    $post = get_post($lastId);
                    if (!$post || $post->post_type !== 'booking' || $post->post_status !== 'publish'
                        || !current_user_can('edit_post', $lastId)) continue;
                    $row = $this->baseRow($lastId, $filters);
                    if (is_wp_error($row)) return $row;
                    if ($row === null) continue;
                    if (count($rows) >= self::LIMIT) {
                        return new WP_Error('booking_export_limit', __('The export exceeds 10,000 bookings. Select a shorter period or a single room.', 'rrze-rsvp'));
                    }
                    if ($filters['personal']) {
                        $personal = $this->personal($lastId);
                        if (is_wp_error($personal)) return $personal;
                        $row['personal'] = $personal;
                    }
                    $row['department'] = '';
                    if ($enabled) {
                        $department = BookingDepartment::read($lastId);
                        if (is_wp_error($department)) return $department;
                        $row['department'] = $department['name'] ?? '';
                        $hasDepartment = $hasDepartment || $department !== null;
                    }
                    if ($row['room_id']) $rooms[$row['room_id']] = true;
                    $rows[] = $row;
                }
            } while (count($ids) === self::BATCH_SIZE);

            // Historical names decide inclusion first; no current identity lookup is needed.
            if ($enabled && !$hasDepartment) {
                foreach (array_keys($rooms) as $roomId) {
                    $room = get_post($roomId);
                    // Removed rooms no longer have active permissions; their booking history remains valid.
                    if (!$room || in_array($room->post_status, ['trash', 'auto-draft'], true)) continue;
                    $entries = RoomAccess::readEntries($roomId);
                    if (is_wp_error($entries)) return $entries;
                    foreach ($entries as $entry) {
                        if ($entry['department_id'] !== null) { $hasDepartment = true; break; }
                    }
                    unset($entries);
                    if ($hasDepartment) break;
                }
            }
            usort($rows,
                /**
                 * @param array $a First row.
                 * @param array $b Second row.
                 * @return int Stable export order.
                 */
                static function (array $a, array $b) use ($filters): int {
                    if ($filters['sort'] === 'department') {
                        $empty = ($a['department'] === '') <=> ($b['department'] === '');
                        if ($empty) return $empty;
                        $department = strnatcasecmp($a['department'], $b['department']);
                        if ($department) return $department;
                    }
                    return ($a['start'] <=> $b['start']) ?: ($a['id'] <=> $b['id']);
                });
            $headers = [__('Booking ID', 'rrze-rsvp'), __('Room', 'rrze-rsvp'), __('Seat', 'rrze-rsvp'),
                __('Start', 'rrze-rsvp'), __('End', 'rrze-rsvp'), __('Duration (minutes)', 'rrze-rsvp'), __('Status', 'rrze-rsvp')];
            if ($filters['personal']) array_push($headers, __('First name', 'rrze-rsvp'), __('Last name', 'rrze-rsvp'), __('Email', 'rrze-rsvp'));
            if ($hasDepartment) $headers[] = __('Department', 'rrze-rsvp');
            $states = self::statuses();
            foreach ($rows as &$row) {
                $values = [$row['id'], $row['room'], $row['seat'], gmdate('Y-m-d H:i:s', $row['start']),
                    $row['end'] === null ? '' : gmdate('Y-m-d H:i:s', $row['end']),
                    $row['end'] === null ? '' : rtrim(rtrim(number_format(($row['end'] - $row['start']) / 60, 2, '.', ''), '0'), '.'),
                    $states[$row['status']] ?? ($row['status'] ?: __('Unknown', 'rrze-rsvp'))];
                if ($filters['personal']) array_push($values, ...$row['personal']);
                if ($hasDepartment) $values[] = $row['department'];
                $row = $values;
            }
            unset($row);
            return ['headers' => $headers, 'rows' => $rows, 'from' => $filters['from'], 'to' => $filters['to']];
        } catch (\Throwable) {
            return self::unavailable();
        }
    }

    /**
     * Query only local published room-booking IDs, using bound filters and an ID cursor.
     *
     * @param array $filters Validated filters.
     * @param int $after Last processed ID.
     * @param int $ceiling Highest ID at the start of this export.
     * @return list<int|string>|WP_Error Candidate IDs without personal metadata.
     */
    private function candidateIds(array $filters, int $after, int $ceiling): array|WP_Error
    {
        global $wpdb;
        $sql = "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = 'booking' AND p.post_status = 'publish'
            AND p.ID > %d AND p.ID <= %d
            AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} c WHERE c.post_id = p.ID AND c.meta_key = 'rrze-rsvp-booking-course-date')
            AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} b WHERE b.post_id = p.ID AND b.meta_key = 'rrze-rsvp-booking-start'
                AND CAST(b.meta_value AS UNSIGNED) >= %d AND CAST(b.meta_value AS UNSIGNED) < %d)";
        $args = [$after, $ceiling, $filters['start'], $filters['until']];
        if ($filters['status'] !== 'all') {
            $sql .= " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} bs WHERE bs.post_id = p.ID AND bs.meta_key = 'rrze-rsvp-booking-status' AND bs.meta_value = %s)";
            $args[] = $filters['status'];
        }
        if ($filters['room_id']) {
            $sql .= " AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} bs
                INNER JOIN {$wpdb->posts} s ON s.ID = CAST(bs.meta_value AS UNSIGNED) AND s.post_type = 'seat'
                INNER JOIN {$wpdb->postmeta} r ON r.post_id = s.ID AND r.meta_key = 'rrze-rsvp-seat-room'
                WHERE bs.post_id = p.ID AND bs.meta_key = 'rrze-rsvp-booking-seat' AND r.meta_value = %s)";
            $args[] = (string) $filters['room_id'];
        }
        $sql .= ' ORDER BY p.ID ASC LIMIT %d';
        $args[] = self::BATCH_SIZE;
        $ids = $wpdb->get_col($wpdb->prepare($sql, ...$args));
        return $wpdb->last_error === '' && is_array($ids) ? $ids : self::unavailable();
    }

    /**
     * Recheck mutable filter data after the ID query and resolve the current seat/room labels.
     *
     * @param int $id Authorized booking ID.
     * @param array $filters Canonical filters.
     * @return array|null|WP_Error Base row, newly excluded booking or invalid data.
     */
    private function baseRow(int $id, array $filters): array|null|WP_Error
    {
        $meta = $this->metadata($id, ['rrze-rsvp-booking-start', 'rrze-rsvp-booking-end',
            'rrze-rsvp-booking-status', 'rrze-rsvp-booking-seat', 'rrze-rsvp-booking-course-date']);
        if (is_wp_error($meta)) return $meta;
        if (array_key_exists('rrze-rsvp-booking-course-date', $meta)) return null;
        $start = self::positiveInt($meta['rrze-rsvp-booking-start'] ?? '');
        $endValue = $meta['rrze-rsvp-booking-end'] ?? '';
        $end = in_array($endValue, ['', '0'], true) ? null : self::positiveInt($endValue);
        if (!$start || $end === false || ($end !== null && $end < $start)) return self::unavailable();
        $status = $meta['rrze-rsvp-booking-status'] ?? '';
        if ($start < $filters['start'] || $start >= $filters['until']
            || ($filters['status'] !== 'all' && $status !== $filters['status'])) return null;
        $seatValue = $meta['rrze-rsvp-booking-seat'] ?? '';
        $seatId = in_array($seatValue, ['', '0'], true) ? 0 : self::positiveInt($seatValue);
        if ($seatId === false) return self::unavailable();
        $place = $this->place($seatId);
        if (is_wp_error($place)) return $place;
        if ($filters['room_id'] && $place['room_id'] !== $filters['room_id']) return null;
        return ['id' => $id, 'start' => $start, 'end' => $end, 'status' => $status] + $place;
    }

    /**
     * Resolve labels without inventing replacements for permanently deleted rooms or seats.
     *
     * @param int $seatId Current seat reference, or zero for an absent reference.
     * @return array{room_id:int,room:string,seat:string}|WP_Error Request-local place information.
     */
    private function place(int $seatId): array|WP_Error
    {
        if (isset($this->places[$seatId])) return $this->places[$seatId];
        $place = ['room_id' => 0, 'room' => '', 'seat' => ''];
        $seat = $seatId ? get_post($seatId) : null;
        if ($seat && $seat->post_type === 'seat') {
            $place['seat'] = $seat->post_title;
            $meta = $this->metadata($seatId, ['rrze-rsvp-seat-room']);
            if (is_wp_error($meta)) return $meta;
            $value = $meta['rrze-rsvp-seat-room'] ?? '';
            $roomId = in_array($value, ['', '0'], true) ? 0 : self::positiveInt($value);
            if ($roomId === false) return self::unavailable();
            $room = $roomId ? get_post($roomId) : null;
            if ($room && $room->post_type === 'room') {
                $place['room_id'] = $roomId;
                $place['room'] = $room->post_title;
            }
        }
        return $this->places[$seatId] = $place;
    }

    /**
     * Decrypt only the explicitly requested personal columns, preserving genuinely absent fields.
     *
     * @param int $id Authorized booking ID.
     * @return list<string>|WP_Error Names and email, or a non-disclosing decryption error.
     */
    private function personal(int $id): array|WP_Error
    {
        $keys = ['rrze-rsvp-booking-guest-firstname', 'rrze-rsvp-booking-guest-lastname', 'rrze-rsvp-booking-guest-email'];
        $meta = $this->metadata($id, $keys);
        if (is_wp_error($meta)) return $meta;
        $values = [];
        foreach ($keys as $key) {
            $encrypted = $meta[$key] ?? '';
            $value = $encrypted === '' ? '' : Functions::crypt($encrypted, 'decrypt');
            if (!is_string($value) || preg_match('//u', $value) !== 1) {
                return new WP_Error('booking_export_personal', __('The requested names or email addresses could not be decrypted. Restore the booking encryption key or export without personal fields.', 'rrze-rsvp'));
            }
            $values[] = $value;
        }
        return $values;
    }

    /**
     * Read exactly the needed raw metadata; duplicate rows and database failures are errors.
     * No plaintext is written to WordPress metadata caches or transient storage.
     *
     * @param int $postId Current-site object ID.
     * @param list<string> $keys Fixed internal metadata keys.
     * @return array<string,string>|WP_Error Single values without implicit coercion.
     */
    private function metadata(int $postId, array $keys): array|WP_Error
    {
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($keys), '%s'));
        $records = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key IN ($placeholders)", $postId, ...$keys), ARRAY_A);
        if ($wpdb->last_error !== '' || !is_array($records)) return self::unavailable();
        $result = [];
        foreach ($records as $record) {
            if (array_key_exists($record['meta_key'], $result) || !is_string($record['meta_value'])) return self::unavailable();
            $result[$record['meta_key']] = $record['meta_value'];
        }
        return $result;
    }

    /**
     * Parse an ISO calendar date without accepting PHP's automatic rollover of invalid dates.
     *
     * @param string $value Submitted date.
     * @return DateTimeImmutable|null Exact supported date or invalid input.
     */
    private static function date(string $value): ?DateTimeImmutable
    {
        if (!preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value) || $value < '1970-01-01') return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * Accept only complete positive integer metadata, never partially numeric corruption.
     *
     * @param string $value Stored numeric value.
     * @return int|false Valid integer or failure.
     */
    private static function positiveInt(string $value): int|false
    {
        return ctype_digit($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
    }

    /** @return WP_Error Safe filter error with no submitted data echoed. */
    private static function invalidFilters(): WP_Error
    {
        return new WP_Error('booking_export_filters', __('Select a valid room, date range, status and sorting option. The start date must not be after the end date.', 'rrze-rsvp'));
    }

    /** @return WP_Error Explicit failure instead of a misleading partial export. */
    private static function unavailable(): WP_Error
    {
        return new WP_Error('booking_export_unavailable', __('The booking data could not be read completely. No CSV was created. Please try again or contact the website administrator.', 'rrze-rsvp'));
    }

    /** @return WP_Error Authorization failure shared with the admin download boundary. */
    public static function forbidden(): WP_Error
    {
        return new WP_Error('booking_export_forbidden', __('You are not allowed to export these bookings.', 'rrze-rsvp'), ['status' => 403]);
    }
}
