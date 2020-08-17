<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use DateTime;

class Functions
{
    public static function dateFormat(int $timestamp): string
    {
        return date_i18n(get_option('date_format'), $timestamp);
    }

    public static function timeFormat(int $timestamp): string
    {
        return date_i18n(get_option('time_format'), $timestamp);
    }

    public static function validateDate(string $date, string $format = 'Y-m-d'): bool
    {
        $dt = DateTime::createFromFormat($format, $date);
        return $dt && $dt->format($format) === $date;
    }

    public static function validateTime(string $date, string $format = 'H:i:s'): bool
    {
        return self::validateDate($date, $format);
    }

    public static function isLocaleEnglish()
    {
        $locale = get_locale();
        return (strpos($locale, 'en_') === 0);
    }

    public static function hasShortcodeSSO(string $shortcode): bool
    {
        global $post;
        if (is_a($post, '\WP_Post') && has_shortcode($post->post_content, $shortcode)) {
            $result = [];
            $pattern = get_shortcode_regex();
            if (preg_match_all('/' . $pattern . '/s', $post->post_content, $matches)) {
                $keys = [];
                $result = [];
                foreach ($matches[0] as $key => $value) {
                    $get = str_replace(" ", "&", $matches[3][$key]);
                    parse_str($get, $output);
                    $keys = array_unique(array_merge($keys, array_keys($output)));
                    $result[][$matches[2][$key]] = $output;
                }
            }
            foreach ($result as $key => $value) {
                if (isset($value[$shortcode]) && !empty($value[$shortcode]['sso'])) {
                    return true;
                }
            }
        }
        return false;
    }



    /**
     * getOccupancyByRoomIdHTML
     * calls getOccupancyByRoomId and returns an HTML table with room's occupancy for today 
     * @param int $room_id (the room's post id)
     * @return string
     */
    public static function getOccupancyByRoomIdHTML(int $room_id): string
    {
        $output = '<table class="rsvp-room-occupancy"><tr>';

        $seats_slots = self::getOccupancyByRoomId($room_id);

        if ($seats_slots){
            $output .= '<th>' . __( 'Seat', 'rrze-rsvp' ) . '</th>';
            foreach($seats_slots['room_slots'] as $room_slot){
                $output .= '<th scope="col"><span class="rrze-rsvp-timespan">' . $room_slot . '</span></th>';
            }
            $output .= '</tr>';
            unset($seats_slots['room_slots']);

            foreach($seats_slots as $seat_id => $aSlots){
                $output .= '<tr>';
                $output .= '<th scope="row">' . get_the_title( $seat_id ) . '</th>';
                foreach($aSlots as $slot => $free){
                    $class = $free?'available':'not-available';
                    $output .= '<td><span class="'.$class.'">' . ($free?'available':'not available') . '</span></td>';
                }
                $output .= '</tr>';
            }
        }else{
            $output .= '<td>' . __('This room has no seats.', 'rrze-rsvp') . '</td>';
        }
        $output .= '</table>';

        return $output;
    }

    /**
     * getOccupancyByRoomId
     * Returns an array('room_slots' with all timeslot-spans for this room, seat_id => array(timeslot-span => true/false)) for today
     * Example: given room has 2 seats; 1 seat is not available at 09:30-10:30 
     *          returns: array(3) { ["room_slots"]=> array(3) { [0]=> string(13) "08:15 - 09:15" [1]=> string(13) "09:30 - 10:30" [2]=> string(13) "11:05 - 12:10" } [2244487]=> array(3) { ["08:15-09:15"]=> bool(true) ["09:30-10:30"]=> bool(true) ["11:05-12:10"]=> bool(true) } [1903]=> array(3) { ["08:15-09:15"]=> bool(true) ["09:30-10:30"]=> bool(false) ["11:05-12:10"]=> bool(true) } }
     * @param int $room_id (the room's post id)
     * @return array
     */
    public static function getOccupancyByRoomId(int $room_id): array
    {
        $data = [];

        $timestamp = current_time('timestamp');
        $today = date('Y-m-d', $timestamp);
        $today_weeknumber = date('N', $timestamp);

        // get timeslots for today for this room
        $slots = self::getRoomSchedule($room_id); // liefert [wochentag-nummer][startzeit] = end-zeit;
        $slots_today_tmp = (isset($slots[$today_weeknumber]) ? $slots[$today_weeknumber] : []);
        $slots_today = [];
        foreach($slots_today_tmp as $start => $end){
            $slots_today[] = $start . '-' . $end;
            $data['room_slots'][] =  $start . ' - ' . $end;
        }

        // get seats for this room
        $seatIds = get_posts([
            'post_type' => 'seat',
            'post_status' => 'publish',
            'nopaging' => true,
            'meta_key' => 'rrze-rsvp-seat-room',
            'meta_value' => $room_id,
            'fields' => 'ids',
            'orderby'=> 'title', 
            'order' => 'ASC'
        ]);

        foreach ($seatIds as $seat_id) {
            $slots_free = self::getSeatAvailability($seat_id, $today, $today);
            $slots_free_today_tmp = ( isset($slots_free[$today]) ? $slots_free[$today] : [] );
            $slots_free_today = array_combine($slots_free_today_tmp, $slots_free_today_tmp); // set values to keys

            foreach($slots_today as $timespan){
                $data[$seat_id][$timespan] = (isset($slots_free_today[$timespan])?true:false);
            }

        }
        return $data;
    }


