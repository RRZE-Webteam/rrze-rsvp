<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

$settings = new Settings(plugin()->getFile());
$options = (object) $settings->getOptions();
global $post;

$roomId = $post->ID;
$meta = get_post_meta($roomId);

// Schedule
$scheduleData = Functions::getRoomSchedule($roomId);
$schedule = '';
$weekdays = Functions::daysOfWeekAry(1);

if (!empty($scheduleData)) {
    $schedule .= '<table class="rsvp-schedule">';
    $schedule .= '<tr>'
        . '<th>' . __('Weekday', 'rrze-rsvp') . '</th>'
        . '<th>' . __('Time slots', 'rrze-rsvp') . '</th>';
    $schedule .= '</tr>';
    foreach ($scheduleData as $weekday => $dailySlots) {
        $schedule .= '<tr>'
            . '<td>' . $weekdays[$weekday] . '</td>'
            . '<td>';
        $ts = [];
        foreach ($dailySlots as $start => $end) {
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

// Floorplan ID
if (isset($meta['rrze-rsvp-room-floorplan_id']) && $meta['rrze-rsvp-room-floorplan_id'] != '') {
    $imgID = $meta['rrze-rsvp-room-floorplan_id'][0];
}

get_header();

/*
 * Ausgabe ?format=embedded&show=xyz für Public Displays
 */
if (isset($_GET['format']) && $_GET['format'] == 'embedded') {
    $width = isset($_GET['width']) ? absint($_GET['width']) : '1820';
    $height = isset($_GET['height']) ? absint($_GET['height']) : '790';
    $innerWidth = (int)$width - 20;
    $innerHeight = (int)$height - 20;
    echo '<style> body.embedded {width:' . $width . 'px; height:' . $height . 'px;} </style>';

    if (isset($_GET['show'])) {
        switch ($_GET['show']) {
            case 'info':
                if (has_post_thumbnail()) {
                    echo get_the_post_thumbnail($roomId, 'medium', array("class" => "alignright"));
                }
                echo get_the_content(null, false, $roomId);
                break;
            case 'floorplan':
                if (isset($meta['rrze-rsvp-room-floorplan_id']) && $meta['rrze-rsvp-room-floorplan_id'] != '') {
                    echo wp_get_attachment_image($imgID, [$innerWidth, $innerHeight]);
                } else {
                    echo __('No floorplan available.', 'rrze-rsvp');
                }
                break;
            case 'schedule':
                echo $schedule;
                break;
            case 'availability':
                $daysInAdvance = get_post_meta($roomId, 'rrze-rsvp-room-days-in-advance', true);
                if (empty($daysInAdvance)) {
                    $daysInAdvance = '10';
                }
                echo do_shortcode('[rsvp-availability room=' . $roomId . ' days=' . $daysInAdvance . ']');
                break;
            case 'occupancy':
                if (!empty($options->general_pdtxt)){
                    echo '<span class="rrze-rsvp-pdtxt">' . $options->general_pdtxt . '</span>';
                }
                if (!empty($meta['rrze-rsvp-room-pdtxt'][0])) {
                    echo '<span class="rrze-rsvp-room-pdtxt">' . $meta['rrze-rsvp-room-pdtxt'][0] . '</span>';
                }
                echo Functions::getOccupancyByRoomIdHTML($roomId);
                break;
            case 'occupancy_now':
                if (!empty($options->general_pdtxt)){
                    echo '<span class="rrze-rsvp-pdtxt">' . $options->general_pdtxt . '</span>';
                }
                if (!empty($meta['rrze-rsvp-room-pdtxt'][0])) {
                    echo '<span class="rrze-rsvp-room-pdtxt">' . $meta['rrze-rsvp-room-pdtxt'][0] . '</span>';
                }
                echo Functions::getOccupancyByRoomIdHTML($roomId, true);
                break;
            case 'occupancy_nextavailable':
                if (!empty($options->general_pdtxt)){
                    echo '<span class="rrze-rsvp-pdtxt">' . $options->general_pdtxt . '</span>';
                }
                if (!empty($meta['rrze-rsvp-room-pdtxt'][0])) {
                    echo '<span class="rrze-rsvp-room-pdtxt">' . $meta['rrze-rsvp-room-pdtxt'][0] . '</span>';
                }
                echo Functions::getOccupancyByRoomIdNextHTML($roomId);
                break;
            default:
                echo Functions::getOccupancyByRoomIdHTML($roomId, true);
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
    $divOpen = '<div id="content">
        <div class="container">
            <div class="row">
                <div class="col-xs-12">
                    <main id="droppoint">
                        <h1 class="screen-reader-text">' . get_the_title() . '</h1>
                        <div class="inline-box">
                            <div class="content-inline">';
    $divClose = '</div>
                        </div>
                    </main>
                </div>
            </div>
        </div>
    </div>';
} else {
    $divOpen = '<div id="content">
        <div class="container">
            <div class="row">
                <div class="col-xs-12">
                <h1 class="entry-title">' . get_the_title() . '</h1>';
    $divClose = '</div>
            </div>
        </div>
    </div>';
}


/*
 * Eigentlicher Content
 */
echo $divOpen;

while (have_posts()) : the_post();

    if (has_post_thumbnail()) {
        the_post_thumbnail('medium', array("class" => "alignright"));
    }
    the_content();

    if (isset($meta['rrze-rsvp-room-timeslots']) && !empty($meta['rrze-rsvp-room-timeslots'])) {

        if ($options->general_single_room_availability_table != 'no') {
            $booking_link = '';
            if ($options->general_single_room_availability_table == 'yes_link') {
                $booking_link = 'booking_link=true';
            }
        }
        $daysInAdvance = get_post_meta($roomId, 'rrze-rsvp-room-days-in-advance', true);
        if (empty($daysInAdvance)) {
            $daysInAdvance = '10';
        }

        if (shortcode_exists('collapsibles')) {
            $shortcode = '[collapsibles expand-all-link="true"]'
                . '[collapse title="' . __('Schedule', 'rrze-rsvp') . '" name="schedule" load="open"]'
                . $schedule
                . '[/collapse]'
                . '[collapse title="' . __('Current Room Occupancy', 'rrze-rsvp') . '" name="occupancy"]'
                . Functions::getOccupancyByRoomIdNextHTML($roomId)
                . '[/collapse]';
            if ($options->general_single_room_availability_table != 'no') {
                $bookingmode = get_post_meta($roomId, 'rrze-rsvp-room-bookingmode', true);
                if ($bookingmode != 'check-only') {
                    $shortcode .= '[collapse title="' . __('Availability', 'rrze-rsvp') . '" name="availability"]'
                        . do_shortcode('[rsvp-availability room=' . $roomId . ' days=' . $daysInAdvance . ' ' . $booking_link . ']')
                        . '[/collapse]';
                }
            }
            $shortcode .= '[/collapsibles]';
            $timetables = do_shortcode($shortcode);
        } else {
            $timetables = '<h2>' . __('Schedule', 'rrze-rsvp') . '</h2>'
                . $schedule
                //. '<h3>' . __('Room occupancy for today', 'rrze-rsvp') . '</h3>';
                . Functions::getOccupancyByRoomIdNextHTML($roomId);
            if ($options->general_single_room_availability_table != 'no') {

                $bookingmode = get_post_meta($roomId, 'rrze-rsvp-room-bookingmode', true);
                if ($bookingmode != 'check-only') {

                    $timetables .= '<h3>' . __('Availability', 'rrze-rsvp') . '</h3>'
                        . do_shortcode('[rsvp-availability room=' . $roomId . ' days=' . $daysInAdvance . ' ' . $booking_link . ']');
                }
            }
        }

        echo $timetables;
    }

    if (isset($meta['rrze-rsvp-room-floorplan_id']) && $meta['rrze-rsvp-room-floorplan_id']  != '') {
        echo '<h2>' . __('Floor Plan', 'rrze-rsvp') . '</h2>';
        $imgSrc = wp_get_attachment_image_src($imgID, 'full');
        $floorplan = wp_get_attachment_image($imgID, 'large');
        echo '<a href="' . $imgSrc[0] . '" class="lightbox">' . $floorplan . '</a>';
    }


endwhile;

echo $divClose;

wp_enqueue_style('rrze-rsvp-shortcode');

get_footer();
