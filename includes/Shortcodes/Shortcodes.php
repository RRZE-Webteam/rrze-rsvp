<?php

namespace RRZE\RSVP\Shortcodes;

defined('ABSPATH') || exit;

use RRZE\RSVP\Main;
use RRZE\RSVP\Shortcodes\Bookings;
use RRZE\RSVP\Shortcodes\Availability;
use function RRZE\RSVP\Config\getShortcodeSettings;

/**
 * Laden und definieren der Shortcodes
 */
class Shortcodes {
    protected $pluginFile;
    private $settings = '';
    
     public function __construct($pluginFile, $settings) {
        $this->pluginFile = $pluginFile;
        $this->settings = getShortcodeSettings();
    }

    public function onLoaded() {
	add_action('wp_enqueue_scripts', [$this, 'enqueueScripts']);
	add_action( 'wp_ajax_UpdateCalendar', [$this, 'ajaxUpdateCalendar'] );
	add_action( 'wp_ajax_UpdateForm', [$this, 'ajaxUpdateForm'] );
	add_action( 'wp_ajax_ShowItemInfo', [$this, 'ajaxShowItemInfo'] );

	$bookings_shortcode = new Bookings($this->pluginFile,  $this->settings);
	$bookings_shortcode->onLoaded();

	$avaibility_shortcode = new Availability($this->pluginFile,  $this->settings);
	$avaibility_shortcode->onLoaded();
    }
     /**
     * Enqueue der Skripte.
     */
    public function enqueueScripts()
    {
        wp_register_style('rrze-rsvp-shortcode', plugins_url('assets/css/shortcode.css', plugin_basename($this->pluginFile)));
        wp_register_script('rrze-rsvp-shortcode', plugins_url('assets/js/shortcode.js', plugin_basename($this->pluginFile)));
        $nonce = wp_create_nonce( 'rsvp-ajax-nonce' );
        wp_localize_script('rrze-rsvp-shortcode', 'rsvp_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => $nonce,
        ]);
    }
    

    public function gutenberg_init() {
        // Skip block registration if Gutenberg is not enabled/merged.
        if ( ! function_exists( 'register_block_type' ) ) {
            return;
        }

        $js = '../assets/js/gutenberg.js';
        $editor_script = $this->settings['block']['blockname'] . '-blockJS';

        wp_register_script(
            $editor_script,
            plugins_url( $js, __FILE__ ),
            array(
                'wp-blocks',
                'wp-i18n',
                'wp-element',
                'wp-components',
                'wp-editor'
            ),
            filemtime( dirname( __FILE__ ) . '/' . $js )
        );

        wp_localize_script( $editor_script, 'blockname', $this->settings['block']['blockname'] );

        register_block_type( $this->settings['block']['blocktype'], array(
            'editor_script' => $editor_script,
            'render_callback' => [$this, 'shortcodeOutput'],
            'attributes' => $this->settings
            )
        );

        wp_localize_script( $editor_script, $this->settings['block']['blockname'] . 'Config', $this->settings );
    }
    /*
     * Inspirationsquelle:
     * https://css-tricks.com/snippets/php/build-a-calendar-table/
     */
    public function buildCalendar($month, $year, $start = '', $end = '', $location = '') {
        if ($start == '')
            $start = date_create();
        if (!is_object($end))
            $end = date_create($end);
        if ($location == 'select')
            $location = '';
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
                if ($location == '') {
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
                        if ($service['location'] == $location && array_key_exists($date, $service['availablity'])) {
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
        $output .= '<button class="show-more btn btn-default btn-block">&hellip;weitere anzeigen&hellip;</button>';

        return $output;
    }

 public function ajaxUpdateCalendar() {
        check_ajax_referer( 'rsvp-ajax-nonce' );
        $period = explode('-', $_POST['month']);
        $mod = ($_POST['direction'] == 'next' ? 1 : -1);
        $start = date_create();
        $end = sanitize_text_field($_POST['end']);
        $location = (int)$_POST['location'];
        $output = '';
        $output .= $this->buildCalendar($period[1] + $mod, $period[0], $start, $end, $location);
        echo $output;
        wp_die();
    }

    public function ajaxUpdateForm() {
        check_ajax_referer( 'rsvp-ajax-nonce' );
        $location = ((isset($_POST['location']) && $_POST['location'] > 0) ? (int)$_POST['location'] : '');
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
            if ($location == '') {
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
                    if ($service['location'] == $location && array_key_exists($date, $service['availablity'])) {
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
                    if ($location == '') {
                        if (array_key_exists($date, $service['availablity']) && in_array($time, $service['availablity'][$date])) {
                            $post = get_post($sid);
                            $serviceSelects .= "<div class='form-group'>"
                                . "<input type='radio' id='$id' value='$sid' name='rsvp_service'>"
                                . "<label for='$id'>$post->post_title</label>"
                                . "</div>";
                        }
                    } else {
                        if ($service['location'] == $location && array_key_exists($date, $service['availablity']) && in_array($time, $service['availablity'][$date])) {
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
        $location = get_the_terms($id, 'rrze-rsvp-services');
        if ($equipment === false && $location === false) {
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
        if ($location !== false) {
            $output .= '<div class="rsvp-item-location"><p><strong>' . __('Location','rrze-rsvp') . ':</strong> ';
            foreach  ($location as $l) {
                $output .= '<a href="'.get_term_link($l->term_id, 'rsvp_locations').'" target="_blank" title="'.__('Open location info in new window.','rrze-rsvp').'">';
                if ($l->parent != 0) {
                    $parent = get_term($l->parent, 'rsvp_locations');
                    $output .= $parent->name . ', ';
                }
                $output .= $l->name;
                $output .= '</a></p>';
            }
            $output .= '</div>';
        }
        $output .= '</div>';
        //wp_send_json($location);
        echo $output;
        wp_die();
    }
}

