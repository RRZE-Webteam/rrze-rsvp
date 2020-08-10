<?php

namespace RRZE\RSVP\Shortcodes;

use RRZE\RSVP\Helper;
use function RRZE\RSVP\Config\getShortcodeSettings;
// use function RRZE\RSVP\Config\getShortcodeDefaults;



defined('ABSPATH') || exit;

/**
 * Define Shortcode QR
 */
class QR extends Shortcodes {
    protected $pluginFile;
    // private $settings = '';
    private $shortcodesettings = '';

    public function __construct($pluginFile, $settings) {
        $this->pluginFile = $pluginFile;
        // $this->settings = $settings;
        $this->shortcodesettings = getShortcodeSettings();
        // $this->options = (object) $settings->getOptions();
    }


    public function onLoaded() {

        add_shortcode('rsvp-qr', [$this, 'shortcodeQR'], 10, 2);

    }

    public function shortcodeQR($atts, $content = '', $tag) {
        $shortcode_atts = parent::shortcodeAtts($atts, $tag, $this->shortcodesettings);
        $output = '';

        if (isset($shortcode_atts['seat']) && $shortcode_atts['seat'] != '') {
            $seat = sanitize_title($shortcode_atts['seat']);
            // Seat-ID über Slug
            $seat_post = get_posts([
                'name'        => $seat,
                'post_type'   => 'seat',
                'post_status' => 'publish',
                'posts_per_page ' => '1',
            ]);
            if (!empty($seat_post)) {
                $seat_id = $seat_post[0]->ID;
            } else {
                // Fallback: Seat = ID eingegeben?
                $seat_post = get_post($seat);
                if (!empty($seat_post)) {
                    $seat_id = $seat;
                } else {
                    return __( 'Please enter a valid seat slug or ID', 'rrze-rsvp' );;
                }
            }
            $permalink = get_permalink($seat_id);

            // 2DO: 
            // how to generate QR using tcpdf as image soley? 
            // $output above echo do_shortcode('[rsvp-availability seat=' . $id . ' days=14 booking_link=true]');  in single-seat.php as <img ... float right 
            // $pdf->write2DBarcode($permalink, 'QRCODE,H', '', $y + $ySpace, 50, 50, $qr_style, 'N');
            $output = ''; 
        } else {
            return __( 'Please specify a seat ID in your Shortcode.', 'rrze-rsvp' );
        }

        wp_enqueue_style('rrze-rsvp-shortcode');
        //wp_enqueue_script('rrze-rsvp-shortcode');

        return $output;
    }


}
