<?php

namespace RRZE\RSVP\Shortcodes;

use function RRZE\RSVP\Config\getShortcodeSettings;
use function RRZE\RSVP\Config\getShortcodeDefaults;



defined('ABSPATH') || exit;

/**
 * Define Shortcode Bookings
 */
class Bookings extends Shortcodes {
    protected $pluginFile;
    private $settings = '';
    private $shortcodesettings = '';

    public function __construct($pluginFile, $settings)
    {
        parent::__construct($pluginFile, $settings);
        $this->shortcodesettings = getShortcodeSettings();
    }


    public function onLoaded()
    {
        add_shortcode('rsvp-booking', [$this, 'shortcodeBooking'], 10, 2);
    }

    public function shortcodeBooking($atts, $content = '', $tag) {
        $shortcode_atts = parent::shortcodeAtts($atts, $tag, $this->shortcodesettings);
        $days = (int)$shortcode_atts['days'];
        $input_room = sanitize_title($shortcode_atts['room']);
        if (is_numeric($input_room)) {
            $post_room = get_post( $input_room );
            if ( !$post_room ) {
                return __( 'Room specified in shortcode does not exist.', 'rrze-rsvp' );
            }
        }
        $room = $input_room;

        if (isset($post_room)) {
            $today = date('Y-m-d');
            $endday = date('Y-m-d', strtotime($today. ' + ' . $days . ' days'));

            // Array aus verfügbaren Timeslots des Raumes erstellen
            $timeslots = get_post_meta($room, 'rrze-rsvp-room-timeslots');
            $timeslots = $timeslots[0];
            foreach($timeslots as $timeslot) {
                foreach ($timeslot['rrze-rsvp-room-weekday'] as $weekday) {
                    $slots[$weekday][] = $timeslot['rrze-rsvp-room-starttime'];
                }
            }

            // Array aus bereits gebuchten Plätzen im Zeitraum erstellen
            $seats = get_posts([
                'post_type' => 'seat',
                'post_status' => 'publish',
                'nopaging' => true,
                'meta_key' => 'rrze-rsvp-seat-room',
                'meta_value' => $room,
            ]);
            $seat_ids = [];
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
                            'key'     => 'rrze-rsvp-booking-date',
                            'value' => array( strtotime($today), strtotime($endday) ),
                            'compare' => 'BETWEEN',
                            'type' => 'numeric'
                        ],
                    ],
                    'meta_key' => 'rrze-rsvp-booking-seat',
                    'meta_value' => $seat->ID,
                ]);
                foreach ($bookings as $booking) {
                    $booking_meta = get_post_meta($booking->ID);
                    $booking_date = $booking_meta['rrze-rsvp-booking-date'][0];
                    $booking_time = $booking_meta['rrze-rsvp-booking-starttime'][0];
                    //$seats_booked[$seat->ID][date('Y-m-d', $booking_date)] = $booking_time;
                    $seats_booked[date('Y-m-d', $booking_date)][$booking_time][] = $seat->ID;
                }
            }
            print "<pre>"; var_dump($seats_booked); print "</pre>";

            // Tageweise durch den Zeitraum loopen, um die Verfügbarkeit je Wochentag zu ermitteln
            $loopstart = strtotime($today);
            $loopend = strtotime($endday);
            while ($loopstart <= $loopend) {
                $weekday = date('w', $loopstart) + 1;
                foreach ($slots[$weekday] as $time) {
                    //$this->availability[date('Y-m-d', $loopstart)] = $slots[$weekday];
                    $this->availability[date('Y-m-d', $loopstart)][$time] = $seat_ids;
                }
                $loopstart = strtotime("+1 day", $loopstart);
            }
