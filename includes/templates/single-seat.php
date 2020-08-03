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
            'value' => time(),
            'compare' => '<=',
            'type' => 'numeric'
        ],
        [
            'key'     => 'rrze-rsvp-booking-end',
            'value' => time(),
            'compare' => '>=',
            'type' => 'numeric'
        ],
    ],
]);

$id = get_the_ID();
$meta = get_post_meta($id);
$room_id = $meta['rrze-rsvp-seat-room'][0];

echo $div_open;

while ( have_posts() ) : the_post();
//var_dump($bookings);
//    if (!empty($bookings)) {
//
//    }

?>

    <p><button class="btn btn-success btn-lg btn-block"><?php _e('Check in', 'rrze-rsvp');?></button></p>
    <p><button class="btn btn-danger btn-lg btn-block"><?php _e('Check out', 'rrze-rsvp');?></button></p>
    <p><a class="btn btn-primary btn-lg btn-block" role="button" href="../../rsvp?room_id=<?php echo $room_id; ?>&seat_id=<?php echo $id; ?>"><?php _e('Book this seat', 'rrze-rsvp');?></a></p>

<?php

    echo '<h3>' . __('Availability', 'rrze-rsvp') . '</h3>';
    echo do_shortcode('[rsvp-availability seat=' . $id . ' days=14 booking_link=true]');

endwhile;

echo $div_close;

get_footer();
