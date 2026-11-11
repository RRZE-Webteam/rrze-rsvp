<?php

namespace RRZE\RSVP;

use DateTimeImmutable;

defined('ABSPATH') || exit;

/** Build one read-only signage page from the same room occupancy used by the administration. */
final class PublicDisplay
{
    public const MODES = ['occupancy', 'occupancy_now', 'occupancy_nextavailable'];
    private const SEATS_PER_PAGE = 8;

    /**
     * Select the day, interval and seat group for one screen. Only public seat names
     * and availability leave this method. Page one starts at the relevant interval.
     *
     * @param int $roomId Room post ID.
     * @param string $mode Full day, remaining day, or next day with available seats.
     * @param DateTimeImmutable $now Current time in the site's timezone.
     * @param int $daysAhead Configured booking horizon, including today at zero.
     * @param int $page Requested one-based display page.
     * @return array Public snapshot with date, time, counts and visible seats, or an empty array.
     */
    public static function snapshot(int $roomId, string $mode, DateTimeImmutable $now, int $daysAhead, int $page = 1): array
    {
        $nextFree = $mode === 'occupancy_nextavailable';
        $today = $now->format('Y-m-d');
        $clock = $now->format('H:i');
        foreach (Functions::getRoomOccupancyDays($roomId, $now, $nextFree ? $daysAhead : 0) as $date => $matrix) {
            $slots = $matrix['room_slots'];
            unset($matrix['room_slots']);
            if (!$matrix) {
                return [];
            }
            $windows = [];
            $preferred = null;
            foreach ($slots as $slot) {
                [$start, $end] = explode(' - ', $slot);
                $ended = $date === $today && $end <= $clock;
                if ($mode !== 'occupancy' && $ended) {
                    continue;
                }
                $free = array_count_values(array_column($matrix, $slot))['available'] ?? 0;
                if (!$ended && (!$nextFree || $free > 0)) {
                    $preferred ??= count($windows);
                }
                $windows[] = ['slot' => $slot, 'start' => $start, 'end' => $end, 'free' => $free];
            }
            if (!$windows || ($nextFree && $preferred === null)) {
                continue;
            }

            $names = [];
            foreach (array_keys($matrix) as $id) {
                $names[$id] = get_the_title($id);
            }
            natcasesort($names);
            $total = count($names);
            $groups = (int) ceil($total / self::SEATS_PER_PAGE);
            $pages = count($windows) * $groups;
            $page = $page >= 1 && $page <= $pages ? $page : 1;
            $window = $windows[(($preferred ?? 0) + intdiv($page - 1, $groups)) % count($windows)];
            $offset = (($page - 1) % $groups) * self::SEATS_PER_PAGE;
            $seats = [];
            foreach (array_slice($names, $offset, self::SEATS_PER_PAGE, true) as $id => $name) {
                $seats[] = ['name' => $name, 'free' => $matrix[$id][$window['slot']] === 'available'];
            }
            return [
                'date' => $date,
                'start' => $window['start'],
                'end' => $window['end'],
                'free' => $window['free'],
                'total' => $total,
                'seats' => $seats,
                'page' => $page,
                'pages' => $pages,
            ];
        }
        return [];
    }
}
