<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

/** Accessible public room availability grouped into compact, expandable months. */
final class RoomAvailability
{
    /**
     * Render available dates using the same data and booking links as the legacy list.
     *
     * No new availability rules are introduced here: closed days, occupied seats,
     * date-specific schedules and elapsed slots are resolved by the existing reader.
     *
     * @param int $roomId Room post ID.
     * @param int $days Number of days ahead, including today when zero.
     * @param bool $bookingLinks Whether the site permits links to the booking form.
     * @return string Escaped public markup, without any participant data.
     */
    public static function render(int $roomId, int $days, bool $bookingLinks): string
    {
        $today = current_datetime();
        $start = $today->format('Y-m-d');
        $end = $today->modify('+' . max(0, $days) . ' days')->format('Y-m-d');
        $availability = Functions::getRoomAvailability($roomId, $start, $end, false);
        // Rooms without published seats can still have schedule entries in the legacy reader.
        foreach ($availability as $date => $slots) {
            $availability[$date] = array_filter($slots);
            if (!$availability[$date]) {
                unset($availability[$date]);
            }
        }
        if (!$availability) {
            return '<p>' . esc_html__('No seats available.', 'rrze-rsvp') . '</p>';
        }
        $file = 'build/room-availability.css';
        $version = plugin()->getVersion() . '.' . filemtime(dirname(__DIR__) . '/' . $file);
        wp_enqueue_style('rrze-rsvp-room-availability', plugins_url($file, plugin()->getFile()), [], $version);
        $script = 'build/room-availability.js';
        wp_enqueue_script('rrze-rsvp-room-availability', plugins_url($script, plugin()->getFile()), [],
            plugin()->getVersion() . '.' . filemtime(dirname(__DIR__) . '/' . $script), true);
        $nonce = wp_create_nonce('rsvp-availability');
        $months = [];
        ksort($availability);
        foreach ($availability as $date => $slots) {
            $months[substr($date, 0, 7)][$date] = $slots;
        }
        $pickerLabels = [
            'search' => __('Search seats', 'rrze-rsvp'),
            'close' => __('Close seat selection', 'rrze-rsvp'),
            'empty' => __('No seats match your search.', 'rrze-rsvp'),
            /* translators: 1: Number of matching seats, 2: Total available seats. */
            'results' => __('%1$s of %2$s seats', 'rrze-rsvp'),
        ];
        $output = '<div class="rrze-rsvp-room-availability" data-seat-picker="' . esc_attr(wp_json_encode($pickerLabels)) . '">';
        if ($bookingLinks) {
            $output .= '<p class="rsvp-room-availability-help">' . esc_html__('Choose a date and book an available seat. Your booking details are entered in the next step.', 'rrze-rsvp') . '</p>';
        }
        $first = true;
        foreach ($months as $month => $dates) {
            $count = array_sum(array_map('count', $dates));
            /* translators: %s: Number of available time slots within a month. */
            $countLabel = sprintf(_n('%s available time slot', '%s available time slots', $count, 'rrze-rsvp'), number_format_i18n($count));
            $output .= '<details class="rsvp-room-month"' . ($first ? ' open' : '') . '><summary><span class="rsvp-room-month-title">' . esc_html(date_i18n('F Y', strtotime($month . '-01'))) . '</span><span class="rsvp-room-month-count">' . esc_html($countLabel) . '</span></summary>';
            $output .= '<ul class="rsvp-room-days">';
            foreach ($dates as $date => $slots) {
                $output .= '<li class="rsvp-room-day"><div class="rsvp-room-date"><span>' . esc_html(date_i18n('l', strtotime($date))) . '</span><time datetime="' . esc_attr($date) . '">' . esc_html(date_i18n('j. F', strtotime($date))) . '</time></div>';
                $output .= '<ul class="rsvp-room-slots">';
                ksort($slots);
                foreach ($slots as $time => $seatIds) {
                    $output .= self::renderSlot($roomId, $date, $time, $seatIds, $bookingLinks, $nonce);
                }
                $output .= '</ul></li>';
            }
            $output .= '</ul></details>';
            $first = false;
        }
        return $output . '</div>';
    }

    /**
     * Render one time slot with naturally sorted seat names and optional booking links.
     *
     * @param int $roomId Public room ID.
     * @param string $date Local ISO calendar date.
     * @param string $time Local start/end time separated by a hyphen.
     * @param array $seatIds Available public seat IDs from the shared reader.
     * @param bool $bookingLinks Whether the site's availability setting enables links.
     * @param string $nonce Existing availability nonce used to open the booking form.
     * @return string Escaped list item for this time slot.
     */
    private static function renderSlot(int $roomId, string $date, string $time, array $seatIds, bool $bookingLinks, string $nonce): string
    {
        $times = explode('-', $time);
        $output = '<li class="rsvp-room-slot"><div><strong class="rsvp-room-slot-time">' . esc_html(implode(' – ', $times)) . '</strong>';
        /* translators: %s: Number of available seats for this time slot. */
        $output .= '<span class="rsvp-room-slot-count">' . esc_html(sprintf(_n('%s seat available', '%s seats available', count($seatIds), 'rrze-rsvp'), number_format_i18n(count($seatIds)))) . '</span></div>';
        $manySeats = count($seatIds) > 4;
        if ($manySeats) {
            $context = date_i18n('l, j. F Y', strtotime($date)) . ' · ' . implode(' – ', $times);
            $output .= '<details class="rsvp-room-seat-picker" data-context="' . esc_attr($context) . '"><summary>'
                . esc_html($bookingLinks ? __('Choose a seat', 'rrze-rsvp') : __('Show seats', 'rrze-rsvp')) . '</summary>';
        }
        $output .= '<div class="rsvp-room-seat-links">';
        $seatNames = [];
        foreach ($seatIds as $seatId) {
            $seatNames[$seatId] = get_the_title($seatId);
        }
        natcasesort($seatNames);
        foreach ($seatNames as $seatId => $name) {
            if ($bookingLinks) {
                $url = add_query_arg(['room_id' => $roomId, 'seat_id' => $seatId, 'bookingdate' => $date, 'timeslot' => $times[0], 'nonce' => $nonce], get_permalink($roomId));
                /* translators: %s: Public seat name. */
                $label = sprintf(__('Book seat %s', 'rrze-rsvp'), $name);
                // Include date/time in the accessible name when several links share a seat name.
                $context = date_i18n('j. F Y', strtotime($date)) . ', ' . implode(' – ', $times);
                $output .= '<a href="' . esc_url($url) . '" data-seat-name="' . esc_attr($name) . '" aria-label="' . esc_attr($label . ', ' . $context) . '">' . esc_html($label) . '</a>';
            } else {
                $output .= '<span data-seat-name="' . esc_attr($name) . '">' . esc_html($name) . '</span>';
            }
        }
        return $output . '</div>' . ($manySeats ? '</details>' : '') . '</li>';
    }
}
