<?php

namespace RRZE\RSVP\Shortcodes;

defined('ABSPATH') || exit;

use RRZE\RSVP\CPT;
use RRZE\RSVP\Options;
use function RRZE\RSVP\plugin;

/**
 * Shortcode
 */
class Shortcode extends Settings
{
    /**
     * Variablen Werte zuweisen.
     * @param string $pluginFile Pfad- und Dateiname der Plugin-Datei
     */
    public function __construct()
    {
        $this->settings = $this->getFields();

        $this->tmp_availability = [
            '2225840' => [
                'availablity' => [
                    '2020-07-19' => ['09:00','14:30'],
                    '2020-07-20' => ['09:00','14:30'],
                    '2020-07-21' => ['09:00','14:30'],
                    '2020-07-22' => ['09:00','14:30'],
                    '2020-07-23' => ['09:00','14:30'],
                    '2020-07-24' => ['09:00','14:30'],
                    '2020-07-26' => ['09:00','14:30'],
                    '2020-07-27' => ['09:00'],
                    '2020-07-28' => ['09:00','14:30'],
                    '2020-07-29' => ['09:00','14:30'],
                    '2020-07-30' => ['09:00','14:30'],
                ],
                'location' => '74'
            ],
            '2225856' => [
                'availablity' => [
                    '2020-07-23' => ['14:30'],
                    '2020-07-24' => ['09:00','14:30'],
                    '2020-07-26' => ['09:00'],
                    '2020-07-27' => ['09:00'],
                    '2020-07-28' => ['09:00','14:30'],
                    '2020-07-29' => ['09:00'],
                    '2020-07-30' => ['14:30'],
                ],
                'location' => '73'
            ],
            '2225858' => [
                'availablity' => [
                    '2020-07-19' => ['09:00'],
                    '2020-07-20' => ['09:00','14:30'],
                    '2020-07-23' => ['09:00','14:30'],
                    '2020-07-24' => ['14:30'],
                    '2020-07-26' => ['09:00','14:30'],
                    '2020-07-28' => ['09:00','14:30'],
                    '2020-07-29' => ['14:30'],
                    '2020-07-30' => ['09:00','14:30'],
                ],
                'location' => '73'
            ],
            '2225860' => [
                'availablity' => [
                    '2020-07-19' => ['09:00','14:30'],
                    '2020-07-20' => ['09:00'],
                    '2020-07-21' => ['09:00'],
                    '2020-07-22' => ['09:00','14:30'],
                    '2020-07-23' => ['14:30'],
                    '2020-07-24' => ['09:00','14:30'],
                    '2020-07-27' => ['09:00'],
                    '2020-07-30' => ['14:30'],
                ],
                'location' => '73'
            ],
            '2225864' => [
                'availablity' => [
                    '2020-07-19' => ['14:30'],
                    '2020-07-21' => ['14:30'],
                    '2020-07-22' => ['09:00','14:30'],
                    '2020-07-23' => ['09:00'],
                    '2020-07-24' => ['09:00','14:30'],
                    '2020-07-28' => ['09:00'],
                ],
                'location' => '73'
            ],
        ];
    }

    /**
     * Er wird ausgeführt, sobald die Klasse instanziiert wird.
     * @return void
     */
    public function onLoaded()
    {
        add_action('wp_enqueue_scripts', [$this, 'enqueueScripts']);
        add_shortcode('rsvp-booking', [$this, 'shortcodeOutput'], 10, 2);
        add_shortcode('rsvp-availability', [$this, 'shortcodeOutput'], 10, 2);
        add_action( 'wp_ajax_UpdateCalendar', [$this, 'ajaxUpdateCalendar'] );
        add_action( 'wp_ajax_UpdateForm', [$this, 'ajaxUpdateForm'] );
        add_action( 'wp_ajax_ShowItemInfo', [$this, 'ajaxShowItemInfo'] );

    }