//print "<pre>"; var_dump($this->availability); print "</pre>";
//$test = $this->array_multi_diff($seats_booked, $seats_booked);
//print "<pre>"; var_dump($test); print "</pre>";

        }

        $output = '';
        $output .= '<div class="rsvp">';
        $output .= '<form action="#" id="rsvp_by_room">'
            . '<div id="loading"><i class="fa fa-refresh fa-spin fa-4x"></i></div>';

        if ($room == 'select') {
            $rooms = get_posts([
                'post_type' => 'room',
                'post_statue' => 'publish',
                'nopaging' => true,
                'orderby' => 'title',
                'order' => 'ASC',
            ]);
            $dropdown = '<select name="rsvp_room" id="rsvp_room" class="postform">
                <option value="0">— Please select —</option>';
            foreach ($rooms as $room) {
                $dropdown .= sprintf('<option value="%s">%s</option>', $room->ID, $room->post_title);
            }
            $dropdown .= '</select>';
            $output .= '<div class="form-group">'
                . '<label for="rsvp_room" class="h3">' . __('Room', 'rrze-rsvp') . '</label>'
                . $dropdown . '</div>';
        } else {
            $output .= '<p><input type="hidden" value="'.$room.'" id="rsvp_room">'
                . __('Book a seat in: ', 'rrze-rsvp') . '<strong>' . get_the_title($room) . '</strong>'
                . '</p>';
        }

        $output .= '<div class="rsvp-datetime-container form-group clearfix"><legend>' . __('Select date and time', 'rrze-rsvp') . '</legend>'
            . '<div class="rsvp-date-container">';
        $dateComponents = getdate();
        $month = $dateComponents['mon'];
        $year = $dateComponents['year'];
        $start = date_create();
        $end = date_create();
        date_modify($end, '+'.$days.' days');
        $output .= $this->buildCalendar($month,$year, $start, $end, $room);
