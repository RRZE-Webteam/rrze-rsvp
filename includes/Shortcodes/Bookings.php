<?php

namespace RRZE\RSVP\Shortcodes;

use RRZE\RSVP\Functions;
use function RRZE\RSVP\Config\getShortcodeSettings;
use function RRZE\RSVP\Config\getShortcodeDefaults;
use function RRZE\RSVP\getRoomAvailability;


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
        add_action( 'wp_ajax_UpdateCalendar', [$this, 'ajaxUpdateCalendar'] );
        add_action( 'wp_ajax_UpdateForm', [$this, 'ajaxUpdateForm'] );
        add_action( 'wp_ajax_ShowItemInfo', [$this, 'ajaxShowItemInfo'] );
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
                . __('Book a seat at: ', 'rrze-rsvp') . '<strong>' . get_the_title($room) . '</strong>'
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
//        $output .= $this->buildCalendar($month,$year, $start, $end, $room);
        $output .= $this->buildCalendar('8',$year, $start, $end, $room);
//        $output .= $this->buildDateBoxes($days);
        $output .= '</div>'; //.rsvp-date-container

        $output .= '<div class="rsvp-time-container">'
            . '<h4>' . __('Available time slots:', 'rrze-rsvp') . '</h4>'
            . '<div class="rsvp-time-select error">'.__('Please select a date.', 'rrze-rsvp').'</div>'
            . '</div>'; //.rsvp-time-container

        $output .= '</div>'; //.rsvp-datetime-container

        $output .= '<div class="rsvp-service-container"></div>';

        $output .= '<div class="form-group"><label for="rrze_rsvp_user_phone">' . __('Phone Number', 'rrze-rsvp') . ' *</label>'
            . '<input type="tel" name="rrze_rsvp_user_phone" id="rrze_rsvp_user_phone" required aria-required="true">'
            . '<p class="description">' . __('Um die Konkakt-Nachverfolgbarkeit im Rahmen der Corona-Bekämpfungsverordnung zu gewährleisten, benötigen wir Ihre Telefonnummer.', 'rrze-rsvp') . '</p>'
            . '</div>';

        $output .= '<button type="submit" class="btn btn-primary">'.__('Submit booking','rrze-rsvp').'</button>
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
        $availability = Functions::getRoomAvailability($room, $startDate, $endDate);

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
            if ($currentDate < $bookingDaysStart || $currentDate > $bookingDaysEnd) {
                $active = false;
                $title = __('Not bookable (outside booking period)','rrze-rsvp');
            } else {
                $active = false;
                $class = 'soldout';
                $title = __('Not bookable (soldout or room blocked)','rrze-rsvp');
                if ($room == '') {

                } else {
                    if (isset($availability[$date])) {
                        foreach ( $availability[ $date ] as $timeslot ) {
                            if ( !empty( $timeslot ) ) {
                                $active = true;
                                $class = 'available';
                                $title = __( 'Seats available', 'rrze-rsvp' );
                            }
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
            } else {
                $availability = Functions::getRoomAvailability($room, $date, date('Y-m-d', strtotime($date. ' +1 days')));
                $slots = array_keys($availability[$date]);
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
                $seats = (isset($availability[$date][$time])) ? $availability[$date][$time] : [];
                foreach ($seats as $seat) {
                    $seatname = get_the_title($seat);
                    $serviceSelects .= "<div class='form-group'>"
                        . "<input type='radio' id='$id' value='$seat' name='rsvp_service'>"
                        . "<label for='$id'>$seatname</label>"
                        . "</div>";
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
        $seat_name = get_the_title($id);
        $equipment = get_the_terms($id, 'rrze-rsvp-equipment');
        $room_id = get_post_meta($id, 'rrze-rsvp-seat-room', true);
        if ($room_id)
            $room = get_post($room_id);
        if ($equipment === false && $room === false) {
//            echo '<div class="rsvp-item-info">' . __('No additional information available.','rrze-rsvp') . '</div>';
            echo '';
            wp_die();
        }
        $output .= '<div class="rsvp-item-info">';
        if ($equipment !== false || $room_id !== false) {
            $output .= '<div class="rsvp-item-equipment"><h5 class="small">' . sprintf( __( 'Seat %s', 'rrze-rsvp' ), $seat_name ) . '</h5>';
        }
        if ($equipment !== false) {
            foreach  ($equipment as $e) {
                $e_arr[] = $e->name;
            }
            $output .= '<p><strong>' . __('Equipment','rrze-rsvp') . '</strong>: ' . implode(', ', $e_arr) . '</p>';
            $output .= '</div>';
        }
        if ($room_id) {
            $output .= '<div class="rsvp-item-room"><p><strong>' . __('Room','rrze-rsvp') . ':</strong> ';
            $output .= '<a href="'.get_permalink($room->ID).'" target="_blank" title="'.__('Open room info in new window.','rrze-rsvp').'">';
            $output .= $room->post_title;
            $output .= '</a></p>';

            $output .= '</div>';
        }
        $output .= '</div>';
        echo $output;
        wp_die();
    }
}
