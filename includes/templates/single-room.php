<?php

use RRZE\RSVP\Functions;
use RRZE\RSVP\Helper;
use RRZE\RSVP\Settings;
use function RRZE\RSVP\plugin;

$settings = new Settings(plugin()->getFile());
$options = (object) $settings->getOptions();
global $post;

$postID = $post->ID;
$meta = get_post_meta($postID);

// Schedule
$schedule_data = Functions::getRoomSchedule($postID);
$schedule = '';
$weekdays = [
    1 => __('Monday', 'rrze-rsvp'),
    2 => __('Tuesday', 'rrze-rsvp'),
    3 => __('Wednesday', 'rrze-rsvp'),
    4 => __('Thursday', 'rrze-rsvp'),
    5 => __('Friday', 'rrze-rsvp'),
    6 => __('Saturday', 'rrze-rsvp'),
    7 => __('Sunday', 'rrze-rsvp')
];
if (!empty($schedule_data)) {
    $schedule .= '<table class="rsvp-schedule">';
    $schedule .= '<tr>'
        . '<th>'. __('Weekday', 'rrze-rsvp') . '</th>'
        . '<th>'. __('Time slots', 'rrze-rsvp') . '</th>';
    $schedule .= '</tr>';
    foreach ($schedule_data as $weekday => $daily_slots) {
        $schedule .= '<tr>'
            .'<td>' . $weekdays[$weekday] . '</td>'
            . '<td>';
        $ts = [];
        foreach ($daily_slots as $start => $end) {
            $ts[] = $start . ' - ' . $end;
        }
        $schedule .= implode('<br />', $ts);
        $schedule .= '</td>'
            . '</tr>';
    }
    $schedule .= "</table>";
} else {
    $schedule .= '<p>' . __('No schedule available.') . '</p>';
}

// Floorplan
if (isset($meta['rrze-rsvp-room-floorplan_id']) && $meta['rrze-rsvp-room-floorplan_id'] != '') {
    $img_id = $meta['rrze-rsvp-room-floorplan_id'][0];
    $floorplan = wp_get_attachment_image( $img_id, 'large');
} else {
    $floorplan = __('No floorplan available.', 'rrze-rsvp');
}


get_header();

/*
 * Ausgabe ?format=embedded&show=xyz für Public Displays
 */
if (isset($_GET['format']) && $_GET['format'] == 'embedded') {
    $width = isset($_GET['width']) ? absint($_GET['width']) : '1820';
    $height = isset($_GET['height']) ? absint($_GET['height']) : '790';
    $innerwidth = (int)$width - 20;
    $innerheight = (int)$height - 20;
    echo '<style> body.embedded {width:' . $width . 'px; height:' . $height . 'px;} </style>';

    if (isset($_GET['show'])) {
        switch ($_GET['show']) {
            case 'info':
                if (has_post_thumbnail()) {
                    echo get_the_post_thumbnail($postID, 'medium', array( "class" => "alignright" ));
                }
                echo get_the_content(null, false, $postID);
                break;
            case 'floorplan':
                echo $floorplan;
                break;
            case 'schedule':
                echo $schedule;
                break;
            case 'availability':
                echo do_shortcode('[rsvp-availability room=' . $postID . ' days=10]');
                break;
            case 'occupancy':
                echo Functions::getOccupancyByRoomIdHTML($postID);
                break;
            case 'occupancy_now':
                echo Functions::getOccupancyByRoomIdHTML($postID, true);
                break;
            default:
                echo Functions::getOccupancyByRoomIdHTML($postID, true);
                break;
        }
    }

    wp_enqueue_style('rrze-rsvp-shortcode');
    get_footer();

    return;
}

/*
 * div-/Seitenstruktur für FAU- und andere Themes
 */
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


/*
 * Eigentlicher Content
 */
echo $div_open;

while ( have_posts() ) : the_post();

    if (has_post_thumbnail()) {
        the_post_thumbnail('medium', array( "class" => "alignright" ));
    }
    the_content();

    if (isset($meta['rrze-rsvp-room-timeslots']) && !empty($meta['rrze-rsvp-room-timeslots'])) {
        echo $schedule;
    }

    echo '<h3>' . __('Room occupancy for today', 'rrze-rsvp') . '</h3>';
    echo Functions::getOccupancyByRoomIdHTML($postID);

    if ($options->general_single_room_availability_table != 'no') {
        $booking_link = '';
        if ($options->general_single_room_availability_table == 'yes_link') {
            $booking_link = 'booking_link=true';
        }
        echo '<h3>' . __('Availability', 'rrze-rsvp') . '</h3>';
        echo do_shortcode('[rsvp-availability room=' . $postID . ' days=10 '.$booking_link.']');
    }

    if (isset($meta['rrze-rsvp-room-floorplan_id']) && $meta['rrze-rsvp-room-floorplan_id'] != '') {
        echo '<h2>'. __('Floor Plan','rrze-rsvp') . '</h2>';
        $img_src = wp_get_attachment_image_src( $img_id, 'full');
        echo '<a href="' . $img_src[0] .'" class="lightbox">' . $floorplan . '</a>';
    }


endwhile;

echo $div_close;

wp_enqueue_style('rrze-rsvp-shortcode');

get_footer();
