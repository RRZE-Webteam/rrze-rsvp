<?php

namespace RRZE\RSVP\Shortcodes;

defined('ABSPATH') || exit;

use TCPDF2DBarcode;
use function RRZE\RSVP\Config\getShortcodeSettings;

/**
 * Define Shortcode QR
 */
class QR extends Shortcodes
{
    private $shortcodesettings = '';

    public function __construct()
    {
        $this->shortcodesettings = getShortcodeSettings();

        add_shortcode('rsvp-qr', [$this, 'shortcodeQR']);
    }

    public function shortcodeQR($atts, $content = '', $tag = '')
    {
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
                    return __('Please enter a valid seat slug or ID', 'rrze-rsvp');;
                }
            }
            $permalink = get_permalink($seat_id);

            $qr = new TCPDF2DBarcode($permalink, 'QRCODE,H');
            $output = '<div class="rsvp-qr-container">' . $qr->getBarcodeSVGcode(3, 3, $color = 'black') . '</div>';
        } else {
            return __('Please specify a seat ID in your Shortcode.', 'rrze-rsvp');
        }

        wp_enqueue_style('rrze-rsvp-shortcode');
        //wp_enqueue_script('rrze-rsvp-shortcode');

        return $output;
    }
}
