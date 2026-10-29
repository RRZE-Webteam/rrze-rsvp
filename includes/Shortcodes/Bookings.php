<?php

namespace RRZE\RSVP\Shortcodes;

use RRZE\RSVP\{Email, Functions, Template, TransientData};
use RRZE\RSVP\Auth\{IdM, LDAP};

use function RRZE\RSVP\Config\defaultOptions;
use function RRZE\RSVP\Config\getShortcodeSettings;

defined('ABSPATH') || exit;

/**
 * Define Shortcode Bookings
 */
class Bookings extends Shortcodes
{
    protected $template;
    private $settings = '';
    private $shortcodesettings = '';
    private $options = '';

    protected $email;
    protected $nonce;

    public function __construct($settings)
    {
        parent::__construct();

        $this->settings = $settings;
        $this->shortcodesettings = getShortcodeSettings();
        $this->options = (object) $this->settings->getOptions();
        $this->email = new Email;
        $this->template = new Template;

        $this->nonce = (isset($_REQUEST['nonce']) && wp_verify_nonce($_REQUEST['nonce'], 'rsvp-availability')) ? $_REQUEST['nonce'] : '';

        add_action('template_redirect', [$this, 'bookingSubmitted']);

        add_action('wp_ajax_UpdateCalendar', [$this, 'ajaxUpdateCalendar']);
        add_action('wp_ajax_nopriv_UpdateCalendar', [$this, 'ajaxUpdateCalendar']);
        add_action('wp_ajax_UpdateForm', [$this, 'ajaxUpdateForm']);
        add_action('wp_ajax_nopriv_UpdateForm', [$this, 'ajaxUpdateForm']);
        add_action('wp_ajax_ShowItemInfo', [$this, 'ajaxShowItemInfo']);
        add_action('wp_ajax_nopriv_ShowItemInfo', [$this, 'ajaxShowItemInfo']);
        add_shortcode('rsvp-booking', [$this, 'shortcodeBooking']);
    }

