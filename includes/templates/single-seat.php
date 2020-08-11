<?php

use RRZE\RSVP\Helper;
use RRZE\RSVP\Settings;
use RRZE\RSVP\Functions;
use function RRZE\RSVP\plugin;

$settings = new Settings(plugin()->getFile());
$options = (object) $settings->getOptions();

get_header();

if (Helper::isFauTheme()) {
    get_template_part('template-parts/hero', 'small');
    $div_open = '<div id="content">
        <div class="container">
            <div class="row">
                <div class="col-xs-12">
                    <main id="droppoint">
                        <h1 class="screen-reader-text">' . get_the_title() . '</h1>
                        <div class="inline-box">
                            <div class="content-inline">';
    $div_close = '</div>
                        </div>
                    </main>
                </div>
            </div>
        </div>
    </div>';
} else {
    $div_open = '<div id="content">
        <div class="container">
            <div class="row">
                <div class="col-xs-12">
                <h1 class="entry-title">' . get_the_title() . '</h1>';
    $div_close = '</div>
            </div>
        </div>
    </div>';
}



echo $div_open;

while ( have_posts() ) : the_post();
    $id = get_the_ID();
    $room_id = get_post_meta($id, 'rrze-rsvp-seat-room', true);
    $now = current_time('timestamp');

    echo '<p><strong>' . __('Room', 'rrze-rsvp') . ':</strong> <a href="' . get_permalink($room_id) . '">' . get_the_title($room_id) . '</a>';

    // Array aus bereits gebuchten Plätzen im Zeitraum erstellen
    $bookings = get_posts([
        'post_type' => 'booking',
        'post_status' => 'publish',
        'nopaging' => true,
        'meta_query' => [
            [
                'key' => 'rrze-rsvp-booking-seat',
                'value'   => $id,
            ],
            [
                'key'     => 'rrze-rsvp-booking-start',
                'value' => $now,
                'compare' => '<=',
                'type' => 'numeric'
            ],
            [
                'key'     => 'rrze-rsvp-booking-end',
                'value' => $now,
                'compare' => '>=',
                'type' => 'numeric'
            ],
        ],
    ]);

    $status = false;
    foreach ($bookings as $booking) {
        $status = get_post_meta($booking->ID, 'rrze-rsvp-booking-status', true);
    }

    switch ($status) {
        case 'confirmed':
            echo '<p>' . __('This seat is currently reserved. If you have reserved this seat, <strong>please check in</strong>.', 'rrze-rsvp') . '</p>';
            echo '<p><button class="btn btn-success btn-lg btn-block">' . __('Check in', 'rrze-rsvp') . '</button></p><hr />';
            break;
        case 'checked-in':
            echo '<p>' . __('Seat checked in. Please remember to <strong>check out before leaving!</strong>', 'rrze-rsvp') . '</p>';
            echo '<p><button class="btn btn-danger btn-lg btn-block">' . __('Check out', 'rrze-rsvp') . '</button></p><hr />';
            break;
        default:
    }

    if (!$status && $options->general_booking_page > 0) {
        $allow_instant = get_post_meta($room_id, 'rrze-rsvp-room-instant-check-in', true);

        if ($allow_instant == 'on') {
            $timestamp = current_time('timestamp');
            $day = date('Y-m-d', $timestamp);
            $time = date('H:i', $timestamp);
            $weekday = date('N', $timestamp);
            $booking_start = '';
            $schedule = Functions::getRoomSchedule($room_id);
            foreach ($schedule as $wday => $starttimes) {
                if ($wday == $weekday) {
                    asort($starttimes);
                    foreach ($starttimes as $starttime => $endtime) {
                        if ($endtime > $time && $starttime <= $time) {
                            $booking_start = $starttime;
                        }
                    }
                }
            }

            if ($booking_start != '') {
                $url = get_permalink($options->general_booking_page) . "?room_id=$room_id&seat_id=$id&bookingdate=$day&timeslot=$booking_start&instant=1";
                echo '<p>' . sprintf(__('This seat is %sfree for instant check-in%s (booking and check-in in one step) for the current timeslot.', 'rrze-rsvp'), '<strong>', '</strong>') . '</p>';
                echo '<p><a class="btn btn-success btn-lg btn-block" href="' . $url . '">' . __('Instant check-in', 'rrze-rsvp') . '</a></p><hr />';
            }
        }
    }

    echo '<h3>' . __('Book this seat', 'rrze-rsvp') . '</h3>';
    
    echo do_shortcode('[rsvp-qr seat=' . $id . ']');
    echo do_shortcode('[rsvp-availability seat=' . $id . ' days=14 booking_link=true]');

endwhile;

echo $div_close;

get_footer();
