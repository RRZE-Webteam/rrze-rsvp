<?php

use RRZE\RSVP\Helper;

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
    $now = current_time('timestamp');
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

    foreach ($bookings as $booking) {
        $status = get_post_meta($booking->ID, 'rrze-rsvp-booking-status', true);
        switch ($status ) {
            case 'confirmed':
                echo '<p>' . __('This seat is currently reserved. If you have reserved this seat, <strong>please check in</strong>.', 'rrze-rsvp') . '</p>';
                echo '<p><button class="btn btn-success btn-lg btn-block">' . __('Check in', 'rrze-rsvp') . '</button></p><hr />';
                break;
            case 'checked-in':
                echo '<p>' . __('Thank you for checking in. Please remember to <strong>check out before leaving!</strong>', 'rrze-rsvp') . '</p>';
                echo '<p><button class="btn btn-danger btn-lg btn-block">' . __('Check out', 'rrze-rsvp') . '</button></p><hr />';
                break;
            default:
        }
    }
    echo '';
//    echo Helper::get_html_var_dump($bookings);

    echo '<h3>' . __('Book this seat', 'rrze-rsvp') . '</h3>';
    echo do_shortcode('[rsvp-availability seat=' . $id . ' days=14 booking_link=true]');

endwhile;

echo $div_close;

get_footer();