    /**
     * Render the room booking form, including its availability-driven picker.
     *
     * @param array $atts Shortcode attributes.
     * @param string $content Enclosed shortcode content.
     * @param string $tag Registered shortcode name.
     * @return string Booking form or authentication/availability notice.
     */
    public function shortcodeBooking($atts, $content = '', $tag = '')
    {
        global $post;
        $postID = $post->ID;      

        if (isset($_GET['transient-data']) && isset($_GET['transient-data-nonce']) && wp_verify_nonce($_GET['transient-data-nonce'], 'transient-data-' . $_GET['transient-data'])) {
            $transient = $_GET['transient-data'];
            $transientData = new TransientData($transient);
            if (empty($fieldErrors = $transientData->getData())) {
                $redirectUrl = add_query_arg(
                    [
                        'nonce' => $this->nonce
                    ],
                    get_permalink()
                );
                wp_redirect($redirectUrl);
                exit;
            }
        } 
        
        wp_enqueue_style('rrze-rsvp-shortcode');

        if ($output = $this->ssoAuthenticationError()) {
            return $output;
        }
        // if ($output = $ldapAuthenticationError()) {
        //     return $output;
        // }
        if ($output = $this->postDataError()) {
            return $output;
        }
        if ($output = $this->saveError()) {
            return $output;
        }
        if ($output = $this->multipleBookingError()) {
            return $output;
        }
        if ($output = $this->seatUnavailableError()) {
            return $output;
        }
        if ($output = $this->timeslotUnavailableError()) {
            return $output;
        }
        if ($output = $this->bookedNotice()) {
            return $output;
        }

        $shortcode_atts = parent::shortcodeAtts($atts, $tag, $this->shortcodesettings);
        $input_room = absint($shortcode_atts['room']);
        $post_room = get_post($input_room);
        if (!$post_room) {
            return __('Room specified in shortcode does not exist.', 'rrze-rsvp');
        }

        if ($output = $this->selectRoom($input_room)) {
            return $output;
        }

        // Enqueue shortcode scripts.
        wp_enqueue_script('rrze-rsvp-shortcode');

        // Perhaps the user needs authentication?
        $idm = new IdM;
        $ldap = new LDAP;
        $ssoRequired = Functions::getBoolValueFromAtt(get_post_meta($input_room, 'rrze-rsvp-room-sso-required', true));
        $ldapRequired = Functions::getBoolValueFromAtt(get_post_meta($input_room, 'rrze-rsvp-room-ldap-required', true));
        $ldapRequired = $ldapRequired && $this->settings->getOption('ldap', 'server') ? true : false;
        if (($ssoRequired && !$idm->isAuthenticated()) || ($ldapRequired && !$ldap->isAuthenticated())) {
            if ($output = $this->authForm($ssoRequired, $ldapRequired)) {
                return $output;
            }
        }

        $output = '';

        $roomID = isset($_GET['room_id']) ? absint($_GET['room_id']) : $input_room;
        $roomMeta = get_post_meta($roomID);
        $days = $shortcode_atts['days'] != '' ? (int)$shortcode_atts['days'] : $roomMeta['rrze-rsvp-room-days-in-advance'][0];
        $comment = (isset($roomMeta['rrze-rsvp-room-notes-check']) && $roomMeta['rrze-rsvp-room-notes-check'][0] == 'on');

        $bookingMode = get_post_meta($roomID, 'rrze-rsvp-room-bookingmode', true);
        if ($bookingMode == 'check-only' && !$this->nonce) {

            $alert = '<div class="alert alert-info" role="alert">';
            $alert .= '<p><strong>' . __('Checkin in room', 'rrze-rsvp') . '</strong><br>';
            $alert .= __('Reservations disabled. Please checkin at the seats in the room.', 'rrze-rsvp') . '</p>';
            $alert .= '</div>';


            if (shortcode_exists('collapsibles')) {
                $scheduleinfo = '';
                // Schedule
                $scheduleData = Functions::getRoomSchedule($roomID);
                $schedule = '';
                $weekdays = Functions::daysOfWeekAry(1);
                if (!empty($scheduleData)) {
                    $schedule .= '<table class="rsvp-schedule">';
                    $schedule .= '<tr>'
                        . '<th>' . __('Weekday', 'rrze-rsvp') . '</th>'
                        . '<th>' . __('Time slots', 'rrze-rsvp') . '</th>';
                    $schedule .= '</tr>';
                    foreach ($scheduleData as $weekday => $dailySlots) {
                        $schedule .= '<tr>'
                            . '<td>' . $weekdays[$weekday] . '</td>'
                            . '<td>';
                        $ts = [];
                        foreach ($dailySlots as $start => $end) {
                            $ts[] = $start . ' - ' . $end;
                        }
                        $schedule .= implode('<br />', $ts);
                        $schedule .= '</td>'
                            . '</tr>';
                    }
                    $schedule .= "</table>";
                }
                if (!empty($schedule)) {
                    $scheduleinfo .= '[collapsibles expand-all-link="true"]'
                        . '[collapse title="' . __('Schedule', 'rrze-rsvp') . '" name="schedule" load="open"]'
                        . $schedule
                        . '[/collapse]';
                }

                $scheduleinfo .= '[collapse title="' . __('Current Room Occupancy', 'rrze-rsvp') . '" name="occupancy"]'
                    . Functions::getOccupancyByRoomIdNextHTML($roomID)
                    . '[/collapse]';

                $scheduleinfo .= '[/collapsibles]';
                $schedulehtml = do_shortcode($scheduleinfo);
                $alert  .= $schedulehtml;
            } else {

                $scheduleinfo = '<h2>' . __('Schedule', 'rrze-rsvp') . '</h2>'
                    //. $schedule
                    //. '<h3>' . __('Room occupancy for today', 'rrze-rsvp') . '</h3>';
                    . Functions::getOccupancyByRoomIdNextHTML($postID);
                $alert  .= $scheduleinfo;
            }
            return $alert;
        }

        $get_date = isset($_GET['bookingdate']) ? sanitize_text_field($_GET['bookingdate']) : date('Y-m-d', current_time('timestamp'));
        if (!$this->isCalendarDate($get_date)) {
            $get_date = current_datetime()->format('Y-m-d');
        }
        $get_time = isset($_GET['timeslot']) ? sanitize_text_field($_GET['timeslot']) : false;
        $get_seat = isset($_GET['seat_id']) ? absint($_GET['seat_id']) : false;
        $get_instant = (isset($_GET['instant']) && $_GET['instant'] == '1');

        $availability = Functions::getRoomAvailability(
            $roomID,
            $get_date,
            date('Y-m-d', strtotime($get_date . ' +1 days')),
            false
        );

        $output .= '<div class="rrze-rsvp">';
        $output .= '<form action="' . get_permalink() . '" method="post" id="rsvp_by_room" class="mode-' . $bookingMode . '">';
        if ($get_instant) {
            $output .= '<div><input type="hidden" value="1" id="rsvp_instant" name="rsvp_instant"></div>';
        }

        if (isset($_GET['nonce']) && wp_verify_nonce($_GET['nonce'], 'rsvp-availability')) {
            $output .= '<div><input type="hidden" value="' . $_GET['nonce'] . '" id="rsvp_availability" name="nonce"></div>';
        }
        $output .= '<p><input type="hidden" value="' . $roomID . '" id="rsvp_room" name="rsvp_room">'
            . wp_nonce_field('post_nonce', 'rrze_rsvp_post_nonce_field', TRUE, FALSE)
            . __('Book a seat at', 'rrze-rsvp') . ': <strong>' . get_the_title($roomID) . '</strong>'
            . '</p>';

        if ($bookingMode == 'check-only') {
            // replace Calendar, time and place inputs by get parameters to avoid reservations for future timeslots
            $timeslots = isset($availability[$get_date]) ? $availability[$get_date] : [];
            $timeslot = '';
            foreach ($timeslots as $key => $data) {
                if (substr($key, 0, strlen($get_time)) == $get_time) {
                    $timeslot = $key;
                } else {
                    $timeslot = __('Timeslot not available.', 'rrze-rsvp');
                }
            }
            $output .= '<input type="hidden" name="rsvp_date" value="' . $get_date . '">'
                . '<input type="hidden" name="rsvp_time" value="' . $get_time . '">'
                . '<input type="hidden" name="rsvp_seat" value="' . $get_seat . '">'
                . '<div class="form-group">'
                . '<h2>' . __('Your booking', 'rrze-rsvp') . '</h2>'
                . '<p>' . __('Date', 'rrze-rsvp') . ': <strong>' . date_i18n(get_option('date_format'), strtotime($get_date)) .  '</strong></p>'
                . '<p>' . __('Time', 'rrze-rsvp') . ': <strong>' . $timeslot . '</strong></p>'
                . '<p>' . __('Room', 'rrze-rsvp') . ': <strong>' . get_the_title($roomID) . '</strong></p>'
                . '<p>' . __('Seat', 'rrze-rsvp') . ': <strong>' . get_the_title($get_seat) . '</strong></p>'
                . '</div>';
        } else {
            $get_timestamp = strtotime($get_date);
            $month          = date_i18n('n', $get_timestamp);
            $year           = date_i18n('Y', $get_timestamp);
            $start          = date_i18n('Y-m-d', current_time('timestamp'));
            $end            = date_i18n('Y-m-d', strtotime($start . ' +' . $days . ' days'));
            // Only show dependent selections for a bookable day in the permitted window.
            if ($get_date < $start || $get_date > $end || !array_filter($availability[$get_date] ?? [])) {
                $get_date = '';
                $get_time = false;
                $get_seat = false;
            }
            $output .= '<fieldset class="rsvp-booking-picker">'
                . '<legend>' . esc_html__('Select date and time', 'rrze-rsvp') . '</legend>'
                . '<p class="rsvp-picker-intro">' . esc_html__('Choose a day, then a time that suits you.', 'rrze-rsvp') . '</p>'
                . '<div class="rsvp-picker-layout">'
                . '<fieldset class="rsvp-date-container"><legend><span class="rsvp-picker-step">1</span> ' . esc_html__('Date', 'rrze-rsvp') . '</legend>'
                . $this->buildCalendar($month, $year, $start, $end, $roomID, $get_date)
                . '</fieldset>'
                . '<fieldset class="rsvp-time-container"><legend><span class="rsvp-picker-step">2</span> ' . esc_html__('Time', 'rrze-rsvp') . '</legend>'
                . $this->buildTimeslotSelect($roomID, $get_date, $get_time, $availability)
                . '</fieldset></div>';
            if ($bookingMode != 'consultation') {
                $output .= '<fieldset class="rsvp-seat-container"><legend><span class="rsvp-picker-step">3</span> ' . esc_html__('Seat', 'rrze-rsvp') . '</legend>'
                    . '<div class="rsvp-seat-options">' . $this->buildSeatSelect($roomID, $get_date, $get_time, $get_seat, $availability) . '</div></fieldset>';
            }
            $output .= '<div class="rsvp-picker-summary" aria-live="polite" hidden></div>'
                . '<div class="rsvp-picker-feedback"><span class="rsvp-picker-status" role="status"></span>'
                . '<button type="button" class="rsvp-picker-retry" hidden>' . esc_html__('Try again', 'rrze-rsvp') . '</button></div>'
                . '</fieldset>';
        }

        $output .= '<fieldset>';
        $output .= '<legend>' . __('Your data', 'rrze-rsvp') . ' <span class="notice-required">(' . __('Required', 'rrze-rsvp') . ')</span></legend>';

        if ($idm->isAuthenticated()) {
            $data = $idm->getCustomerData();
            $output .= '<input type="hidden" value="' . $data['customer_lastname'] . '" id="rsvp_lastname" name="rsvp_lastname">';
            $output .= '<input type="hidden" value="' . $data['customer_firstname'] . '" id="rsvp_firstname" name="rsvp_firstname">';
            $output .= '<input type="hidden" value="' . $data['customer_email'] . '" id="rsvp_email" name="rsvp_email">';

            $output .= '<div class="form-group">'
                . '<p>' . __('Last name', 'rrze-rsvp') . ': <strong>' . $data['customer_lastname'] . '</strong></p>'
                . '<p>' . __('First name', 'rrze-rsvp') . ': <strong>' . $data['customer_firstname'] . '</strong></p>'
                . '<p>' . __('Email', 'rrze-rsvp') . ': <strong>' . $data['customer_email'] . '</strong></p>'
                . '</div>';
        }

        if ($ldap->isAuthenticated()) {
            $data = $ldap->getCustomerData();
            $output .= '<input type="hidden" value="' . $data['customer_email'] . '" id="rsvp_email" name="rsvp_email">';

            $output .= '<div class="form-group">'
                . '<p>' . __('Email', 'rrze-rsvp') . ': <strong>' . $data['customer_email'] . '</strong></p>'
                . '</div>';
        }

        if (!$idm->isAuthenticated()) {
            $error = isset($fieldErrors['rsvp_lastname']) ? ' error' : '';
            $value = isset($fieldErrors['rsvp_lastname']['value']) ? $fieldErrors['rsvp_lastname']['value'] : '';
            $message = isset($fieldErrors['rsvp_lastname']['message']) ? $fieldErrors['rsvp_lastname']['message'] : '';
            $output .= '<div class="form-group' . $error . '"><label for="rsvp_lastname">'
                . __('Last name', 'rrze-rsvp') . '</label>'
                . '<input type="text" name="rsvp_lastname" value="' . $value . '" id="rsvp_lastname" required>'
                . '<div class="error-message">' . $message . '</div>'
                . '</div>';

            $error = isset($fieldErrors['rsvp_firstname']) ? ' error' : '';
            $value = isset($fieldErrors['rsvp_firstname']['value']) ? $fieldErrors['rsvp_firstname']['value'] : '';
            $message = isset($fieldErrors['rsvp_firstname']['message']) ? $fieldErrors['rsvp_firstname']['message'] : '';
            $output .= '<div class="form-group' . $error . '"><label for="rsvp_firstname">'
                . __('First name', 'rrze-rsvp') . '</label>'
                . '<input type="text" name="rsvp_firstname" value="' . $value . '" id="rsvp_firstname" required>'
                . '<div class="error-message">' . $message . '</div>'
                . '</div>';
        }

        if (!$idm->isAuthenticated() && !$ldap->isAuthenticated()) {
            $error = isset($fieldErrors['rsvp_email']) ? ' error' : '';
            $value = isset($fieldErrors['rsvp_email']['value']) ? $fieldErrors['rsvp_email']['value'] : '';
            $message = isset($fieldErrors['rsvp_email']['message']) ? $fieldErrors['rsvp_email']['message'] : '';
            $output .= '<div class="form-group' . $error . '"><label for="rsvp_email">'
                . __('Email', 'rrze-rsvp') . '</label>'
                . '<input type="email" name="rsvp_email" value="' . $value . '" '
                . 'pattern="^([^\x00-\x20\x22\x28\x29\x2c\x2e\x3a-\x3c\x3e\x40\x5b-\x5d\x7f-\xff]+|\x22([^\x0d\x22\x5c\x80-\xff]|\x5c[\x00-\x7f])*\x22)(\x2e([^\x00-\x20\x22\x28\x29\x2c\x2e\x3a-\x3c\x3e\x40\x5b-\x5d\x7f-\xff]+|\x22([^\x0d\x22\x5c\x80-\xff]|\x5c[\x00-\x7f])*\x22))*\x40([^\x00-\x20\x22\x28\x29\x2c\x2e\x3a-\x3c\x3e\x40\x5b-\x5d\x7f-\xff]+|\x5b([^\x0d\x5b-\x5d\x80-\xff]|\x5c[\x00-\x7f])*\x5d)(\x2e([^\x00-\x20\x22\x28\x29\x2c\x2e\x3a-\x3c\x3e\x40\x5b-\x5d\x7f-\xff]+|\x5b([^\x0d\x5b-\x5d\x80-\xff]|\x5c[\x00-\x7f])*\x5d))*(\.\w{2,})+$" '
                . 'id="rsvp_email" required>'
                . '<div class="error-message">' . $message . '</div>'
                . '</div>';
        }

        if (CORONA_MODE) {
            $error = isset($fieldErrors['rsvp_phone']) ? ' error' : '';
            $value = isset($fieldErrors['rsvp_phone']['value']) ? $fieldErrors['rsvp_phone']['value'] : '';
            $message = isset($fieldErrors['rsvp_phone']['message']) ? $fieldErrors['rsvp_phone']['message'] : '';
            $output .= '<div class="form-group' . $error . '"><label for="rsvp_phone">'
                . __('Phone Number', 'rrze-rsvp') . '</label>'
                . '<input type="text" name="rsvp_phone" value="' . $value . '" pattern="^([+])?(\d{1,3})?\s?(\(\d{3,5}\)|\d{3,5})?\s?(\d{1,3}\s?|\d{1,3}[-])?(\d{3,8})$" id="rsvp_phone">'
                . '<div class="error-message">' . $message . '</div>'
                . '</div>';
        }

        $defaults = defaultOptions();
        if ($comment) {
            $label = $roomMeta['rrze-rsvp-room-notes-label'][0];
            if ($label == '') {
                $label = $defaults['room-notes-label'];
            }
            $output .= '<div class="form-group">'
                . '<label for="rsvp_comment">' . $label . '</label>'
                . '<textarea name="rsvp_comment" id="rsvp_comment"></textarea>'
                . '</div>';
        }

        if (CORONA_MODE) {
            $showSupplement = !empty($this->options->general_show_dsgvo_supplement) ? $this->options->general_show_dsgvo_supplement : $defaults['show_dsgvo_supplement'];
            if ($showSupplement == 'on') {
                $lang = (strpos(get_locale(), 'de_') === 0 ? 'de' : 'en');
                $optionName = 'general_dsgvo_supplement_' . $lang;
                $dsgvo_supplement = !empty($this->options->$optionName) ? $this->options->$optionName : $defaults['dsgvo_supplement_' . $lang];
            } else {
                $dsgvo_supplement = '';
            }
        } else {
            $dsgvo_supplement = '';
        }

        if (CORONA_MODE) {
            $output .= '<div class="form-group">'
                . '<input type="checkbox" value="1" id="rsvp_dsgvo" name="rsvp_dsgvo" required> '
                . '<label for="rsvp_dsgvo">' . $defaults['dsgvo-declaration'] . '</label>'
                . '</div>'
                . '<div class="form-group no-flex">'
                . $dsgvo_supplement
                . '</div>';
        } else {
            $output .= '<input type="hidden" value="1" id="rsvp_dsgvo" name="rsvp_dsgvo">';
        }

        $output .= '</fieldset>';
        $output .= '<button type="submit" class="btn btn-primary">' . __('Submit booking', 'rrze-rsvp') . '</button>
            </form>
        </div>';

        return $output;
    }

