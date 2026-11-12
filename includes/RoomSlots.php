<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

/** Evaluate room opening windows and reservations using RSVP's local wall-clock timestamps. */
final class RoomSlots
{
    public const ENABLED = 'rrze-rsvp-room-flexible';
    public const MIN = 'rrze-rsvp-room-duration-min';
    public const MAX = 'rrze-rsvp-room-duration-max';
    public const STEP = 'rrze-rsvp-room-duration-step';

    /**
     * @param mixed $value Submitted minutes.
     * @return int Positive whole minutes, or zero for an invalid value.
     */
    public static function minutes($value): int
    {
        return (is_string($value) || is_int($value)) && ctype_digit((string) $value)
            ? (int) filter_var(ltrim((string) $value, '0'), FILTER_VALIDATE_INT) : 0;
    }

    /**
     * @param array $values Room field values keyed by metadata name.
     * @return array Field-specific validation errors.
     */
    public static function errors(array $values): array
    {
        $errors = [];
        $step = self::minutes($values[self::STEP] ?? '');
        $min = self::minutes($values[self::MIN] ?? '');
        $max = self::minutes($values[self::MAX] ?? '');
        foreach ([self::STEP => $step, self::MIN => $min, self::MAX => $max] as $key => $minutes) {
            if (!$minutes) {
                $errors[$key] = __('Enter a positive whole number of minutes.', 'rrze-rsvp');
            } elseif ($step && $minutes % $step) {
                $errors[$key] = __('Enter a multiple of the interval.', 'rrze-rsvp');
            }
        }
        if ($min && $max && $max < $min) {
            $errors[self::MAX] = __('Maximum duration must be at least the minimum duration.', 'rrze-rsvp');
        }
        return $errors;
    }

    /**
     * @param int $room Published room ID.
     * @return array|null Minimum, maximum and step in minutes for flexible rooms.
     */
    public static function rule(int $room): ?array
    {
        if (get_post_meta($room, self::ENABLED, true) !== 'on'
            || get_post_meta($room, 'rrze-rsvp-room-bookingmode', true) === 'check-only') {
            return null;
        }
        return [
            'min' => self::minutes(get_post_meta($room, self::MIN, true)),
            'max' => self::minutes(get_post_meta($room, self::MAX, true)),
            'step' => self::minutes(get_post_meta($room, self::STEP, true)),
        ];
    }

    /**
     * @param int $room Room ID.
     * @return bool Whether on-site reservations may use the current grid interval.
     */
    public static function instantAllowed(int $room): bool
    {
        return get_post_meta($room, 'rrze-rsvp-room-bookingmode', true) === 'reservation'
            && Functions::getBoolValueFromAtt(get_post_meta($room, 'rrze-rsvp-room-instant-check-in', true))
            && Functions::getBoolValueFromAtt(get_post_meta($room, 'rrze-rsvp-room-auto-confirmation', true));
    }

