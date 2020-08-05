<?php

namespace RRZE\RSVP\Shortcodes;

use RRZE\RSVP\Email;
use RRZE\RSVP\IdM;
use RRZE\RSVP\Functions;
use RRZE\RSVP\Helper;

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
    private $options = '';

    protected $email;
    protected $idm;
    protected $sso = false;

    public function __construct($pluginFile, $settings)
    {
        parent::__construct($pluginFile, $settings);
        $this->shortcodesettings = getShortcodeSettings();
        $this->options = (object) $settings->getOptions();
        $this->email = new Email;
        $this->idm = new IdM;
    }


    public function onLoaded()
    {
        add_action('wp', [$this, 'ssoLogin'], 0);
        add_shortcode('rsvp-booking', [$this, 'shortcodeBooking'], 10, 2);
        add_action( 'wp_ajax_UpdateCalendar', [$this, 'ajaxUpdateCalendar'] );
        add_action( 'wp_ajax_nopriv_UpdateCalendar', [$this, 'ajaxUpdateCalendar'] );
        add_action( 'wp_ajax_UpdateForm', [$this, 'ajaxUpdateForm'] );
        add_action( 'wp_ajax_nopriv_UpdateForm', [$this, 'ajaxUpdateForm'] );
        add_action( 'wp_ajax_ShowItemInfo', [$this, 'ajaxShowItemInfo'] );
        add_action( 'wp_ajax_nopriv_ShowItemInfo', [$this, 'ajaxShowItemInfo'] );
    }

    public function ssoLogin()
    {
        if (!is_user_logged_in() && Functions::hasShortcodeSSO('rsvp-booking')) {
            $this->sso = $this->idm->tryLogIn(true);
        }
    }


    public function shortcodeBooking($atts, $content = '', $tag) {
        $output = '';
        if (isset($_POST['rrze_rsvp_post_nonce_field']) && wp_verify_nonce($_POST['rrze_rsvp_post_nonce_field'], 'post_nonce')) {

            array_walk_recursive(
                    $_POST, function ( &$value ) {
                    if ( is_string( $value ) ) {
                        $value = wp_strip_all_tags( trim( $value ) );
                    }
                }
            );

            $posted_data = $_POST;
//            echo Helper::get_html_var_dump($posted_data);
            $booking_date = sanitize_text_field($posted_data['rsvp_date']);
            $booking_start = sanitize_text_field($posted_data['rsvp_time']);
            $booking_timestamp_start = strtotime($booking_date . ' ' . $booking_start);
            $booking_seat = absint($posted_data['rsvp_seat']);
            $booking_lastname = sanitize_text_field($posted_data['rsvp_lastname']);
            $booking_firstname = sanitize_text_field($posted_data['rsvp_firstname']);
            $booking_email = sanitize_email($posted_data['rsvp_email']);
            $booking_phone = sanitize_text_field($posted_data['rsvp_phone']);

            // Überprüfen ob bereits eine Bewerbung mit gleicher E-Mail-Adresse zur gleichen Zeit vorliegt
            $check_args = [
                'post_type' => 'booking',
                'meta_query' => [
                    'relation' => 'AND',
                    [
                        'key' => 'rrze-rsvp-booking-guest-email',
                        'value' => $booking_email
                    ],
                    [
                        'key' => 'rrze-rsvp-booking-start',
                        'value' => $booking_timestamp_start
                    ],
                    [
                        'key' => 'rrze-rsvp-booking-status',
                        'value' => ['confirmed', 'checked-in'],
                        'compare' => 'IN',
                    ]
                ],
                'nopaging' => true,
            ];
            $check_bookings = get_posts($check_args);
            if ($check_bookings !== false && !empty($check_bookings)) {
                return '<h2>' . __('Multiple Booking', 'rrze-rsvp') . '</h2>'
                    . '<div class="alert alert-danger" role="alert">' . sprintf('%sSie haben für den angegebenen Zeitraum bereits einen Sitzplatz gebucht.%s Wenn Sie Ihre Buchung ändern möchten, stornieren Sie bitte zuerst die bestehende Buchung. Den Link dazu finden Sie in Ihrer Bestätigungsmail.', '<strong>', '</strong><br />') . '</div>';
            }

            $room_id = get_post_meta($booking_seat, 'rrze-rsvp-seat-room', true);
            $room_autoconfirmation = get_post_meta($room_id, 'rrze-rsvp-room-auto-confirmation', true);
            $room_timeslots = get_post_meta($room_id, 'rrze-rsvp-room-timeslots', true);
            foreach ($room_timeslots as $week) {
                foreach ($week['rrze-rsvp-room-weekday'] as $day) {
                    $schedule[$day][$week['rrze-rsvp-room-starttime']] = $week['rrze-rsvp-room-endtime'];
                }
            }
            $weekday = date('N', $booking_timestamp_start);
            $booking_end = array_key_exists($booking_start, $schedule[$weekday]) ? $schedule[$weekday][$booking_start] : $booking_start;
            $booking_timestamp_end = strtotime($booking_date . ' ' . $booking_end);

            //Buchung speichern
            $new_draft = [
                'post_status' => 'publish',
                'post_type' => 'booking',
            ];
            if ($booking_id = wp_insert_post($new_draft)) {
                update_post_meta($booking_id, 'rrze-rsvp-booking-start', $booking_timestamp_start);
                $weekday = date_i18n('w', $booking_timestamp_start);
                update_post_meta($booking_id, 'rrze-rsvp-booking-end', $booking_timestamp_end );
                update_post_meta($booking_id, 'rrze-rsvp-booking-seat', $booking_seat);
                update_post_meta($booking_id, 'rrze-rsvp-booking-guest-lastname', $booking_lastname);
                update_post_meta($booking_id, 'rrze-rsvp-booking-guest-firstname', $booking_firstname);
                update_post_meta($booking_id, 'rrze-rsvp-booking-guest-email', $booking_email);
                update_post_meta($booking_id, 'rrze-rsvp-booking-guest-phone', $booking_phone);
                if ($room_autoconfirmation == 'on') {
                    update_post_meta( $booking_id, 'rrze-rsvp-booking-status', 'confirmed' );
                } else {
                    update_post_meta( $booking_id, 'rrze-rsvp-booking-status', 'booked' );
                }
                
                // E-Mail senden
                if ($room_autoconfirmation == 'on') {
                    $this->email->bookingConfirmedCustomer($booking_id);
                } else {
                    $this->email->bookingRequestedCustomer($booking_id);
                }
                if ($this->options->email_notification_if_new == 'yes' && $this->options->email_notification_email != '') {
                    $to = $this->options->email_notification_email;
                    $subject = _x('[RSVP] New booking received', 'Mail Subject for room admin: new booking received', 'rrze-rsvp');
                    $this->email->bookingRequestedAdmin($to, $subject, $booking_id);
                }

//                wp_redirect(get_permalink().'?submit=success');
//                exit;

            } else {
//                wp_redirect(get_permalink().'?submit=error');
//                exit;

                return '<div class="alert alert-danger" role="alert">' . __('Fehler beim Speichern der Buchung.', 'rrze-rsvp') . '</div>';
            }



            $output .= '<h2>' . __('Your reservation has been submitted. Thank you for booking!', 'rrze-rsvp') . '</h2>';
            if ($room_autoconfirmation == 'on') {
                $output .= '<p>' . __('Your reservation:', 'rrze-rsvp') . '</p>';
            } else {
                $output .= '<p>' . __('Your reservation request:', 'rrze-rsvp') . '</p>';
            }
            $output .= '<ul>'
                . '<li>'. __('Date', 'rrze-rsvp') . ': <strong>' . date_i18n(get_option('date_format'), $booking_timestamp_start) . '</strong></li>'
                . '<li>'. __('Time', 'rrze-rsvp') . ': <strong>' . $booking_start . ' - ' . $booking_end . '</strong></li>'
                . '<li>'. __('Room', 'rrze-rsvp') . ': <strong>' . get_the_title($room_id) . '</strong></li>'
                . '<li>'. __('Seat', 'rrze-rsvp') . ': <strong>' . get_the_title($booking_seat) . '</strong></li>'
                . '</ul>'
                . '<p>' . sprintf(__('Diese Daten wurden Ihnen ebenfalls per E-Mail an %s gesendet.', 'rrze-rsvp'), '<strong>' . $booking_email . '</strong>') . '</p>'
                . '<div class="alert alert-danger" role="alert">';
            if ($room_autoconfirmation == 'on') {
                $output .= sprintf(__('%sDieser Platz wurde verbindlich für Sie reserviert.%s Sie können ihn jederzeit stornieren, falls Sie den Termin nicht wahrnemen können. Informationen dazu finden Sie in Ihrer Bestätigungsmail.', 'rrze-rsvp'),'<strong>', '</strong><br />');
            } else {
                $output .= sprintf(__('%sBitte beachten Sie, dass dies nur eine Reservierungsanfrage ist.%s Sie erst wird verbindlich, sobald wir Ihre Buchung per E-Mail bestätigen.', 'rrze-rsvp'),'<strong>', '</strong><br />');
            }
            $output .= '</div>';

        } else {
            $shortcode_atts = parent::shortcodeAtts($atts, $tag, $this->shortcodesettings);

            $sso = ($shortcode_atts[ 'sso' ] == 'true');
            if ($sso == true && $this->sso == false)
                return '<div class="alert alert-warning" role="alert">' . sprintf('%sSSO not available.%s Please activate SSO authentication or remove the SSO attribute from your shortcode.', '<strong>', '</strong><br />') . '</div>';

//        var_dump($_GET);
            $get_date = isset($_GET[ 'bookingdate' ]) ? sanitize_text_field($_GET[ 'bookingdate' ]) : false;
            $get_time = isset($_GET[ 'timeslot' ]) ? sanitize_text_field($_GET[ 'timeslot' ]) : false;
            $get_room = isset($_GET[ 'room_id' ]) ? absint($_GET[ 'room_id' ]) : false;
            $get_seat = isset($_GET[ 'seat_id' ]) ? absint($_GET[ 'seat_id' ]) : false;

            if ($get_room && $get_date) {
                $availability = Functions::getRoomAvailability(
                    $get_room,
                    $get_date,
                    date('Y-m-d', strtotime($get_date . ' +1 days'))
                );
            }

            $days       = (int)$shortcode_atts[ 'days' ];
            $input_room = sanitize_title($shortcode_atts[ 'room' ]);
            if ($get_room) {
                $input_room = $get_room;
            }
            if (is_numeric($input_room)) {
                $post_room = get_post($input_room);
                if ( ! $post_room) {
                    return __('Room specified in shortcode does not exist.', 'rrze-rsvp');
                }
            }
            $room = $input_room;

            if (isset($post_room)) {
                $today  = date('Y-m-d');
                $endday = date('Y-m-d', strtotime($today . ' + ' . $days . ' days'));
            }

            $output .= '<div class="rrze-rsvp">';
            $output .= '<form action="' . get_permalink() . '" method="post" id="rsvp_by_room">'
                       . '<div id="loading"><i class="fa fa-refresh fa-spin fa-4x"></i></div>';

            $output .= '<p><input type="hidden" value="' . $room . '" id="rsvp_room">'
                       . wp_nonce_field('post_nonce', 'rrze_rsvp_post_nonce_field')
                       . __('Book a seat at', 'rrze-rsvp') . ': <strong>' . get_the_title($room) . '</strong>'
                       . '</p>';

            $output         .= '<div class="rsvp-datetime-container form-group clearfix"><legend>' . __(
                    'Select date and time',
                    'rrze-rsvp'
                ) . '</legend>'
                               . '<div class="rsvp-date-container">';
            $dateComponents = getdate();
            $month          = $dateComponents[ 'mon' ];
            $year           = $dateComponents[ 'year' ];
            $start          = date_create();
            $end            = date_create();
            date_modify($end, '+' . $days . ' days');
            $output .= $this->buildCalendar($month, $year, $start, $end, $room, $get_date);
//        $output .= $this->buildDateBoxes($days);
            $output .= '</div>'; //.rsvp-date-container

            $output .= '<div class="rsvp-time-container">'
                       . '<h4>' . __('Available time slots:', 'rrze-rsvp') . '</h4>';
            if ($get_date) {
                $output .= $this->buildTimeslotSelect($room, $get_date, $get_time, $availability);
            } else {
                $output .= '<div class="rsvp-time-select error">' . __('Please select a date.', 'rrze-rsvp') . '</div>';
            }
            $output .= '</div>'; //.rsvp-time-container

            $output .= '</div>'; //.rsvp-datetime-container

            $output .= '<div class="rsvp-seat-container">';
            if ($get_date && $get_time) {
                $output .= $this->buildSeatSelect($room, $get_date, $get_time, $get_seat, $availability);
            } else {
                $output .= '<div class="rsvp-time-select error">' . __('Please select a date.', 'rrze-rsvp') . '</div>';
            }
            $output .= '</div>'; //.rsvp-seat-container

            $output .= '<legend>' . __('Your data', 'rrze-rsvp') . '</legend>';
            if ($sso) {
                $data = $this->idm->getCustomerData();
                $readonly        = 'readonly';
                $input_lastname  = $data['customer_lastname'];
                $input_firstname = $data['customer_firstname'];
                $input_email     = $data['customer_email'];
            } else {
                $readonly        = '';
                $input_lastname  = '';
                $input_firstname = '';
                $input_email     = '';
            }

            $output .= '<div class="form-group"><label for="rsvp_lastname">'
                       . __('Last name', 'rrze-rsvp') . ' *</label>'
                       . "<input type=\"text\" name=\"rsvp_lastname\" id=\"rsvp_lastname\" required $readonly aria-required=\"true\" value=\"$input_lastname\">"
                       . '</div>';

            $output .= '<div class="form-group"><label for="rsvp_firstname">'
                       . __('First name', 'rrze-rsvp') . ' *</label>'
                       . "<input type=\"text\" name=\"rsvp_firstname\" id=\"rsvp_firstname\" required $readonly aria-required=\"true\" value=\"$input_firstname\">"
                       . '</div>';

            $output .= '<div class="form-group"><label for="rsvp_email">'
                       . __('Email', 'rrze-rsvp') . ' *</label>'
                       . "<input type=\"text\" name=\"rsvp_email\" id=\"rsvp_email\" required $readonly aria-required=\"true\" value=\"$input_email\">"
                       . '</div>';

            $output .= '<div class="form-group"><label for="rsvp_phone">'
                       . __('Phone Number', 'rrze-rsvp') . ' *</label>'
                       . '<input type="tel" name="rsvp_phone" id="rsvp_phone" required aria-required="true">'
                       . '<p class="description">' . __(
                           'Um die Konkakt-Nachverfolgbarkeit im Rahmen der Corona-Bekämpfungsverordnung zu gewährleisten, benötigen wir Ihre Telefonnummer.',
                           'rrze-rsvp'
                       ) . '</p>'
                       . '</div>';

            $output .= '<button type="submit" class="btn btn-primary">' . __('Submit booking', 'rrze-rsvp') . '</button>
                </form>
            </div>';
        }

        wp_enqueue_style('rrze-rsvp-shortcode');
        wp_enqueue_script('rrze-rsvp-shortcode');
        wp_localize_script('rrze-rsvp-shortcode', 'rsvp_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce( 'rsvp-ajax-nonce' ),
        ]);

        return $output;
    }

    /*
     * Inspirationsquelle:
     * https://css-tricks.com/snippets/php/build-a-calendar-table/
     */
    public function buildCalendar($month, $year, $start = '', $end = '', $room = '', $bookingdate_selected = '') {
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
                $selected = $bookingdate_selected == $date ? 'checked="checked"' : '';
                $input_open = "<input type=\"radio\" id=\"rsvp_date_$date\" value=\"$date\" name=\"rsvp_date\" $selected required aria-required='true'><label for=\"rsvp_date_$date\">";
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
            $output .= '<br /> <input type="radio" id="seat_'. $techtime1 . '" name="datetime" value="'. $techtime1 . '" disabled>'
                . '<label for="seat_'. $techtime1 . '" class="disabled"> 09:00-13:30 Uhr</label>';
            $output .= '<br /> <input type="radio" id="seat_'. $techtime2 . '" name="datetime" value="'. $techtime2 . '" disabled>'
                . '<label for="seat_'. $techtime2 . '" class="disabled"> 14:30-19:00 Uhr</label><br />';
            $output .= '';
            $output .= '</div>';
        }
        $output .= '<button class="show-more btn btn-default btn-block">&hellip;'.__('More','rrze-rsvp').'&hellip;</button>';

        return $output;
    }

    public function ajaxUpdateCalendar() {
        check_ajax_referer( 'rsvp-ajax-nonce', 'nonce' );
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
        check_ajax_referer( 'rsvp-ajax-nonce', 'nonce'  );
        $room = ((isset($_POST['room']) && $_POST['room'] > 0) ? (int)$_POST['room'] : '');
        $date = (isset($_POST['date']) ? sanitize_text_field($_POST['date']) : false);
        $time = (isset($_POST['time']) ? sanitize_text_field($_POST['time']) : false);
        $response = [];
        if ($date !== false) {
            $response['time'] = '<div class="rsvp-time-select error">'.__('Please select a date.', 'rrze-rsvp').'</div>';
        }
        if (!$date || !$time) {
            $response['seat'] = '<div class="rsvp-seat-select error">'.__('Please select a date and a time slot.', 'rrze-rsvp').'</div>';
        }
        $availability = Functions::getRoomAvailability($room, $date, date('Y-m-d', strtotime($date. ' +1 days')));
        if ($date) {
            $response['time'] = $this->buildTimeslotSelect($room, $date, $time, $availability);
            if ($time) {
                $response['seat'] = $this->buildSeatSelect($room, $date, $time, false, $availability);
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

    private function buildTimeslotSelect($room, $date, $time = false, $availability) {
        $slots = [];
        $timeSelects = '';
        $slots = array_keys($availability[$date]);
        foreach ($slots as $slot) {
            $id = 'rsvp_time_' . sanitize_title($slot);
            $checked = checked($time !== false && $time == $slot, true, false);
            $timeSelects .= "<div class='form-group'><input type='radio' id='$id' value='$slot' name='rsvp_time' " . $checked . " required aria-required='true'><label for='$id'>$slot</label></div>";
        }
        if ($timeSelects == '') {
            $timeSelects .= __('No time slots available.', 'rrze-rsvp');
        }
        return '<div class="rsvp-time-select">' . $timeSelects . '</div>';
    }

    private function buildSeatSelect($room, $date, $time, $seat_id, $availability) {
        $seats = (isset($availability[$date][$time])) ? $availability[$date][$time] : [];
        $seatSelects = '';
        foreach ($seats as $seat) {
            $seatname = get_the_title($seat);
            $id = 'rsvp_seat_' . sanitize_title($seat);
            $checked = checked($seat_id !== false && $seat == $seat_id, true, false);
            $seatSelects .= "<div class='form-group'>"
                . "<input type='radio' id='$id' value='$seat' name='rsvp_seat' $checked required aria-required='true'>"
                . "<label for='$id'>$seatname</label>"
                . "</div>";
        }
        if ($seatSelects == '') {
            $seatSelects = __('Please select a date and a time slot.', 'rrze-rsvp');
        }
        return '<h4>' . __('Available seats:', 'rrze-rsvp') . '</h4><div class="rsvp-seat-select">' . $seatSelects . '</div>';
    }

}
