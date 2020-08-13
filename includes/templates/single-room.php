<?php

use RRZE\RSVP\Functions;
use RRZE\RSVP\Helper;
use RRZE\RSVP\Settings;
use function RRZE\RSVP\plugin;

$settings = new Settings(plugin()->getFile());
$options = (object) $settings->getOptions();
global $post;

get_header();

//TODO: Format embedded
if (isset($_GET['format']) && $_GET['format'] == 'embedded') {
    echo '<style>
    body {
        width: 1820px;
        height: 590px;
        overflow-x: hidden;
        overflow-y: hidden;
        padding: 20px;
    }
    html .admin-bar {
        margin-top: 0 !important;
    }
    div[role="navigation"],
    #header {
        display: none;
    }
    </style>';

    while ( have_posts() ) : the_post();
        echo Functions::getOccupancyByRoomIdHTML(get_the_ID());
    endwhile;

    return;
}

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

    $meta = get_post_meta(get_the_ID());
    if (has_post_thumbnail()) {
        the_post_thumbnail('medium', array( "class" => "alignright" ));
    }
    the_content();

    if (isset($meta['rrze-rsvp-room-timeslots']) && !empty($meta['rrze-rsvp-room-timeslots'])) {
        $schedule = [];
        echo '<h2>'. __('Schedule','rrze-rsvp') . '</h2>';
        $timeslots = get_post_meta(get_the_ID(), 'rrze-rsvp-room-timeslots', true);
        $weekdays = [
            1 => __('Monday', 'rrze-rsvp'),
            2 => __('Tuesday', 'rrze-rsvp'),
            3 => __('Wednesday', 'rrze-rsvp'),
            4 => __('Thursday', 'rrze-rsvp'),
            5 => __('Friday', 'rrze-rsvp'),
            6 => __('Saturday', 'rrze-rsvp'),
            7 => __('Sunday', 'rrze-rsvp')
        ];
        foreach ($timeslots as $timeslot) {
            foreach ($timeslot['rrze-rsvp-room-weekday'] as $day) {
                if (isset($timeslot['rrze-rsvp-room-starttime']) && isset($timeslot['rrze-rsvp-room-endtime'])) {
                    $schedule[$weekdays[$day]][] = $timeslot['rrze-rsvp-room-starttime'] . ' - ' . $timeslot['rrze-rsvp-room-endtime'];
                }
            }
        }
        if (!empty($schedule)) {
            echo '<table class="rsvp-schedule">';
            echo '<tr>'
                . '<th>'. __('Weekday', 'rrze-rsvp') . '</th>'
                . '<th>'. __('Time slots', 'rrze-rsvp') . '</th>';

            echo '</tr>';
            foreach ($schedule as $weekday => $daily_slots) {
                echo '<tr>'
                    .'<td>' . $weekday . '</td>'
                    . '<td>' . implode('<br />', $daily_slots) . '</td>'
                    . '</tr>';
            }
            echo "</table>";
        } else {
            echo '<p>' . __('No schedule available.') . '</p>';
        }

    }

    if ($options->general_single_room_availability_table != 'no') {
        $booking_link = '';
        if ($options->general_single_room_availability_table == 'yes_link') {
            $booking_link = 'booking_link=true';
        }
        echo '<h3>' . __('Availability', 'rrze-rsvp') . '</h3>';
        echo do_shortcode('[rsvp-availability room=' . $post->ID . ' days=10 '.$booking_link.']');
    }

    if (isset($meta['rrze-rsvp-room-floorplan_id']) && $meta['rrze-rsvp-room-floorplan_id'] != '') {
        $img_src = wp_get_attachment_image_src( $meta['rrze-rsvp-room-floorplan_id'][0]);
        echo '<h2>'. __('Floor Plan','rrze-rsvp') . '</h2>';
        echo '<a href="' . wp_get_attachment_image_src( $img_src[0], 'full') .'" class="lightbox">' . wp_get_attachment_image( $meta['rrze-rsvp-room-floorplan_id'][0], 'large') . '</a>';
    }
endwhile;

echo $div_close;

get_footer();