//        $output .= $this->buildDateBoxes($days);
        $output .= '</div>'; //.rsvp-date-container

        $output .= '<div class="rsvp-time-container">'
            . '<h4>' . __('Available time slots:', 'rrze-rsvp') . '</h4>'
            . '<div class="rsvp-time-select error">'.__('Please select a date.', 'rrze-rsvp').'</div>'
            . '</div>'; //.rsvp-time-container

        $output .= '</div>'; //.rsvp-datetime-container

        $output .= '<div class="rsvp-service-container"></div>';

        $output .= '<div class="form-group"><label for="rrze_rsvp_user_phone">' . __('Phone Number', 'rrze-rsvp') . ' *</label>'
            . '<input type="tel" name="rrze_rsvp_user_phone" id="rrze_rsvp_user_phone" required aria-required="true">';

        $output .= '<button type="submit" class="btn btn-primary">'.__('Submit','rrze-rsvp').'</button>
                </form>
            </div>';

        wp_enqueue_style('rrze-rsvp-shortcode');
        wp_enqueue_script('rrze-rsvp-shortcode');

        return $output;
    }

    /*
     * Inspirationsquelle:
     * https://css-tricks.com/snippets/php/build-a-calendar-table/
     */
    public function buildCalendar($month, $year, $start = '', $end = '', $room = '') {
        if ($start == '')
            $start = date_create();
        if (!is_object($end))
            $end = date_create($end);
        if ($room == 'select')
            $room = '';
        // Create array containing abbreviations of days of week.
        $daysOfWeek = array('Mo','Di','Mi','Do','Fr','Sa','So');
        // What is the first day of the month in question?
        $firstDayOfMonth = mktime(0,0,0,$month,1,$year);
        $firstDayOfMonthObject = date_create($firstDayOfMonth);
        // How many days does this month contain?
        $numberDays = date('t', $firstDayOfMonth);
        // Retrieve some information about the first day of the
        // month in question.
        $dateComponents = getdate($firstDayOfMonth);
        // What is the name of the month in question?
        $monthName = $dateComponents['month'];
        // What is the index value (0-6) of the first day of the month in question.
        // (BB: adapted to European index (Mo = 0)
        $dayOfWeek = $dateComponents['wday'] - 1;
        if ($dayOfWeek == -1)
            $dayOfWeek = 6;
        $today_day = date("d");
        $today_day = ltrim($today_day, '0');
        $bookingDaysStart = $start;
        $bookingDaysEnd = $end;
        $endDate = date_format($bookingDaysEnd, 'Y-m-d');
        $startDate = date_format($bookingDaysStart, 'Y-m-d');
        $link_next = '<a href="#" class="cal-skip cal-next" data-direction="next">&gt;&gt;</a>';
        $link_prev = '<a href="#" class="cal-skip cal-prev" data-direction="prev">&lt;&lt;</a>';
        // Create the table tag opener and day headers
        $calendar = '<table class="rsvp_calendar" data-period="'.date_i18n('Y-m', $firstDayOfMonth).'" data-end="'.$endDate.'">';
        $calendar .= "<caption>";
        if ($bookingDaysStart <= date_create($year.'-'.$month)) {
            $calendar .= $link_prev;
        }
        $calendar .= date_i18n('F Y', $firstDayOfMonth);
        if ($bookingDaysEnd >= date_create($year.'-'.$month.'-'.$numberDays)) {
            $calendar .= $link_next;
        }
        //print $remainingBookingDays;
        $calendar .= "</caption>";
        $calendar .= "<tr>";
        // Create the calendar headers
        foreach($daysOfWeek as $day) {
            $calendar .= "<th class='header'>$day</th>";
        }
        // Create the rest of the calendar
        // Initiate the day counter, starting with the 1st.
        $currentDay = 1;
        $calendar .= "</tr><tr>";
        // The variable $dayOfWeek is used to
        // ensure that the calendar
        // display consists of exactly 7 columns.
        if ($dayOfWeek > 0) {
            $calendar .= "<td colspan='$dayOfWeek'>&nbsp;</td>";
        }
        $month = str_pad($month, 2, "0", STR_PAD_LEFT);
        while ($currentDay <= $numberDays) {
            // Seventh column (Saturday) reached. Start a new row.
            if ($dayOfWeek == 7) {
                $dayOfWeek = 0;
                $calendar .= "</tr><tr>";
            }
            $currentDayRel = str_pad($currentDay, 2, "0", STR_PAD_LEFT);
            $date = "$year-$month-$currentDayRel";
            $currentDate = date_create($date);
            $class = '';
            $title = '';
            $active = true;
            //var_dump($bookingDaysStart, $bookingDaysEnd);
            if ($currentDate < $bookingDaysStart || $currentDate > $bookingDaysEnd) {
                $active = false;
                $title = __('Not bookable (outside booking period)','rrze-rsvp');
            } else {
                $active = false;
                $class = 'soldout';
                $title = __('Not bookable (soldout)','rrze-rsvp');
                if ($room == '') {
                    foreach ($this->tmp_availability as $id => $service) {
                        if (array_key_exists($date, $service['availablity'])) {
                            $active = true;
                            $class = 'available';
                            $title = __('Seats available','rrze-rsvp');
                            break;
                        }
                    }
                } else {
                    foreach ($this->tmp_availability as $id => $service) {
                        if ($service['room'] == $room && array_key_exists($date, $service['availablity'])) {
                            $active = true;
                            $class = 'available';
                            $title = __('Seats available','rrze-rsvp');
                            break;
                        }
                    }
                }
            }

            $input_open = '<span class="inactive">';
            $input_close = '</span>';
            if ($active) {
                $input_open = "<input type=\"radio\" id=\"rsvp_date_$date\" value=\"$date\" name=\"rsvp_date\"><label for=\"rsvp_date_$date\">";
                $input_close = '</label>';
            }
            $calendar .= "<td class='day $class' rel='$date' title='$title'>" . $input_open.$currentDay.$input_close . "</td>";
            // Increment counters
            $currentDay++;
            $dayOfWeek++;
        }
        // Complete the row of the last week in month, if necessary
        if ($dayOfWeek != 7) {
            $remainingDays = 7 - $dayOfWeek;
            $calendar .= "<td colspan='$remainingDays'>&nbsp;</td>";
        }
        $calendar .= "</tr>";
        $calendar .= "</table>";
        return $calendar;
    }

    public function buildDateBoxes($days = 14) {
        $output = '';
        for ($i = 0; $i <= $days; $i++) {
            $timestamp = mktime(0, 0, 0, date("m")  , date("d")+$i, date("Y"));
            $techtime1 = date('Y-m-d_09-00', $timestamp);
            $techtime2 = date('Y-m-d_14-30', $timestamp);
            $output .= '<div class="rsvp-datebox">';
            $output .= date_i18n("D", $timestamp) . ', ' . date_i18n(get_option('date_format'), $timestamp);
            $output .= '<br /> <input type="radio" id="service_'. $techtime1 . '" name="datetime" value="'. $techtime1 . '" disabled>'
                . '<label for="service_'. $techtime1 . '" class="disabled"> 09:00-13:30 Uhr</label>';
            $output .= '<br /> <input type="radio" id="service_'. $techtime2 . '" name="datetime" value="'. $techtime2 . '" disabled>'
                . '<label for="service_'. $techtime2 . '" class="disabled"> 14:30-19:00 Uhr</label><br />';
            $output .= '';
            $output .= '</div>';
        }
        $output .= '<button class="show-more btn btn-default btn-block">&hellip;'.__('More','rrze-rsvp').'&hellip;</button>';

        return $output;
    }

    public function ajaxUpdateCalendar() {
        check_ajax_referer( 'rsvp-ajax-nonce' );
        $period = explode('-', $_POST['month']);
        $mod = ($_POST['direction'] == 'next' ? 1 : -1);
        $start = date_create();
        $end = sanitize_text_field($_POST['end']);
        $room = (int)$_POST['room'];
        $output = '';
        $output .= $this->buildCalendar($period[1] + $mod, $period[0], $start, $end, $room);
        echo $output;
        wp_die();
    }

    public function ajaxUpdateForm() {
        check_ajax_referer( 'rsvp-ajax-nonce' );
        $room = ((isset($_POST['room']) && $_POST['room'] > 0) ? (int)$_POST['room'] : '');
        $date = (isset($_POST['date']) ? sanitize_text_field($_POST['date']) : false);
        $time = (isset($_POST['time']) ? sanitize_text_field($_POST['time']) : false);
        $response = [];
        if ($date !== false) {
            $response['time'] = '<div class="rsvp-time-select error">'.__('Please select a date.', 'rrze-rsvp').'</div>';
        }
        if (!$date || !$time) {
            $response['service'] = '<div class="rsvp-service-select error">'.__('Please select a date and a time slot.', 'rrze-rsvp').'</div>';
        }
        $timeSelects = '';
        $serviceSelects = '';
        if ($date) {
            $slots = [];
            if ($room == '') {
                foreach ($this->tmp_availability as $sid => $service) {
                    if (array_key_exists($date, $service['availablity'])) {
                        foreach ($service['availablity'][$date] as $slot) {
                            if (!in_array($slot, $slots, true)) {
                                array_push($slots, $slot);
                            }
                        }
                    }
                }
            } else {
                foreach ($this->tmp_availability as $sid => $service) {
                    if ($service['room'] == $room && array_key_exists($date, $service['availablity'])) {
                        foreach ($service['availablity'][$date] as $slot) {
                            if (!in_array($slot, $slots, true)) {
                                array_push($slots, $slot);
                            }
                        }
                    }
                }
            }
            foreach ($slots as $slot) {
                $id = 'rsvp_time_' . sanitize_title($slot);
                $checked = checked($time !== false && $time == $slot, true, false);
                $timeSelects .= "<div class='form-group'><input type='radio' id='$id' value='$slot' name='rsvp_time' " . $checked . "><label for='$id'>$slot</label></div>";
            }
            if ($timeSelects == '') {
                $timeSelects .= __('No time slots available.', 'rrze-rsvp');
            }
            $response['time'] = '<div class="rsvp-time-select">' . $timeSelects . '</div>';
            if ($time) {
                foreach ($this->tmp_availability as $sid => $service) {
                    $id = 'rsvp_service_' . $sid;
                    if ($room == '') {
                        if (array_key_exists($date, $service['availablity']) && in_array($time, $service['availablity'][$date])) {
                            $post = get_post($sid);
                            $serviceSelects .= "<div class='form-group'>"
                                . "<input type='radio' id='$id' value='$sid' name='rsvp_service'>"
                                . "<label for='$id'>$post->post_title</label>"
                                . "</div>";
                        }
                    } else {
                        if ($service['room'] == $room && array_key_exists($date, $service['availablity']) && in_array($time, $service['availablity'][$date])) {
                            $post = get_post($sid);
                            $serviceSelects .= "<div class='form-group'>"
                                . "<input type='radio' id='$id' value='$sid' name='rsvp_service'>"
                                . "<label for='$id'>$post->post_title</label>"
                                . "</div>";
                        }
                    }
                }
                if ($serviceSelects == '') {
                    $serviceSelects = '<div class="rsvp-service-select error">'.__('Please select a date and a time slot.', 'rrze-rsvp').'</div>';
                } else {
                    $serviceSelects = '<div class="rsvp-service-select">' . $serviceSelects . '</div>';
                }
                $response['service'] = '<h4>' . __('Available items:', 'rrze-rsvp') . '</h4>' . $serviceSelects;
            }
        }
        wp_send_json($response);
    }

    public function ajaxShowItemInfo() {
        if (!isset($_POST['id'])) {
            echo '';
            wp_die();
        }
        $id = (int)$_POST['id'];
        $output = '';
        $equipment = get_the_terms($id, 'rrze-rsvp-equipment');
        $room = get_the_terms($id, 'rrze-rsvp-services');
        if ($equipment === false && $room === false) {
            //echo '<div class="rsvp-item-info">' . __('No additional information available.','rrze-rsvp') . '</div>';
            echo '';
            wp_die();
        }
        $output .= '<div class="rsvp-item-info">';
        if ($equipment !== false) {
            $output .= '<div class="rsvp-item-equipment">';
            foreach  ($equipment as $e) {
                $e_arr[] = $e->name;
            }
            $output .= '<p><strong>' . __('Equipment','rrze-rsvp') . '</strong>: ' . implode(', ', $e_arr) . '</p>';
            $output .= '</div>';
        }
        if ($room !== false) {
            $output .= '<div class="rsvp-item-room"><p><strong>' . __('Room','rrze-rsvp') . ':</strong> ';
            foreach  ($room as $l) {
                $output .= '<a href="'.get_term_link($l->term_id, 'rsvp_rooms').'" target="_blank" title="'.__('Open room info in new window.','rrze-rsvp').'">';
                if ($l->parent != 0) {
                    $parent = get_term($l->parent, 'rsvp_rooms');
                    $output .= $parent->name . ', ';
                }
                $output .= $l->name;
                $output .= '</a></p>';
            }
            $output .= '</div>';
        }
        $output .= '</div>';
        //wp_send_json($room);
        echo $output;
        wp_die();
    }

    /*public function array_multi_diff( $array1, $array2 ) {
        $result = array();
        foreach( $array1 as $key => $a1 ) {
            if( !array_key_exists( $key, $array2 ) ) {
                $result[ $key ] = $a1;
                continue;
            }
            $a2 = $array2[ $key ];
            if( is_array( $a1 ) ) {
                $recc_array = $this->array_multi_diff( $a1, $a2 );
                if( !empty( $recc_array ) ) {
                    $result[ $key ] = $recc_array;
                }
            }
            else if( $a1 != $a2 ) {
                $result[ $key ] = $a1;
            }
        }
        return $result;
    }*/
}
