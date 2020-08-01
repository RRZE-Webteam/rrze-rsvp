<?php

namespace RRZE\RSVP\Shortcodes;

use RRZE\RSVP\Functions;
use RRZE\RSVP\Helper;
use function RRZE\RSVP\Config\getShortcodeSettings;
use function RRZE\RSVP\Config\getShortcodeDefaults;



defined('ABSPATH') || exit;

/**
 * Define Shortcode Bookings
 */
class Availability extends Shortcodes {
    protected $pluginFile;
    private $settings = '';
    private $shortcodesettings = '';

    public function __construct($pluginFile, $settings) {
        $this->pluginFile = $pluginFile;
        $this->settings = $settings;
        $this->shortcodesettings = getShortcodeSettings();
        $this->options = (object) $settings->getOptions();
    }


    public function onLoaded() {

        add_shortcode('rsvp-availability', [$this, 'shortcodeAvailability'], 10, 2);

    }


    public function shortcodeAvailability($atts, $content = '', $tag) {
        $shortcode_atts = parent::shortcodeAtts($atts, $tag, $this->shortcodesettings);
        $output = '';
        if (isset($shortcode_atts['room'])) {
            $room = (int)$shortcode_atts['room'];
        } else {
            return __( 'Please specify a room ID in your Shortcode.', 'rrze-rsvp' );
        }
        $booking_link = (isset($shortcode_atts['booking_link']) && $shortcode_atts['booking_link'] == 'true');
        $days = sanitize_text_field($shortcode_atts['days']); // kann today, tomorrow oder eine Zahl sein (kommende X Tage)
//        $seats = isset($shortcode_atts['seat']) ? explode(',', sanitize_text_field($shortcode_atts['seat'])) : [];
//        $seats = array_map('trim', $seats);
//        $seats = array_map('sanitize_title', $seats);
        $services = isset($shortcode_atts['service']) ? explode(',', sanitize_text_field($shortcode_atts['service'])) : [];
        $services = array_map('trim', $services);
        $services = array_map('sanitize_title', $services);
        $today = date('Y-m-d');
//var_dump($seats, $services);
//        $seats = get_posts([
//            'post_type' => 'seat',
//            'meta_key' => 'rrze-rsvp-seat-room',
//            'meta-value' => $room,
//            'orderby' => 'title',
//            'order' => 'ASC',
//        ]);
        $availability = Functions::getRoomAvailability($room, $today, date('Y-m-d', strtotime($today. ' +'.$days.' days')));

        $output .= '<table>';
        $output .= '<tr>'
            . '<th scope="col" width="200">' . __('Date/Time', 'rrze-rsvp') . '</th>'
            . '<th scope="col">' . __('Seats available', 'rrze-rsvp') . '</th>';
        foreach ($availability as $date => $timeslot) {
            foreach ($timeslot as $time => $seat_ids) {
//                echo Helper::get_html_var_dump(count($timeslot));
                $seat_names = [];
                $date_formatted = date_i18n('d.m.Y', strtotime($date));
                foreach ($seat_ids as $seat_id) {
                    $seat_names_raw[$seat_id] = get_the_title($seat_id);
                }
                asort($seat_names_raw);
                foreach ($seat_names_raw as $seat_id => $seat_name) {
                    $booking_link_open = '';
                    $booking_link_close = '';
                    if ($booking_link && $this->options->general_booking_page != '') {
                        $permalink = get_permalink($this->options->general_booking_page);
                        $booking_link_open = "<a href=\"$permalink?room_id=$room&seat_id=$seat_id&timeslot=$time\" title='" . __('Book this seat/timeslot now','rrze-rsvp') . "'>";
                        $booking_link_close = '</a>';
                    }
                    $seat_names[] = $booking_link_open . $seat_name . $booking_link_close;
                }

                $output .= '<tr>'
                    . '<td>' . $date_formatted . ' &nbsp;&nbsp; ' . $time . '</td>';
                $output .= '<td>' . implode(', ', $seat_names) . '</td>';
                $output .= '</tr>';
            }
        }
        $output .= '</table>';

        wp_enqueue_style('rrze-rsvp-shortcode');
        //wp_enqueue_script('rrze-rsvp-shortcode');

        return $output;
    }


}