    public static function getBooking(int $bookingId): array
    {
        $data = [];

        $post = get_post($bookingId);
        if (!$post) {
            return $data;
        }

        $data['id'] = $post->ID;
        $data['status'] = get_post_meta($post->ID, 'rrze-rsvp-booking-status', true);
        $data['start'] = absint(get_post_meta($post->ID, 'rrze-rsvp-booking-start', true));
        $start = new Carbon(date('Y-m-d H:i:s', $data['start']), wp_timezone());
        $end = absint(get_post_meta($post->ID, 'rrze-rsvp-booking-end', true));
        $data['end'] = $end ? $end : $start->endOfDay()->getTimestamp();
        $data['date'] = self::dateFormat($data['start']);
        $data['time'] = self::timeFormat($data['start']) . ' - ' . self::timeFormat($data['end']);
        $data['date_en'] = date('F j, Y', $data['start']);
        $data['time_en'] = date('g:i a', $data['start']) . ' - ' . date('g:i a', $data['end']);

        $data['booking_date'] = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($post->post_date));

        $data['seat'] = get_post_meta($post->ID, 'rrze-rsvp-booking-seat', true);
        $data['seat_name'] = !empty($data['seat']) ? get_the_title($data['seat']) : '';

        $data['room'] = get_post_meta($data['seat'], 'rrze-rsvp-seat-room', true);
        $data['room_name'] = get_the_title($data['room']);

        $data['notes'] = get_post_meta($post->ID, 'rrze-rsvp-booking-notes', true);

        $data['guest_firstname'] = get_post_meta($post->ID, 'rrze-rsvp-booking-guest-firstname', true);
        $data['guest_lastname'] = get_post_meta($post->ID, 'rrze-rsvp-booking-guest-lastname', true);
        $data['guest_email'] = get_post_meta($post->ID, 'rrze-rsvp-booking-guest-email', true);
        $data['guest_phone'] = get_post_meta($post->ID, 'rrze-rsvp-booking-guest-phone', true);