    protected function ssoAuthenticationError()
    {
        if (!isset($_GET['booking']) || !wp_verify_nonce($_GET['booking'], 'sso_authentication')) {
            return '';
        }

        $data = [];
        $data['sso_authentication_error'] = true;
        $data['sso_authentication'] = __('SSO error', 'rrze-rsvp');
        $data['message'] = __("Error retrieving your data from SSO. Please try again or contact the website administrator.", 'rrze-rsvp');

        return $this->template->getContent('shortcode/booking-error', $data);
    }

    // protected function ldapAuthenticationError(){
    //     if (!isset($_GET['booking']) || !wp_verify_nonce($_GET['booking'], 'ldap_authentication')) {
    //         return '';
    //     }

    //     $data = [];
    //     $data['ldap_authentication_error'] = true;
    //     $data['ldap_authentication'] = __('LDAP error', 'rrze-rsvp');
    //     $data['message'] = __("Error retrieving your data from LDAP. Please try again or contact the website administrator.", 'rrze-rsvp');

    //     return $this->template->getContent('shortcode/booking-error', $data);
    // }

    protected function postDataError()
    {
        if (!isset($_GET['booking']) || !wp_verify_nonce($_GET['booking'], 'post_data')) {
            return '';
        }

        $data = [];
        $data['booking_data_error'] = true;
        $data['booking_data'] =  __('Booking data', 'rrze-rsvp');
        $data['message'] =  __('Invalid or missing booking data.', 'rrze-rsvp');

        return $this->template->getContent('shortcode/booking-error', $data);
    }

    protected function saveError()
    {
        if (!isset($_GET['booking']) || !wp_verify_nonce($_GET['booking'], 'save_error-' . $_GET['nonce'])) {
            return '';
        }

        $data = [];
        $data['booking_save_error'] = true;
        $data['booking_save'] =  __('Save booking', 'rrze-rsvp');
        $data['message'] =  __('Error saving the booking.', 'rrze-rsvp');

        return $this->template->getContent('shortcode/booking-error', $data);
    }

    protected function multipleBookingError()
    {
        if (!isset($_GET['booking']) || !wp_verify_nonce($_GET['booking'], 'multiple_booking-' . $_GET['nonce'])) {
            return '';
        }

        $data = [];
        $data['multiple_booking_error'] = true;
        // $data['multiple_booking'] = __('Multiple Booking', 'rrze-rsvp');
        // $data['message'] = __('<strong>You have already booked a seat for the specified time slot.</strong><br>If you want to change your booking, please cancel the existing booking first. You will find the link to do so in your confirmation email.', 'rrze-rsvp');
        $data['multiple_booking'] = __('Save booking', 'rrze-rsvp');
        $data['message'] = __('Error saving the booking.', 'rrze-rsvp');

        return $this->template->getContent('shortcode/booking-error', $data);
    }