    /**
     * Enqueue der Skripte.
     */
    public function enqueueScripts()
    {
        wp_register_style('rrze-rsvp-shortcode', plugins_url('assets/css/shortcode.css', plugin()->getBasename()));
        wp_register_script('rrze-rsvp-shortcode', plugins_url('assets/js/shortcode.js', plugin()->getBasename()));
        $nonce = wp_create_nonce( 'rsvp-ajax-nonce' );
        wp_localize_script('rrze-rsvp-shortcode', 'rsvp_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => $nonce,
        ]);
    }


    /**
     * Generieren Sie die Shortcode-Ausgabe
     * @param  array   $atts Shortcode-Attribute
     * @param  string  $content Beiliegender Inhalt
     * @return string Gib den Inhalt zurück
     */
    public function shortcodeOutput($atts, $content = '', $tag) {
        // merge given attributes with default ones
        $atts_default = array();
        foreach( $this->settings as $tagname => $settings) {
            foreach( $settings as $k => $v ) {
                if ($k != 'block') {
                    $atts_default[$tagname][$k] = $v['default'];
                }
            }
        }
        $shortcode_atts = shortcode_atts( $atts_default[$tag], $atts );

        switch ($tag) {
            case 'rsvp-booking':
                return $this->shortcodeBooking($shortcode_atts);
                break;
            case 'rsvp-availability':
                return $this->shortcodeAvailability($shortcode_atts);
                break;
        }
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

    public function shortcodeBooking($shortcode_atts) {
        $location = sanitize_title($shortcode_atts['location']);
        if ($location != '' && $location != 'select') {
            $locationTerm = get_term_by('slug', $location, CPT::getTaxonomyServiceName());
            $location =  $locationTerm->term_id;
            if ($locationTerm === false) {
                return __('Location specified in shortcode does not exist.','rrze-rsvp');
            }
            $locationsOptions = Options::getServiceOptions($locationTerm->term_id);
        }


        
        $days = (int)$shortcode_atts['days'];

        $output = '';
        $output .= '<div class="rsvp">';
        $output .= '<form action="#" id="rsvp_by_location">'
            . '<div id="loading"><i class="fa fa-refresh fa-spin fa-4x"></i></div>';

        if ($location == 'select') {
            $dropdown = wp_dropdown_categories([
                'taxonomy' => 'rrze-rsvp-services',
                'hide_empty' => true,
                'show_option_none' => __('-- Please select --', 'rrze-rsvp'),
                'orderby' => 'name',
                //'hierarchical' => true,
                'id' => 'rsvp_location',
                'echo' => false,
            ]);
//            $dropdown = \RRZE\RSVP\Seats\NewSettings::serviceField();
//            var_dump($dropdown);
//            exit;
            $output .= '<div class="form-group">'
                . '<label for="rsvp_location" class="h3">Location/Room</label>'
                . $dropdown . '</div>';
        } else {
            $output .= '<div><input type="hidden" value="'.$location.'" id="rsvp_location"></div>';
        }

        $output .= '<div class="rsvp-datetime-container form-group clearfix"><legend>' . __('Select date and time', 'rrze-rsvp') . '</legend>'
            . '<div class="rsvp-date-container">';
        $dateComponents = getdate();
        $month = $dateComponents['mon'];
        $year = $dateComponents['year'];
        $start = date_create();
        $end = date_create();
        date_modify($end, '+'.$days.' days');
        $output .= $this->buildCalendar($month,$year, $start, $end, $location);
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

        $output .= '<button type="submit" class="btn btn-primary">Submit</button>
                </form>
            </div>';

        wp_enqueue_style('rrze-rsvp-shortcode');
        wp_enqueue_script('rrze-rsvp-shortcode');

        return $output;
    }

    public function shortcodeAvailability($shortcode_atts) {
        $output = '';
        $days = sanitize_text_field($shortcode_atts['days']); // kann today, tomorrow oder eine Zahl sein (kommende X Tage)
        $seats = explode(',', sanitize_text_field($shortcode_atts['seat']));
        $seats = array_map('trim', $seats);
        $seats = array_map('sanitize_title', $seats);
        $services = explode(',', sanitize_text_field($shortcode_atts['service']));
        $services = array_map('trim', $services);
        $services = array_map('sanitize_title', $services);
//var_dump($seats, $services);
        $post = get_posts();

        wp_enqueue_style('rrze-rsvp-shortcode');
        //wp_enqueue_script('rrze-rsvp-shortcode');

        return $output;
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
                foreach ($this->tmp_availability as $id => $service) {
                    if ($service['location'] == $location && array_key_exists($date, $service['availablity'])) {
                        $active = true;
                        $class = 'available';
                        $title = __('Seats available','rrze-rsvp');
                        break;
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
        $location = absint($_POST['location']);
        $output = '';
        $output .= $this->buildCalendar($period[1] + $mod, $period[0], $start, $end, $location);
        echo $output;
        wp_die();
    }

    public function ajaxUpdateForm() {
        check_ajax_referer( 'rsvp-ajax-nonce' );
        $location = isset($_POST['location']) ? absint($_POST['location']) : '';
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
            foreach ($this->tmp_availability as $sid => $service) {
                if ($service['location'] == $location && array_key_exists($date, $service['availablity'])) {
                    foreach ($service['availablity'][$date] as $slot) {
                        if (!in_array($slot, $slots, true)) {
                            array_push($slots, $slot);
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
                    if ($service['location'] == $location && array_key_exists($date, $service['availablity']) && in_array($time, $service['availablity'][$date])) {
                        $post = get_post($sid); // CPT = rrze_rsvp_seat
                        $serviceSelects .= "<div class='form-group'>"
                            . "<input type='radio' id='$id' value='$sid' name='rsvp_service'>"
                            . "<label for='$id'>$post->post_title</label>"
                            . "</div>";
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
        $id = absint($_POST['id']);
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
