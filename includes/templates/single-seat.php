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

$id = get_the_ID();
$meta = get_post_meta($id);
$room_id = $meta['rrze-rsvp-seat-room'][0];

echo $div_open;

while ( have_posts() ) : the_post(); ?>

    <p><button class="btn btn-success btn-lg btn-block"><?php _e('Check in', 'rrze-rsvp');?></button></p>
    <p><button class="btn btn-danger btn-lg btn-block"><?php _e('Check out', 'rrze-rsvp');?></button></p>

<?php

    echo '<h3>' . __('Book this seat', 'rrze-rsvp') . '</h3>';
    echo do_shortcode('[rsvp-availability seat=' . $id . ' days=14 booking_link=true]');

endwhile;

echo $div_close;

get_footer();