    /**
     * @param int $start Inclusive local timestamp.
     * @param int $end Exclusive local timestamp.
     * @return array WP meta query clauses.
     */
    public static function overlap(int $start, int $end): array
    {
        return [
            ['key' => 'rrze-rsvp-booking-start', 'value' => $end, 'compare' => '<', 'type' => 'NUMERIC'],
            ['relation' => 'OR',
                ['key' => 'rrze-rsvp-booking-end', 'value' => $start, 'compare' => '>', 'type' => 'NUMERIC'],
                // Legacy bookings without an end are displayed as reserving the rest of their local day.
                ['relation' => 'AND',
                    ['key' => 'rrze-rsvp-booking-start', 'value' => strtotime(gmdate('Y-m-d', $start) . ' UTC'), 'compare' => '>=', 'type' => 'NUMERIC'],
                    ['relation' => 'OR',
                        ['key' => 'rrze-rsvp-booking-end', 'compare' => 'NOT EXISTS'],
                        ['key' => 'rrze-rsvp-booking-end', 'value' => 0, 'compare' => '=', 'type' => 'NUMERIC'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Find the available intervals for one duration and the longest bookable duration in the range.
     * Each opening is evaluated separately. The next reservation bounds the available run for one seat.
     *
     * @param int $room Room ID.
     * @param string $first First ISO date.
     * @param string $last Last ISO date, inclusive.
     * @param bool $past Include elapsed starts (administrative overview).
     * @param int|null $duration Requested minutes, or null for the shortest bookable duration.
     * @param int $seat Limit to one seat already verified to belong to this room.
     * @param bool $instant Only consider the currently running grid interval for on-site check-in.
     * @param int $exclude Booking being edited, excluded from conflicts.
     * @param string $onlyStart Optional start time pinned by an on-site link.
     * @param bool $daysOnly Stop after finding the first bookable interval on each day.
     * @param int|null $clock Reference local clock timestamp, defaulting to WordPress time.
     * @return array{slots:array,longest:int} Date/range to seat IDs and the longest continuous duration.
     */
    public static function available(int $room, string $first, string $last, bool $past = true,
        ?int $duration = null, int $seat = 0, bool $instant = false, int $exclude = 0, string $onlyStart = '', bool $daysOnly = false, ?int $clock = null): array
    {
        $result = ['slots' => [], 'longest' => 0];
        if (!$room || !Functions::validateDate($first) || !Functions::validateDate($last) || $first > $last) {
            return $result;
        }
        $rule = self::rule($room);
        if ($rule && (!$rule['step'] || !$rule['min'] || $rule['max'] < $rule['min']
            || $rule['min'] % $rule['step'] || $rule['max'] % $rule['step'])) {
            return $result;
        }
        $duration ??= $rule['min'] ?? 0;
        $consultation = get_post_meta($room, 'rrze-rsvp-room-bookingmode', true) === 'consultation';
        $seats = $seat && !$consultation ? [$seat] : get_posts([
            'post_type' => 'seat', 'post_status' => 'publish', 'fields' => 'ids',
            'numberposts' => $consultation ? 1 : -1,
            'meta_key' => 'rrze-rsvp-seat-room', 'meta_value' => $room, 'orderby' => 'date ID', 'order' => 'ASC',
        ]);
        if (!$seats || ($seat && !in_array($seat, array_map('intval', $seats), true))) {
            return $result;
        }
        $begin = strtotime($first . ' UTC');
        $until = strtotime($last . ' UTC') + 86400;
        $busy = self::occupiedByDay($seats, $begin, $until, $exclude);
        $validDuration = !$rule || ($duration >= $rule['min'] && $duration <= $rule['max'] && $duration % $rule['step'] === 0);
        $closed = preg_split('/\s+/', trim((string) get_post_meta($room, 'rrze-rsvp-room-days-closed', true)));
        $now = $past && !$instant ? 0 : ($clock ?? current_time('timestamp'));
        for ($day = $begin; $day < $until; $day += 86400) {
            $date = gmdate('Y-m-d', $day);
            if (in_array($date, $closed, true)) {
                continue;
            }
            $windows = Functions::getRoomSchedule($room, false, $day)[gmdate('N', $day)] ?? [];
            foreach ($windows as $opens => $closes) {
                $start = strtotime("$date $opens UTC");
                $end = strtotime("$date $closes UTC");
                if ($end <= $start || ($now && $end <= $now)) {
                    continue;
                }
                // A step larger than a day cannot produce a new start inside one window.
                $step = $rule ? min(1440, $rule['step']) * 60 : $end - $start;
                $length = $rule ? $duration : intdiv($end - $start, 60);
                for ($at = $start; $at < $end; $at += $step) {
                    if (($onlyStart !== '' && gmdate('H:i', $at) !== $onlyStart)
                        || ($instant && ($at > $now || $at + $step <= $now))
                        || ($rule && !$past && !$instant && $at < $now)) {
                        continue;
                    }
                    foreach ($seats as $id) {
                        $minutes = self::freeMinutes($at, $end, $busy[$day][$id] ?? []);
                        if ($rule) {
                            $longest = min($rule['max'], $minutes - $minutes % $rule['step']);
                            if ($longest >= $rule['min']) {
                                $result['longest'] = max($result['longest'], $longest);
                            }
                        }
                        if (!$validDuration || $length > $minutes) {
                            continue;
                        }
                        $slot = gmdate('H:i', $at) . '-' . gmdate('H:i', $at + 60 * $length);
                        $result['slots'][$date][$slot][(int) $id] = (int) $id;
                        if ($daysOnly) {
                            break 3;
                        }
                    }
                }
            }
            if (isset($result['slots'][$date])) {
                ksort($result['slots'][$date]);
                $result['slots'][$date] = array_map('array_values', $result['slots'][$date]);
            }
        }
        return $result;
    }

    /**
     * Load active bookings across the requested seats in one query.
     * @param array $seats Published seat IDs.
     * @param int $begin First local midnight.
     * @param int $until Midnight after the last day.
     * @param int $exclude Booking being edited.
     * @return array Busy intervals indexed by local midnight and seat.
     */
    private static function occupiedByDay(array $seats, int $begin, int $until, int $exclude): array
    {
        $bookings = get_posts([
            'post_type' => 'booking', 'post_status' => 'publish', 'numberposts' => -1,
            'post__not_in' => $exclude ? [$exclude] : [], 'cache_results' => false,
            'meta_query' => [
                ['key' => 'rrze-rsvp-booking-seat', 'value' => $seats, 'compare' => 'IN'],
                ['key' => 'rrze-rsvp-booking-status', 'value' => Functions::SEAT_RESERVING_STATUSES, 'compare' => 'IN'],
                ...self::overlap($begin, $until),
            ],
        ]);
        $busy = [];
        foreach ($bookings as $booking) {
            $id = (int) get_post_meta($booking->ID, 'rrze-rsvp-booking-seat', true);
            $start = (int) get_post_meta($booking->ID, 'rrze-rsvp-booking-start', true);
            $end = (int) get_post_meta($booking->ID, 'rrze-rsvp-booking-end', true);
            if (!$end) {
                $end = strtotime(gmdate('Y-m-d', $start) . ' UTC') + 86400;
            }
            $firstDay = max($begin, strtotime(gmdate('Y-m-d', $start) . ' UTC'));
            for ($day = $firstDay; $day < min($until, $end); $day += 86400) {
                $busy[$day][$id][] = [$start, $end];
            }
        }
        return $busy;
    }

    /**
     * @param int $start Candidate start.
     * @param int $closes End of this opening.
     * @param array $busy Reserved intervals for this one seat and day.
     * @return int Uninterrupted minutes before closing or the next reservation.
     */
    private static function freeMinutes(int $start, int $closes, array $busy): int
    {
        foreach ($busy as [$taken, $released]) {
            if ($released > $start) {
                $closes = min($closes, $taken);
            }
        }
        return max(0, intdiv($closes - $start, 60));
    }

    /**
     * Render one accessible duration selector, optionally showing the exact end for a pinned start.
     * @param array $rule Room limits.
     * @param int $longest Longest continuously available duration.
     * @param int|null $chosen Current selection.
     * @param string $start Fixed on-site start, or empty for the ordinary picker.
     * @return string Escaped control.
     */
    public static function durationSelect(array $rule, int $longest, ?int $chosen, string $start = ''): string
    {
        $html = '<select name="rsvp_duration" aria-label="' . esc_attr__('Booking duration', 'rrze-rsvp') . '" required>'
            . '<option value="">' . esc_html__('Choose a duration', 'rrze-rsvp') . '</option>';
        for ($n = $rule['min']; $n > 0 && $rule['step'] > 0 && $n <= $longest; $n += $rule['step']) {
            /* translators: %d: booking duration in minutes. */
            $label = sprintf(__('%d min', 'rrze-rsvp'), $n);
            if ($start !== '') {
                $label .= ' · ' . $start . ' – ' . gmdate('H:i', strtotime("1970-01-01 $start UTC") + $n * 60);
            }
            $html .= '<option value="' . $n . '"' . selected($chosen, $n, false) . '>' . esc_html($label) . '</option>';
        }
        return $html . '</select>';
    }

    /**
     * @param array $slots Day's available intervals.
     * @param string $start Requested start.
     * @param int $seat Requested seat.
     * @return string Verified end time or empty.
     */
    public static function end(array $slots, string $start, int $seat): string
    {
        foreach ($slots as $range => $ids) {
            [$from, $until] = explode('-', $range);
            if ($start === $from && in_array($seat, $ids, true)) {
                return $until;
            }
        }
        return '';
    }
}