        return $data;
    }

    public static function bookingReplyUrl(string $action, string $password, int $id): string
    {
        //$hash = password_hash($password, PASSWORD_DEFAULT);
        $hash = self::crypt($password);
        return get_site_url() . "/?rrze-rsvp-booking-reply=" . $hash . "&id=" . $id . "&action=" . $action;
    }

    public static function crypt(string $string, string $action = 'encrypt')
    {
        $secretKey = AUTH_KEY;
        $secretSalt = AUTH_SALT;

        $output = false;
        $encryptMethod = 'AES-256-CBC';
        $key = hash('sha256', $secretKey);
        $salt = substr(hash('sha256', $secretSalt), 0, 16);

        if ($action == 'encrypt') {
            $output = base64_encode(openssl_encrypt($string, $encryptMethod, $key, 0, $salt));
        } else if ($action == 'decrypt') {
            $output = openssl_decrypt(base64_decode($string), $encryptMethod, $key, 0, $salt);
        }

        return $output;
    }

    public static function decrypt(string $string)
    {
        return self::crypt($string, 'decrypt');
    }

    /**
     * getRoomAvailability
     * Returns an array of dates/timeslots/seats available, for a defined period.
     * Array structure: date => timeslot => seat IDs
     * @param string $room the room's post id
     * @param string $start start date of the period (format 'Y-m-d')
     * @param string $end end date of the period (format 'Y-m-d')
     * @return array ['date(Y-m-d)']['timeslot(H:i-H:i)'] = [seat_id, seat_id...]
     */
    public static function getRoomAvailability($room_id, $start, $end)
    {
        $availability = [];
        $room_availability = [];
        // Array aus verfügbaren Timeslots des Raumes erstellen
        $timeslots = get_post_meta($room_id, 'rrze-rsvp-room-timeslots', true);
        $slots = self::getRoomSchedule($room_id);
        // Array aus bereits gebuchten Plätzen im Zeitraum erstellen
        $seats = get_posts([
            'post_type' => 'seat',
            'post_status' => 'publish',
            'nopaging' => true,
            'meta_key' => 'rrze-rsvp-seat-room',
            'meta_value' => $room_id,
        ]);
        $seat_ids = [];
        $seats_booked = [];
        if ($start == $end) {
            $end = date('Y-m-d H:i', strtotime($start . ' +23 hours, +59 minutes'));
        }
        foreach ($seats as $seat) {
            $seat_ids[] = $seat->ID;
            $bookings = get_posts([
                'post_type' => 'booking',
                'post_status' => 'publish',
                'nopaging' => true,
                'meta_query' => [
                    [
                        'key' => 'rrze-rsvp-booking-seat',
                        'value'   => $seat->ID,
                    ],
                    [
                        'key' => 'rrze-rsvp-booking-status',
                        'value'   => ['confirmed', 'checked-in'],
                        'compare' => 'IN'
                    ],
                    [
                        'key'     => 'rrze-rsvp-booking-start',
                        'value' => array(strtotime($start), strtotime($end)),
                        'compare' => 'BETWEEN',
                        'type' => 'numeric'
                    ],
                ],
            ]);
            foreach ($bookings as $booking) {
                $booking_meta = get_post_meta($booking->ID);
                $booking_start = $booking_meta['rrze-rsvp-booking-start'][0];
                //$seats_booked[$seat->ID][date('Y-m-d', $booking_date)] = $booking_time;
                $seats_booked[$booking_start][] = $seat->ID;
            }
        }

        // Tageweise durch den Zeitraum loopen, um die Verfügbarkeit je Wochentag zu ermitteln
        $loopstart = strtotime($start);
        $loopend = strtotime($end);
        while ($loopstart <= $loopend) {
            $weekday = date('w', $loopstart);
            if (isset($slots[$weekday])) {
                foreach ($slots[$weekday] as $time => $endtime) {
                    $time_parts = explode(':', $time);
                    $room_availability[strtotime('+' . $time_parts[0] . ' hours, + ' . $time_parts[1] . ' minutes', $loopstart)] = $seat_ids;
                }
            }
            $loopstart = strtotime("+1 day", $loopstart);
        }

        // Bereits gebuchte Plätze aus Array $room_availability entfernen
        foreach ($seats_booked as $timestamp => $v) {
            foreach ($v as $k => $seat_booked) {
                if (isset($room_availability[$timestamp])) {
                    $key = array_search($seat_booked, $room_availability[$timestamp]);
                    if ($key !== false) {
                        unset($room_availability[$timestamp][$key]);
                    }
                    if (empty($room_availability[$timestamp])) {
                        unset($room_availability[$timestamp]);
                    }
                }
            }
        }

        // Für Kalender aus Array-Ebene Timestamp zwei Ebenen (Tag / Zeit) machen
        foreach ($room_availability as $timestamp => $v) {
            $weekday = (date('w', $timestamp));
            $start = date('H:i', $timestamp);
            $end = $slots[$weekday][$start];

            $availability[date('Y-m-d', $timestamp)][$start . '-' . $end] = $v;
        }

        return $availability;
    }

    /**
     * getSeatAvailability
     * Returns an array of dates/timeslots where the seat is available, for a defined period.
     * Array structure: date => timeslot
     * @param string $room the seat's post id
     * @param string $start start date of the period (format 'Y-m-d')
     * @param string $end end date of the period (format 'Y-m-d')
     * @return array ['date(Y-m-d)'] => ['start(H:i) - end(H:i)', 'start(H:i) - end(H:i)'...]
     */
    public static function getSeatAvailability($seat, $start, $end)
    {
        $availability = [];
        $seat_availability = [];
        $timeslots_booked = [];

        // Array aus verfügbaren Timeslots des Raumes erstellen
        $room_id = get_post_meta($seat, 'rrze-rsvp-seat-room', true);
        $slots = self::getRoomSchedule($room_id);
        // Array aus bereits gebuchten Plätzen im Zeitraum erstellen
        if ($start == $end) {
            $end = date('Y-m-d H:i', strtotime($start . ' +23 hours, +59 minutes'));
        }
        $bookings = get_posts([
            'post_type' => 'booking',
            'post_status' => 'publish',
            'nopaging' => true,
            'meta_query' => [
                [
                    'key' => 'rrze-rsvp-booking-seat',
                    'value'   => $seat,
                ],
                [
                    'key' => 'rrze-rsvp-booking-status',
                    'value'   => ['confirmed', 'checked-in'],
                    'compare' => 'IN'
                ],
                [
                    'key'     => 'rrze-rsvp-booking-start',
                    'value' => array(strtotime($start), strtotime($end)),
                    'compare' => 'BETWEEN',
                    'type' => 'numeric'
                ],
            ],
        ]);

        foreach ($bookings as $booking) {
            $booking_meta = get_post_meta($booking->ID);
            $booking_start = $booking_meta['rrze-rsvp-booking-start'][0];
            $timeslots_booked[] = $booking_start;
        }

        // Tageweise durch den Zeitraum loopen, um die Verfügbarkeit je Wochentag zu ermitteln
        $loopstart = strtotime($start);
        $loopend = strtotime($end);
        while ($loopstart <= $loopend) {
            $weekday = date('w', $loopstart);
            if (isset($slots[$weekday])) {
                foreach ($slots[$weekday] as $starttime  => $endtime) {
                    $start_parts = explode(':', $starttime);
                    $end_parts = explode(':', $endtime);
                    $timestamp = strtotime('+' . $start_parts[0] . ' hours, + ' . $start_parts[1] . ' minutes', $loopstart);
                    $timestamp_end = strtotime('+' . $end_parts[0] . ' hours, + ' . $end_parts[1] . ' minutes', $loopstart);
                    if (!in_array($timestamp, $timeslots_booked)) {
                        $seat_availability[$timestamp] = $timestamp_end;
                    }
                }
            }
            $loopstart = strtotime("+1 day", $loopstart);
        }

        // Für Ausgabe Timestamp zwei Ebenen (Tag / Zeit) machen
        foreach ($seat_availability as $timestamp => $timestamp_end) {
            $availability[date('Y-m-d', $timestamp)][] = date('H:i', $timestamp) . '-' . date('H:i', $timestamp_end);
        }

        return $availability;
    }

    /**
     * getPagesDropdownOptions
     * Returns an array of post_id => post_title that can be used by settings select callback.
     * Reduced version of wp_dropdown_pages()
     * @param array $args
     * @return array page_id => page_title
     */
    public static function getPagesDropdownOptions($args = '')
    {
        $defaults = array(
            'depth' => 0,
            'child_of' => 0,
            'show_option_none' => '',
            'show_option_no_change' => '',
            'option_none_value' => '',
            'sort_column' => 'post_title',
        );
        $parsed_args = wp_parse_args($args, $defaults);
        $pages = get_pages($parsed_args);

        $output = [];
        if (!empty($pages)) {
            if ($parsed_args['show_option_no_change']) {
                $output['-1'] = $parsed_args['show_option_no_change'];
            }
            if ($parsed_args['show_option_none']) {
                $output[esc_attr($parsed_args['option_none_value'])] = $parsed_args['show_option_none'];
            }
            foreach ($pages as $page) {
                $output[$page->ID] = $page->post_title;
            }
        }
        return $output;
    }

    /**
     * getRoomSchedule
     * Returns an array of timeslots per weekday for a specific room.
     * @param int $room_id
     * @return array [weekday_number(1-7)][starttime(H:i)] => endtime(H:i)
     */
    public static function getRoomSchedule($room_id)
    {
        $schedule = [];
        $room_timeslots = get_post_meta($room_id, 'rrze-rsvp-room-timeslots', true);
        if (is_array($room_timeslots)) {
            foreach ($room_timeslots as $week) {
                foreach ($week['rrze-rsvp-room-weekday'] as $day) {
                    if (isset($week['rrze-rsvp-room-starttime']) && isset($week['rrze-rsvp-room-endtime'])) {
                        $schedule[$day][$week['rrze-rsvp-room-starttime']] = $week['rrze-rsvp-room-endtime'];
                    }
                }
            }
        }
        return $schedule;
    }

    /**
     * getSelectHTML
     * Returns HTML <select ...><option ...>...
     * @param string $sSelect : the id and name of <select
     * @param string $sAll : the description of option  0 (f.e. --- all seats ---)
     * @param array $aOptions : assoc array with options' values => descriptions
     * @param string $sSelected : value of selected option (optional)
     * @return string
     */
    public static function getSelectHTML(string $sSelect, string $sAll, array $aOptions, string $sSelected = ''): string
    {
        $output = '<select id="' . $sSelect . '" name="' . $sSelect . '">';
        $output .= '<option value="0">' . $sAll . ' </option>';
        foreach ($aOptions as $val => $desc){
            $sel = ($val == $sSelected ? ' selected="selected"' : '');
            $output .= '<option value="' . $val . '"' . $sel . '>' . $desc . ' </option>';
        }
        $output .= '</select>';
        return $output;
    }

    /**
     * sortArrayKeepKeys
     * Returns sorted assoc array but keeps the keys
     * @param array $aInput : assoc array
     * @return no return but $aInput is passed by reference
     */
    public static function sortArrayKeepKeys(array &$aInput)
    {
        uasort($aInput, function ($a, $b) {
            if ($a == $b) { return 0;}
            return ($a < $b) ? -1 : 1;
        });

    }
}