    protected function seatUnavailableError()
    {
        if (!isset($_GET['url']) || !isset($_GET['booking']) || !wp_verify_nonce($_GET['booking'], 'seat_unavailable-' . $_GET['url'])) {
            return '';
        }
        // $url = $_GET['url'];
        $url = filter_input(INPUT_GET, 'url', FILTER_SANITIZE_URL);

        if (sanitize_text_field($url) != $url) {
            return '';
        }

        $data = [];
        $data['seat_unavailable_error'] = true;
        $data['seat_already_booked'] = __('Seat already booked', 'rrze-rsvp');
        $data['message'] = __('<strong>Sorry! The seat you selected is no longer available.</strong><br>Please try again.', 'rrze-rsvp');
        $data['backlink'] = sprintf(__('<a href="%s">Back to booking form &rarr;</a>', 'rrze-rsvp'), $url);

        return $this->template->getContent('shortcode/booking-error', $data);
    }

    protected function timeslotUnavailableError()
    {
        if (!isset($_GET['url']) || !isset($_GET['booking']) || !wp_verify_nonce($_GET['booking'], 'timeslot_unavailable-' . $_GET['url'])) {
            return '';
        }
        // $url = $_GET['url'];
        $url = filter_input(INPUT_GET, 'url', FILTER_SANITIZE_URL);

        if (sanitize_text_field($url) != $url) {
            return '';
        }

        $data = [];
        $data['timeslot_unavailable_error'] = true;
        $data['timeslot_in_past'] = __('Timeslot not available.', 'rrze-rsvp');
        $data['message'] = __('<strong>The timeslot you selected lies in the past.</strong><br>Please try again.', 'rrze-rsvp');
        $data['backlink'] = sprintf(__('<a href="%s">Back to booking form &rarr;</a>', 'rrze-rsvp'), $url);

        return $this->template->getContent('shortcode/booking-error', $data);
    }

