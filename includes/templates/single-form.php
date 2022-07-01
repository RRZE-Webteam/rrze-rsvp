<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

$roomId = isset($_REQUEST['room_id']) ? absint($_REQUEST['room_id']) : null;
$roomId = $roomId ? sprintf(' room=%d', $roomId) : '';

$shortcodeOutput = do_shortcode(sprintf('[rsvp-booking%s]', $roomId));

get_header();

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

echo $shortcodeOutput;

echo $divClose;

wp_enqueue_style('rrze-rsvp-shortcode');

get_footer();
