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

    $meta = get_post_meta(get_the_ID());
    if (has_post_thumbnail()) {
        the_post_thumbnail('medium', array( "class" => "alignright" ));
    }
    the_content();

    if (isset($meta['rrze-rsvp-room-timeslots']) && !empty($meta['rrze-rsvp-room-timeslots'])) {
        echo '<h2>'. __('Schedule','rrze-rsvp') . '</h2>';
        $timeslots = get_post_meta(get_the_ID(), 'rrze-rsvp-room-timeslots');
        $weekdays = [
            1 => __('Monday', 'rrze-rsvp'),
            2 => __('Tuesday', 'rrze-rsvp'),
            3 => __('Wednesday', 'rrze-rsvp'),
            4 => __('Thursday', 'rrze-rsvp'),
            5 => __('Friday', 'rrze-rsvp'),
            6 => __('Saturday', 'rrze-rsvp'),
            7 => __('Sunday', 'rrze-rsvp')
        ];
        echo "<table>";
        foreach ($timeslots[0] as $timeslot) {
            echo "<tr>";
            echo "<td>";
            $days = [];
            foreach ($timeslot['rrze-rsvp-room-weekday'] as $day) {
                $days[] = $weekdays[$day];
            }
            echo implode(', ', $days );
            echo "</td>";
            echo "<td>" . $timeslot['rrze-rsvp-room-starttime'] . ' - ' . $timeslot['rrze-rsvp-room-endtime'] . "</td>";
            echo "</tr>";
//                                            print "<pre>";
//                                            var_dump($timeslot);
//                                            print "</pre>";
        }
        echo "</table>";
    }

    if (isset($meta['rrze-rsvp-room-floorplan_id']) && $meta['rrze-rsvp-room-floorplan_id'] != '') {
        $img_src = wp_get_attachment_image_src( $meta['rrze-rsvp-room-floorplan_id'][0]);
        echo '<h2>'. __('Floor Plan','rrze-rsvp') . '</h2>';
        echo '<a href="' . wp_get_attachment_image_src( $img_src[0], 'full') .'" class="lightbox">' . wp_get_attachment_image( $meta['rrze-rsvp-room-floorplan_id'][0], 'large') . '</a>';
    }
endwhile;

echo $div_close;

get_footer();
