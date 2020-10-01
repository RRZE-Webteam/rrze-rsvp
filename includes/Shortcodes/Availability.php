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
class Availability extends Shortcodes
{
    protected $pluginFile;
    private $settings = '';
    private $shortcodesettings = '';

    public function __construct($pluginFile, $settings)
    {
        $this->pluginFile = $pluginFile;
        $this->settings = $settings;
        $this->shortcodesettings = getShortcodeSettings();
        $this->options = (object) $settings->getOptions();
    }


    public function onLoaded()
    {

        add_shortcode('rsvp-availability', [$this, 'shortcodeAvailability'], 10, 2);
    }

    public function shortcodeAvailability($atts, $content = '', $tag)
    {
        $shortcode_atts = parent::shortcodeAtts($atts, $tag, $this->shortcodesettings);
        $output = '';
        $today = date('Y-m-d');
        // Auskommentiert wg. Bug https://github.com/RRZE-Webteam/rrze-rsvp/issues/164
        //$nonce = wp_create_nonce('rsvp-availability');
        $booking_link = (isset($shortcode_atts['booking_link']) && Functions::getBoolValueFromAtt($shortcode_atts['booking_link']));
        $days = sanitize_text_field($shortcode_atts['days']); // kann today, tomorrow oder eine Zahl sein (kommende X Tage)

        if (isset($shortcode_atts['room']) && $shortcode_atts['room'] != '') {
            $room = (int)$shortcode_atts['room'];
            $bookingmode = get_post_meta($room, 'rrze-rsvp-room-bookingmode', true);
            $availability = Functions::getRoomAvailability($room, $today, date('Y-m-d', strtotime($today . ' +' . $days . ' days')), false);
            if (!empty($availability)) {
                $output .= '<table class="rsvp-room-availability">';
                $output .= '<tr>'
                    . '<th scope="col" width="200">' . __('Date/Time', 'rrze-rsvp') . '</th>'
                    . '<th scope="col">' . __('Seats available', 'rrze-rsvp') . '</th>';
                foreach ($availability as $date => $timeslot) {
                    foreach ($timeslot as $time => $seat_ids) {
                        $seat_names = [];
                        $date_formatted = date_i18n('d.m.Y', strtotime($date));
                        $seat_names_raw = [];
                        foreach ($seat_ids as $seat_id) {
                            $seat_names_raw[$seat_id] = get_the_title($seat_id);
                        }
                        asort($seat_names_raw);
                        foreach ($seat_names_raw as $seat_id => $seat_name) {
                            $booking_link_open = '';
                            $booking_link_close = '';
                            $glue = ', ';
                            if ($booking_link) {
                                $permalink = get_permalink($room);
                                $timeslot = explode('-', $time)[0];
                                // Auskommentiert wg. Bug https://github.com/RRZE-Webteam/rrze-rsvp/issues/164
                                //$booking_link_open = "<a href=\"$permalink?room_id=$room&seat_id=$seat_id&bookingdate=$date&timeslot=$timeslot&nonce=$nonce\" title='" . __('Book this seat/timeslot now', 'rrze-rsvp') . "' class='seat-link'>";
                                $booking_link_open = "<a href=\"$permalink?room_id=$room&seat_id=$seat_id&bookingdate=$date&timeslot=$timeslot\" title='" . __('Book this seat/timeslot now', 'rrze-rsvp') . "' class='seat-link'>";
                                $booking_link_close = '</a>';
                                $glue = '';
                            }
                            $seat_names[] = $booking_link_open . $seat_name . $booking_link_close;
                        }

                        $output .= '<tr>'
                            . '<td>' . $date_formatted . ' &nbsp;&nbsp; ' . $time . '</td>';
                        $output .= '<td>' . implode($glue, $seat_names) . '</td>';
                        $output .= '</tr>';
                    }
                }
                $output .= '</table>';
            } else {
                $output .= '<p>' . __('No seats available.', 'rrze-rsvp') . '</p>';
            }
        } elseif (isset($shortcode_atts['seat']) && $shortcode_atts['seat'] != '') {
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
            $room_id = get_post_meta($seat_id, 'rrze-rsvp-seat-room', true);

            $availability = Functions::getSeatAvailability($seat_id, $today, date('Y-m-d', strtotime($today . ' +' . $days . ' days')), false);

            if (empty($availability)) {
                return __('No timeslots available for this seat.', 'rrze-rsvp');
            } else {
                $output .= '<div class="rrze-rsvp">'
                    . '<table class="rsvp-seat-availability">'
                    . '<th scope="col" width="200">' . __('Date', 'rrze-rsvp') . '</th>'
                    . '<th scope="col">' . __('Available Time Slots', 'rrze-rsvp') . '</th>';
                foreach ($availability as $date => $timeslots) {
                    $time_output = [];
                    foreach ($timeslots as $time) {
                        $booking_link_open = '';
                        $booking_link_close = '';
                        $glue = ', &nbsp; ';
                        if ($booking_link) {
                            $permalink = get_permalink($room_id);
                            $timeslot = explode('-', $time)[0];
                            $booking_link_open = "<a href=\"$permalink?room_id=$room_id&seat_id=$seat_id&bookingdate=$date&timeslot=$timeslot&nonce=$nonce\" title='" . __('Book this seat/timeslot now', 'rrze-rsvp') . "'>";
                            $booking_link_close = '</a>';
                            $glue = '';
                        }
                        $time_output[] = $booking_link_open . $time . $booking_link_close;
                    }
                    $output .= '<tr>'
                        . '<td>' . $date_formatted = date_i18n('d.m.Y', strtotime($date)) . '</td>'
                        . '<td>' . implode($glue, $time_output) . '</td>'
                        . '</tr>';
                }
                $output .= '</table>'
                    . '</div>';
            }
        } else {
            return __('Please specify a room ID in your Shortcode.', 'rrze-rsvp');
        }


        wp_enqueue_style('rrze-rsvp-shortcode');
        //wp_enqueue_script('rrze-rsvp-shortcode');

        return $output;
    }
}