    protected function selectRoom($input_room = '')
    {
        $get_room = isset($_GET['room_id']) ? absint($_GET['room_id']) : '';
        if ($input_room != '' || $get_room != '') {
            return '';
        }
        $selectRoom = '';
        $rooms = get_posts([
            'post_type' => 'room',
            'post_status' => 'publish',
            'nopaging' => true,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        $selectRoom .= '<form action="' . get_permalink() . '" method="get" id="rsvp_select_room">';
        $selectRoom .= '<p>' . __('Please select a room.', 'rrze-rsvp') . '</p>';
        $selectRoom .= '<select id="rsvp-room-select" name="room_id">';
        foreach ($rooms as $room) {
            $selectRoom .= '<option value="' . $room->ID . '">' . $room->post_title . '</option>';
        }
        $selectRoom .= '</select>';
        $selectRoom .= '<button type="submit" class="btn btn-primary">' . __('Continue booking', 'rrze-rsvp') . '</button>';
        $selectRoom .= '</form>';
        return $selectRoom;
    }

    /**
     * Render a legacy room receipt without exposing course participants or changing SSO.
     *
     * @return string Legacy receipt HTML, or an empty string for an invalid/course link.
     */
    protected function bookedNotice()
    {
        if (!isset($_GET['id']) || !isset($_GET['booking']) || !wp_verify_nonce($_GET['booking'], 'booked-' . $_GET['nonce'])) {
            return '';
        }

        $bookingId = absint($_GET['id']);
        if (Functions::isCourseBooking($bookingId)) {
            return '';
        }

        $idm = new IdM;
        $ldap = new LDAP;

        $queryStr = Functions::getQueryStr([], ['require-auth']);
        $returnTo = trailingslashit(get_permalink()) . ($queryStr ? '?' . $queryStr : '');

        if ($idm->isAuthenticated()) {
            $idm->logout($returnTo);
        }

        if ($ldap->isAuthenticated()) {
            $ldap->logout($returnTo);
        }

        $booking = Functions::getBooking($bookingId);
        if (!$booking) {
            return '';
        }

        $data = [];
        $roomId = $booking['room'];
        $bookingMode = get_post_meta($roomId, 'rrze-rsvp-room-bookingmode', true);
        $roomMeta = get_post_meta($roomId);

        $data['autoconfirmation'] = (isset($roomMeta['rrze-rsvp-room-auto-confirmation']) && $roomMeta['rrze-rsvp-room-auto-confirmation'][0] == 'on');
        $data['force_to_confirm'] = (isset($roomMeta['rrze-rsvp-room-force-to-confirm']) && $roomMeta['rrze-rsvp-room-force-to-confirm'][0] == 'on');
        $data['force_to_checkin'] = (isset($roomMeta['rrze-rsvp-room-force-to-checkin']) && $roomMeta['rrze-rsvp-room-force-to-checkin'][0] == 'on');

        $data['date'] = $booking['date'];
        $data['date_label'] = __('Date', 'rrze-rsvp');
        $data['time'] = $booking['time'];
        $data['time_label'] = __('Time', 'rrze-rsvp');
        $data['room_name'] = $booking['room_name'];
        $data['room_label'] = __('Room', 'rrze-rsvp');
        $data['seat_name'] = ($bookingMode != 'consultation') ? $booking['seat_name'] : '';
        $data['seat_label'] = __('Seat', 'rrze-rsvp');
        $data['customer']['name'] = sprintf('%s %s', $booking['guest_firstname'], $booking['guest_lastname']);
        $data['customer']['email'] = $booking['guest_email'];
        $data['show_notes'] = false;
        if (isset($roomMeta['rrze-rsvp-room-notes-check']) && $roomMeta['rrze-rsvp-room-notes-check'][0] == 'on' && $booking['notes'] != '') {
            $defaults = defaultOptions();
            $notesLabel = $roomMeta['rrze-rsvp-room-notes-label'][0];
            if ($notesLabel == '') {
                $notesLabel = $defaults['room-notes-label'];
            }
            $data['notes_label'] = $notesLabel;
            $data['customer']['notes'] = $booking['notes'];
            $data['show_notes'] = true;
        }

        $data['data_sent_to_customer_email'] = sprintf(__('These data were also sent to your email address <strong>%s</strong>.', 'rrze-rsvp'), $booking['guest_email']);

        // forceToConfirm
        $data['confirm_your_booking'] = __('Your reservation has been submitted. Please confirm your booking!', 'rrze-rsvp');
        $data['confirmation_request_sent'] = __('An email with the confirmation link and your booking information has been sent to your email address.<br><strong>Please note that unconfirmed bookings automatically expire after one hour.</strong>', 'rrze-rsvp');
        // !forceToConfirm
        $data['reservation_submitted'] = __('Your reservation has been submitted. Thank you for booking!', 'rrze-rsvp');

        // autoconfirmation
        $data['your_reservation'] = __('Your reservation:', 'rrze-rsvp');
        // !autoconfirmation
        $data['your_reservation_request'] = __('Your reservation request:', 'rrze-rsvp');

        // !forceToConfirm && autoconfirmation
        $data['place_has_been_reserved'] = __('<strong>This seat has been reserved for you.</strong><br>You can cancel it at any time if you cannot keep the appointment. You can find information on this in your confirmation email.', 'rrze-rsvp');
        // !forceToConfirm && !autoconfirmation
        $data['reservation_request'] = __('<strong>Please note that this is only a reservation request.</strong><br>It only becomes binding as soon as we confirm your booking by email.', 'rrze-rsvp');

        // forceToCheckin
        $data['check_in_when_arrive'] = __('Please remember to <strong>check in</strong> to your seat when you arrive!', 'rrze-rsvp');

        return $this->template->getContent('shortcode/booking-booked', $data);
    }

    public function bookingSubmitted()
    {
        if (!isset($_POST['rrze_rsvp_post_nonce_field']) || !wp_verify_nonce($_POST['rrze_rsvp_post_nonce_field'], 'post_nonce')) {
            return;
        }

        $idm = new IdM;
        $ldap = new LDAP;
        
        array_walk_recursive(
            $_POST,
            function (&$value) {
                if (is_string($value)) {
                    $value = wp_strip_all_tags(trim($value));
                }
            }
        );

        $posted_data = $_POST;
        $booking_date = sanitize_text_field($posted_data['rsvp_date']);
        $booking_start = sanitize_text_field($posted_data['rsvp_time']);
        $booking_timestamp_start = strtotime($booking_date . ' ' . $booking_start);
        if (CORONA_MODE) {
            $booking_phone = sanitize_text_field($posted_data['rsvp_phone']);
        }
        $booking_instant = (isset($posted_data['rsvp_instant']) && $posted_data['rsvp_instant'] == '1');
        $booking_comment = (isset($posted_data['rsvp_comment']) ? sanitize_textarea_field($posted_data['rsvp_comment']) : '');
        $booking_dsgvo = (isset($posted_data['rsvp_dsgvo']) && $posted_data['rsvp_dsgvo'] == '1');
        $booking_room = absint($posted_data['rsvp_room']);
        $booking_mode = get_post_meta($booking_room, 'rrze-rsvp-room-bookingmode', true);
        if ($booking_mode == 'consultation') {
            $room_seats = get_posts([
                'post_type' => 'seat',
                'meta_key' => 'rrze-rsvp-seat-room',
                'meta_value' => $booking_room,
                'numberposts' => 1,
                'orderby' => 'date',
                'order' => 'ASC',
            ]);
            $booking_seat = $room_seats[0]->ID;
        } else {
            $booking_seat = absint($posted_data['rsvp_seat']);
        }
        $room_id = $booking_room;
        $room_meta = get_post_meta($room_id);
        $room_timeslots = isset($room_meta['rrze-rsvp-room-timeslots']) ? unserialize($room_meta['rrze-rsvp-room-timeslots'][0]) : '';
        foreach ($room_timeslots as $week) {
            $valid_from = ((isset($week['rrze-rsvp-room-timeslot-valid-from']) && $week['rrze-rsvp-room-timeslot-valid-from'] != '') ? $week['rrze-rsvp-room-timeslot-valid-from'] : 'unlimited');
            $valid_to   = ((isset($week['rrze-rsvp-room-timeslot-valid-to']) && $week['rrze-rsvp-room-timeslot-valid-to'] != '') ? strtotime(
                '+23 hours, +59 minutes',
                intval($week['rrze-rsvp-room-timeslot-valid-to'])
            ) : 'unlimited');
            foreach ($week['rrze-rsvp-room-weekday'] as $day) {
                if (($valid_from != 'unlimited' && $valid_to != 'unlimited' && $booking_timestamp_start >= $valid_from && $booking_timestamp_start <= $valid_to)
                    || ($valid_from != 'unlimited' && $valid_to == 'unlimited' && $booking_timestamp_start >= $valid_from)
                    || ($valid_from == 'unlimited' && $valid_to != 'unlimited' && $booking_timestamp_start <= $valid_to)
                    || ($valid_from == 'unlimited' && $valid_to == 'unlimited')
                ) {
                    $schedule[$day][$week['rrze-rsvp-room-starttime']] = $week['rrze-rsvp-room-endtime'];
                }
            }
        }
        $weekday = date('N', $booking_timestamp_start);
        $booking_end = array_key_exists($booking_start, $schedule[$weekday]) ? $schedule[$weekday][$booking_start] : $booking_start;
        $booking_timestamp_end = strtotime($booking_date . ' ' . $booking_end);

        if ($idm->isAuthenticated()) {
            if ($idm->isAuthenticated()) {
                $sso_data = $idm->getCustomerData();
                $booking_lastname  = $sso_data['customer_lastname'];
                $booking_firstname  = $sso_data['customer_firstname'];
                $booking_email  = $sso_data['customer_email'];
            } else {
                $redirectUrl = add_query_arg(
                    [
                        'booking' => wp_create_nonce('sso_authentication'),
                        'nonce' => $this->nonce
                    ],
                    get_permalink()
                );
                wp_redirect($redirectUrl);
                exit;
            }
        } elseif ($ldap->isAuthenticated()) {
            if ($ldap->isAuthenticated()) {
                $ldap_data = $ldap->getCustomerData();
                $booking_email  = $ldap_data['customer_email'];
            } else {
                $redirectUrl = add_query_arg(
                    [
                        'booking' => wp_create_nonce('ldap_authentication'),
                        'nonce' => $this->nonce
                    ],
                    get_permalink()
                );
                wp_redirect($redirectUrl);
                exit;
            }
        } else {
            $booking_lastname = sanitize_text_field($posted_data['rsvp_lastname']);
            $booking_firstname = sanitize_text_field($posted_data['rsvp_firstname']);
            $booking_email = sanitize_email($posted_data['rsvp_email']);
        }

        // Postdaten überprüfen
        $transientData = new TransientData(bin2hex(random_bytes(8)));

        if (!$booking_dsgvo) {
            $transientData->addData(
                'rsvp_dsgvo',
                [
                    'value' => $booking_dsgvo,
                    'message' => __('DSGVO field is required.', 'rrze-rsvp')
                ]
            );
        }
        if (!Functions::validateDate($booking_date) || !Functions::validateTime($booking_start, 'H:i')) {
            $transientData->addData(
                'rsvp_date',
                [
                    'value' => $booking_date,
                    'message' => __('The date or time of the booking is not valid.', 'rrze-rsvp')
                ]
            );
        }
        if (!get_post_meta($booking_seat, 'rrze-rsvp-seat-room', true)) {
            $transientData->addData(
                'rsvp_seat',
                [
                    'value' => $booking_seat,
                    'message' => __('The room does not exist.', 'rrze-rsvp')
                ]
            );
        }
        if (empty($booking_lastname)) {
            $transientData->addData(
                'customer_lastname',
                [
                    'value' => $booking_lastname,
                    'message' => __('Your last name is required.', 'rrze-rsvp')
                ]
            );
        } else {
            // encrypt user data
            $booking_lastname = Functions::crypt($booking_lastname, 'encrypt');
        }
        if (empty($booking_firstname)) {
            $transientData->addData(
                'customer_firstname',
                [
                    'value' => $booking_firstname,
                    'message' => __('Your name is required.', 'rrze-rsvp')
                ]
            );
        } else {
            // encrypt user data
            $booking_firstname = Functions::crypt($booking_firstname, 'encrypt');
        }
        if (!filter_var($booking_email, FILTER_VALIDATE_EMAIL)) {
            $transientData->addData(
                '
                rsvp_date',
                [
                    'value' => $booking_email,
                    'message' => __('The email address is not valid.', 'rrze-rsvp')
                ]
            );
        } else {
            // encrypt user data
            $booking_email = Functions::crypt($booking_email, 'encrypt');
        }

        if (CORONA_MODE) {
            if (!Functions::validatePhone($booking_phone)) {
                $transientData->addData(
                    'rsvp_phone',
                    [
                        'value' => $booking_phone,
                        'message' => __('Your phone number is not valid.', 'rrze-rsvp'),
                    ]
                );
            } else {
                // encrypt user data
                $booking_phone = Functions::crypt($booking_phone, 'encrypt');
            }
        }

        if (!empty($transientData->getData(false))) {
            $transient = $transientData->getTransient();
            $redirectUrl = add_query_arg(
                [
                    'transient-data-nonce' => wp_create_nonce('transient-data-' . $transient),
                    'transient-data' => $transient,
                    'nonce' => $this->nonce
                ],
                wp_get_referer()
            );
            wp_redirect($redirectUrl);
            exit;
        }

        // Überprüfen ob bereits eine Buchung mit gleicher E-Mail-Adresse zur gleichen Zeit vorliegt
        $check_args = [
            'post_type' => 'booking',
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'rrze-rsvp-booking-guest-email',
                    'value' => $booking_email
                ],
                [
                    'key' => 'rrze-rsvp-booking-status',
                    'value' => ['booked', 'customer-confirmed', 'confirmed', 'checked-in'],
                    'compare' => 'IN',
                ],
                [
                    'relation' => 'OR',
                    [
                        'key' => 'rrze-rsvp-booking-start',
                        'value' => [$booking_timestamp_start + 1, $booking_timestamp_end - 1],
                        'compare' => 'BETWEEN',
                    ],
                    [
                        'key' => 'rrze-rsvp-booking-end',
                        'value' => [$booking_timestamp_start + 1, $booking_timestamp_end - 1],
                        'compare' => 'BETWEEN',
                    ],
                    [
                        'key' => 'rrze-rsvp-booking-start',
                        'value' => $booking_timestamp_start,
                        'compare' => '=',
                    ],
                    [
                        'key' => 'rrze-rsvp-booking-end',
                        'value' => $booking_timestamp_end,
                        'compare' => '=',
                    ],
                ]
            ],
            'nopaging' => true,
        ];
        $check_bookings = get_posts($check_args);
        if (!empty($check_bookings)) {
            $redirectUrl = add_query_arg(
                [
                    'booking' => wp_create_nonce('multiple_booking-' . $this->nonce),
                    'nonce' => $this->nonce
                ],
                get_permalink()
            );
            wp_redirect($redirectUrl);
            exit;
        }

        // Überprüfen ob Timeslot in der Vergangenheit liegt
        if ($booking_timestamp_end < current_time('timestamp')) {
            $url = urlencode(wp_get_referer());
            $redirectUrl = add_query_arg(
                [
                    //'url' => sprintf('%s?room_id=%s&bookingdate=%s&timeslot=%s', get_permalink(), $room_id, $booking_date, $booking_start),
                    'url' => $url,
                    'booking' => wp_create_nonce('timeslot_unavailable-' . $url),
                    'nonce' => $this->nonce
                ],
                get_permalink()
            );
            wp_redirect($redirectUrl);
            exit;
        }

        // Überprüfen ob der Platz in der Zwischenzeit bereits anderweitig gebucht wurde
        $bookings = get_posts([
            'post_type' => 'booking',
            'post_status' => 'publish',
            'nopaging' => true,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => 'rrze-rsvp-booking-seat',
                    'value'   => $booking_seat,
                ],
                [
                    'key' => 'rrze-rsvp-booking-status',
                    'value'   => ['booked', 'customer-confirmed', 'confirmed', 'checked-in'],
                    'compare' => 'IN'
                ],
                [
                    'key'     => 'rrze-rsvp-booking-start',
                    'value' => strtotime($booking_date . ' ' . $booking_start),
                    'compare' => '=',
                ],
            ],
        ]);

        if (!empty($bookings)) {

            $url = urlencode(wp_get_referer());

            $redirectUrl = add_query_arg(
                [
                    //'url' => sprintf('%s?room_id=%s&bookingdate=%s&timeslot=%s', get_permalink(), $room_id, $booking_date, $booking_start),
                    'url' => $url,
                    'booking' => wp_create_nonce('seat_unavailable-' . $url),
                    'nonce' => $this->nonce
                ],
                get_permalink()
            );
            wp_redirect($redirectUrl);
            exit;
        }

        $autoconfirmation = Functions::getBoolValueFromAtt(get_post_meta($room_id, 'rrze-rsvp-room-auto-confirmation', true));
        $instantCheckIn = Functions::getBoolValueFromAtt(get_post_meta($room_id, 'rrze-rsvp-room-instant-check-in', true));
        $forceToConfirm = Functions::getBoolValueFromAtt(get_post_meta($room_id, 'rrze-rsvp-room-force-to-confirm', true));
        $forceToCheckin = Functions::getBoolValueFromAtt(get_post_meta($room_id, 'rrze-rsvp-room-force-to-checkin', true));
        $sendIcsToAdmin = get_post_meta($room_id, 'rrze-rsvp-room-send-to-email', true);
        if ($booking_mode == 'check-only') {
            $autoconfirmation = true;
            $instantCheckIn = true;
            $forceToCheckin = false;
        }

        //Buchung speichern
        $new_draft = [
            'post_status' => 'publish',
            'post_type' => 'booking',
        ];
        $booking_id = wp_insert_post($new_draft);

        // Booking save error
        if (!$booking_id || is_wp_error($booking_id)) {
            $redirectUrl = add_query_arg(
                [
                    'booking' => wp_create_nonce('save_error-' . $this->nonce),
                    'nonce' => $this->nonce
                ],
                get_permalink()
            );
            wp_redirect($redirectUrl);
            exit;
        }

        // Successful booking saved
        update_post_meta($booking_id, 'rrze-rsvp-booking-start', $booking_timestamp_start);
        $weekday = date_i18n('w', $booking_timestamp_start);
        update_post_meta($booking_id, 'rrze-rsvp-booking-end', $booking_timestamp_end);
        update_post_meta($booking_id, 'rrze-rsvp-booking-seat', $booking_seat);
        update_post_meta($booking_id, 'rrze-rsvp-booking-guest-lastname', $booking_lastname);
        update_post_meta($booking_id, 'rrze-rsvp-booking-guest-firstname', $booking_firstname);
        update_post_meta($booking_id, 'rrze-rsvp-booking-guest-email', $booking_email);
        if (CORONA_MODE) {
            update_post_meta($booking_id, 'rrze-rsvp-booking-guest-phone', $booking_phone);
        }

        // Set booking status
        $timestamp = current_time('timestamp');
        $bookingMode = get_post_meta($room_id, 'rrze-rsvp-room-bookingmode', true);

        if ($forceToConfirm) {
            $status = 'booked';
        } else {
            if ($autoconfirmation) {
                $status = 'confirmed';
                switch ($bookingMode) {
                    case 'check-only':
                        if ($booking_date == date('Y-m-d', $timestamp) && $booking_timestamp_start < $timestamp) {
                            $status = 'checked-in';
                        }
                        break;
                    case 'reservation':
                        if (($booking_instant || $instantCheckIn) && $booking_date == date('Y-m-d', $timestamp) && $booking_timestamp_start < $timestamp) {
                            $status = 'checked-in';
                        }
                        break;
                    default:
                        //
                }
            } else {
                $status = 'booked';
            }
        }

        update_post_meta($booking_id, 'rrze-rsvp-booking-status', $status);
        update_post_meta($booking_id, 'rrze-rsvp-booking-notes', $booking_comment);
        update_post_meta($booking_id, 'rrze-rsvp-booking-dsgvo', $booking_dsgvo);


        // E-Mail senden
        if ($forceToConfirm) {
            $this->email->doEmail('customerConfirmationRequired', 'customer', $booking_id, $status);
        } else {
            if ($bookingMode == 'check-only') {
                $this->email->doEmail('bookingCheckedIn', 'customer', $booking_id, $status);
                if ($this->options->email_notification_if_new == 'yes' && $this->options->email_notification_email != '') {
                    $this->email->doEmail('newBooking', 'admin', $booking_id, $status);
                }
            } elseif ($autoconfirmation) {
                $this->email->doEmail('adminConfirmed', 'customer', $booking_id, $status);
                if ($this->options->email_notification_if_new == 'yes' && $this->options->email_notification_email != '') {
                    $this->email->doEmail('newBooking', 'admin', $booking_id, $status);
                }
            } else {
                $this->email->doEmail('adminConfirmationRequired', 'customer', $booking_id, $status);
                $this->email->doEmail('adminConfirmationRequired', 'admin', $booking_id, $status);
            }
        }


        /*switch($bookingMode) {
            case 'check-only':
                if ($status == 'confirmed') {
                    $this->email->doEmail('adminConfirmed', 'customer', $booking_id, $status);
                    if ($this->options->email_notification_if_new == 'yes' && $this->options->email_notification_email != '') {
                        $this->email->doEmail('newBooking', 'admin', $booking_id, $status);
                    }
                }
                break;
            case 'reservation':
            case 'no-check':
            case 'consultation':
                if ($status == 'confirmed') {
                    $this->email->doEmail('adminConfirmed', 'customer', $booking_id, $status);
                } elseif ($status == 'checked-in') {
                    $this->email->doEmail('adminConfirmed', 'customer', $booking_id, $status);
                } elseif ($status == 'booked' && $forceToConfirm) {
                    $this->email->doEmail('customerConfirmationRequired', 'customer', $booking_id, $status);
                } else {
                    if ($this->options->email_notification_if_new == 'yes' && $this->options->email_notification_email != '') {
                        $this->email->doEmail('newBooking', 'admin', $booking_id, $status);
                    }
                }
                break;
            default:
                //
        }*/

        // Redirect zur Seat-Seite, falls
        if ($status == 'checked-in') {
            if (CORONA_MODE) {
                do_action('rrze-rsvp-tracking', get_current_blog_id(), $booking_id);
            }
            $redirectUrl = add_query_arg(
                [
                    'id' => $booking_id,
                    'nonce' => wp_create_nonce('rrze-rsvp-checkin-booked-' . $booking_id),
                ],
                get_permalink($booking_seat)
            );
            wp_redirect($redirectUrl);
            exit;
        }

        // Redirect to bookedNotice()
        $redirectUrl = add_query_arg(
            [
                'id' => $booking_id,
                'booking' => wp_create_nonce('booked-' . $this->nonce),
                'nonce' => $this->nonce
            ],
            get_permalink()
        );
        wp_redirect($redirectUrl);
        exit;
    }

    /**
     * Render one month with real radio controls and explicit availability states.
     *
     * @param int $month Requested month (1–12).
     * @param int $year Requested year.
     * @param string $start First bookable date, Y-m-d.
     * @param string $end Last bookable date, inclusive, Y-m-d.
     * @param int|string $roomID Room post ID.
     * @param string $bookingdate_selected Selected date, if still available.
     * @return string Calendar markup, clamped to the booking window.
     */
    public function buildCalendar($month, $year, $start = '', $end = '', $roomID = '', $bookingdate_selected = '')
    {
        $today = current_datetime()->format('Y-m-d');
        $start = $this->isCalendarDate($start) ? $start : $today;
        $end = $this->isCalendarDate($end) ? max($start, $end) : (new \DateTimeImmutable($start))->modify('+7 days')->format('Y-m-d');
        $period = sprintf('%04d-%02d', max(1970, min(9999, (int) $year)), max(1, min(12, (int) $month)));
        $period = max(substr($start, 0, 7), min(substr($end, 0, 7), $period));
        $first = new \DateTimeImmutable($period . '-01', wp_timezone());
        $last = $first->modify('last day of this month');
        $monthTitle = wp_date('F Y', $first->getTimestamp());
        $availability = Functions::getRoomAvailability($roomID, max($start, $first->format('Y-m-d')), min($end, $last->format('Y-m-d')), false);
        $calendar = '<div class="rsvp-calendar" data-period="' . esc_attr($period) . '" data-end="' . esc_attr($end) . '">'
            . '<div class="rsvp-calendar-heading">'
            . '<button type="button" class="rsvp-calendar-nav" data-direction="prev" aria-label="' . esc_attr__('Previous month', 'rrze-rsvp') . '"'
            . ($period <= substr($start, 0, 7) ? ' disabled' : '') . '><span aria-hidden="true">‹</span></button>'
            . '<h3 tabindex="-1">' . esc_html($monthTitle) . '</h3>'
            . '<button type="button" class="rsvp-calendar-nav" data-direction="next" aria-label="' . esc_attr__('Next month', 'rrze-rsvp') . '"'
            . ($period >= substr($end, 0, 7) ? ' disabled' : '') . '><span aria-hidden="true">›</span></button></div>'
            . '<table class="rsvp-calendar-table"><caption class="rsvp-picker-sr">' . esc_html($monthTitle) . '</caption><thead><tr>';
        $monday = new \DateTimeImmutable('2024-01-01', wp_timezone());
        for ($weekday = 0; $weekday < 7; $weekday++) {
            $timestamp = $monday->modify('+' . $weekday . ' days')->getTimestamp();
            $calendar .= '<th scope="col"><abbr title="' . esc_attr(wp_date('l', $timestamp)) . '">' . esc_html(wp_date('D', $timestamp)) . '</abbr></th>';
        }
        $calendar .= '</tr></thead><tbody><tr>';
        $offset = (int) $first->format('N') - 1;
        for ($blank = 0; $blank < $offset; $blank++) {
            $calendar .= '<td class="rsvp-calendar-empty"></td>';
        }
        $availableDays = 0;
        for ($day = 1; $day <= (int) $last->format('j'); $day++) {
            if ($day > 1 && ($offset + $day - 1) % 7 === 0) {
                $calendar .= '</tr><tr>';
            }
            $date = $first->setDate((int) $first->format('Y'), (int) $first->format('m'), $day);
            $value = $date->format('Y-m-d');
            $active = $value >= $start && $value <= $end && array_filter($availability[$value] ?? []);
            $state = $active ? 'available' : ($value < $start || $value > $end ? 'outside' : 'unavailable');
            $isToday = $value === $today;
            $description = wp_date('l, j. F Y', $date->getTimestamp());
            $status = $active ? __('Seats available', 'rrze-rsvp') : __('No available appointments', 'rrze-rsvp');
            if ($state === 'outside') {
                $status = __('Not bookable (outside booking period)', 'rrze-rsvp');
            }
            $accessibleLabel = $description . ($isToday ? ' · ' . __('Today', 'rrze-rsvp') : '') . ' · ' . $status;
            $calendar .= '<td class="rsvp-calendar-day is-' . $state . ($isToday ? ' is-today' : '') . '">';
            if ($active) {
                $availableDays++;
                $id = 'rsvp_date_' . $roomID . '_' . $value;
                $calendar .= '<span class="rsvp-picker-choice"><input type="radio" id="' . esc_attr($id) . '" name="rsvp_date" value="' . esc_attr($value) . '"'
                    . ' data-label="' . esc_attr($description) . '" aria-label="' . esc_attr($accessibleLabel) . '"'
                    . checked($bookingdate_selected, $value, false) . ' required>'
                    . '<label for="' . esc_attr($id) . '">' . $day . '</label></span>';
            } else {
                $calendar .= '<span class="rsvp-calendar-inactive" aria-label="' . esc_attr($accessibleLabel) . '" title="' . esc_attr($status) . '">' . $day . '</span>';
            }
            $calendar .= '</td>';
        }
        $trailing = (7 - ($offset + (int) $last->format('j')) % 7) % 7;
        for ($blank = 0; $blank < $trailing; $blank++) {
            $calendar .= '<td class="rsvp-calendar-empty"></td>';
        }
        $calendar .= '</tr></tbody></table><div class="rsvp-calendar-key">'
            . '<span><i class="is-available" aria-hidden="true"></i>' . esc_html__('Available', 'rrze-rsvp') . '</span>'
            . '<span><i class="is-selected" aria-hidden="true">✓</i>' . esc_html__('Selected', 'rrze-rsvp') . '</span>'
            . '<span><i class="is-today" aria-hidden="true"></i>' . esc_html__('Today', 'rrze-rsvp') . '</span></div>';
        if (!$availableDays) {
            $calendar .= '<p class="rsvp-picker-empty">' . esc_html__('No available appointments this month.', 'rrze-rsvp') . '</p>';
        }
        return $calendar . '</div>';
    }

    /**
     * Validate a calendar date without accepting DateTime's automatic rollovers.
     *
     * @param mixed $date Candidate date.
     * @return bool Whether the value is a real, zero-padded Y-m-d date.
     */
    private function isCalendarDate($date): bool
    {
        if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            return false;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed && $parsed->format('Y-m-d') === $date;
    }

    /**
     * Return the adjacent month while retaining the displayed booking horizon.
     *
     * @return void Sends calendar HTML, or HTTP 400 for malformed navigation.
     */
    public function ajaxUpdateCalendar()
    {
        check_ajax_referer('rsvp-ajax-nonce', 'nonce');
        $period = sanitize_text_field(wp_unslash($_POST['month'] ?? ''));
        $direction = sanitize_text_field(wp_unslash($_POST['direction'] ?? ''));
        $end = sanitize_text_field(wp_unslash($_POST['end'] ?? ''));
        if (!$this->isCalendarDate($period . '-01') || !$this->isCalendarDate($end) || !in_array($direction, ['prev', 'next'], true)) {
            wp_die('', '', ['response' => 400]);
        }
        $date = (new \DateTimeImmutable($period . '-01'))->modify($direction === 'next' ? '+1 month' : '-1 month');
        echo $this->buildCalendar((int) $date->format('n'), (int) $date->format('Y'), current_datetime()->format('Y-m-d'), $end, absint($_POST['room'] ?? 0));
        wp_die();
    }

    /**
     * Refresh dependent controls from current availability, dropping stale choices.
     *
     * @return void Sends JSON with time and seat markup.
     */
    public function ajaxUpdateForm()
    {
        check_ajax_referer('rsvp-ajax-nonce', 'nonce');
        $roomID = absint($_POST['room'] ?? 0);
        $date = sanitize_text_field(wp_unslash($_POST['date'] ?? ''));
        $time = sanitize_text_field(wp_unslash($_POST['time'] ?? ''));
        $seat = absint($_POST['seat'] ?? 0);
        $date = $this->isCalendarDate($date) ? $date : '';
        $availability = $date ? Functions::getRoomAvailability($roomID, $date, $date, false) : [];
        $response = ['time' => $this->buildTimeslotSelect($roomID, $date, $time, $availability)];
        if (get_post_meta($roomID, 'rrze-rsvp-room-bookingmode', true) !== 'consultation') {
            $response['seat'] = $this->buildSeatSelect($roomID, $date, $time, $seat, $availability);
        }
        wp_send_json($response);
    }

    /**
     * Render escaped equipment details for an available, selected seat.
     *
     * @param int|string $seatID Seat post ID.
     * @return string Equipment markup, or an empty string when none exists.
     */
    public function buildSeatInfo($seatID = '')
    {
        if (!$seatID) {
            return '';
        }
        $equipment = get_the_terms($seatID, 'rrze-rsvp-equipment');
        if (!$equipment || is_wp_error($equipment)) {
            return '';
        }
        return '<div class="rsvp-item-info"><strong>' . esc_html__('Equipment', 'rrze-rsvp') . '</strong>: '
            . esc_html(implode(', ', wp_list_pluck($equipment, 'name'))) . '</div>';
    }

    /**
     * Render bookable time choices for a single day, excluding empty seat lists.
     *
     * @param int $roomID Room post ID used to namespace input IDs.
     * @param string $date Selected calendar date.
     * @param string|false $time Selected start time.
     * @param array|false $availability Availability grouped by date and interval.
     * @return string Date heading and time controls, or a contextual empty state.
     */
    private function buildTimeslotSelect($roomID, $date, $time = false, $availability = false)
    {
        if (!$date) {
            return '<div class="rsvp-time-select"><p class="rsvp-picker-empty">' . esc_html__('Select an available day in the calendar to see its times.', 'rrze-rsvp') . '</p></div>';
        }
        $dateObject = new \DateTimeImmutable($date, wp_timezone());
        $output = '<div class="rsvp-time-select" data-date="' . esc_attr($date) . '"><p class="rsvp-picker-date">' . esc_html(wp_date('l, j. F', $dateObject->getTimestamp())) . '</p>';
        $choices = '';
        $count = 0;
        foreach ($availability[$date] ?? [] as $slot => $seats) {
            if (!$seats) {
                continue;
            }
            $count++;
            $value = explode('-', $slot)[0];
            $label = str_replace('-', ' – ', $slot);
            $id = 'rsvp_time_' . $roomID . '_' . sanitize_title($value);
            $choices .= '<span class="rsvp-picker-choice"><input type="radio" id="' . esc_attr($id) . '" value="' . esc_attr($value) . '" name="rsvp_time"'
                . ' data-label="' . esc_attr($label) . '"' . checked($time, $value, false) . ' required>'
                . '<label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></span>';
        }
        if (!$choices) {
            return $output . '<p class="rsvp-picker-empty">' . esc_html__('No times are available on this day. Please choose another day.', 'rrze-rsvp') . '</p></div>';
        }
        $scrollable = $count > 12;
        $hintId = 'rsvp_time_hint_' . $roomID;
        if ($scrollable) {
            /* translators: %d: Number of available time slots on the selected day. */
            $output .= '<p class="rsvp-time-hint" id="' . esc_attr($hintId) . '">'
                . esc_html(sprintf(__('%d available time slots. Scroll down to see more times.', 'rrze-rsvp'), $count)) . '</p>';
        }
        return $output . '<div class="rsvp-picker-options' . ($scrollable ? ' rsvp-time-options-scroll' : '') . '"'
            . ($scrollable ? ' tabindex="0" role="group" aria-label="' . esc_attr__('Available time slots:', 'rrze-rsvp') . '" aria-describedby="' . esc_attr($hintId) . '"' : '')
            . '>' . $choices . '</div></div>';
    }

    /**
     * Render available seats as tiles, using a compact native select for large rooms.
     *
     * @param int $roomID Room post ID used to namespace input IDs.
     * @param string $date Selected date.
     * @param string|false $time Selected start time.
     * @param int|false $seat_id Selected seat ID.
     * @param array $availability Availability grouped by date and interval.
     * @return string Seat controls and equipment, or a selection hint.
     */
    private function buildSeatSelect($roomID, $date, $time, $seat_id, $availability)
    {
        $seats = [];
        foreach ($availability[$date] ?? [] as $interval => $ids) {
            if (explode('-', $interval)[0] === (string) $time) {
                $seats = $ids;
                break;
            }
        }
        if (!$seats) {
            return '<p class="rsvp-picker-empty">' . esc_html__('Choose a day and time to see available seats.', 'rrze-rsvp') . '</p>';
        }
        $titles = [];
        foreach ($seats as $seatID) {
            $titles[$seatID] = get_the_title($seatID);
        }
        natsort($titles);
        $output = '<div class="rsvp-seat-select">';
        if (count($titles) > 8) {
            $id = 'rsvp_seat_select_' . $roomID;
            $output .= '<label class="rsvp-picker-sr" for="' . esc_attr($id) . '">' . esc_html__('Select a seat', 'rrze-rsvp') . '</label>'
                . '<select id="' . esc_attr($id) . '" name="rsvp_seat" required><option value="">' . esc_html__('Select a seat', 'rrze-rsvp') . '</option>';
            foreach ($titles as $seat => $title) {
                $output .= '<option value="' . esc_attr($seat) . '"' . selected($seat_id, $seat, false) . '>' . esc_html($title) . '</option>';
            }
            $output .= '</select>';
        } else {
            $output .= '<div class="rsvp-picker-options">';
            foreach ($titles as $seat => $title) {
                $id = 'rsvp_seat_' . $roomID . '_' . $seat;
                $output .= '<span class="rsvp-picker-choice"><input type="radio" id="' . esc_attr($id) . '" value="' . esc_attr($seat) . '" name="rsvp_seat"'
                    . ' data-label="' . esc_attr($title) . '"' . checked($seat_id, $seat, false) . ' required>'
                    . '<label for="' . esc_attr($id) . '">' . esc_html($title) . '</label></span>';
            }
            $output .= '</div>';
        }
        return $output . '</div>' . (isset($titles[$seat_id]) ? $this->buildSeatInfo($seat_id) : '');
    }
}
