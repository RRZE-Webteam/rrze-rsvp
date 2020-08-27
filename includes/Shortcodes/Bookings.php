<?php

namespace RRZE\RSVP\Shortcodes;

use RRZE\RSVP\Email;
use RRZE\RSVP\IdM;
use RRZE\RSVP\Functions;
use RRZE\RSVP\Template;
use RRZE\RSVP\TransientData;

use function RRZE\RSVP\Config\defaultOptions;
use function RRZE\RSVP\Config\getShortcodeSettings;

defined('ABSPATH') || exit;

/**
 * Define Shortcode Bookings
 */
class Bookings extends Shortcodes {
    protected $pluginFile;
    protected $template;
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
        $this->template = new Template;
    }


    public function onLoaded()
    {
        global $post;
        if (is_a($post, '\WP_Post') && is_page()) {
            add_shortcode('rsvp-booking', [$this, 'shortcodeBooking'], 10, 2);
        }
        add_action('template_redirect', [$this, 'maybeAuthenticate']);
        add_action('template_redirect', [$this, 'bookingSubmitted']);

        add_action( 'wp_ajax_UpdateCalendar', [$this, 'ajaxUpdateCalendar'] );
        add_action( 'wp_ajax_nopriv_UpdateCalendar', [$this, 'ajaxUpdateCalendar'] );
        add_action( 'wp_ajax_UpdateForm', [$this, 'ajaxUpdateForm'] );
        add_action( 'wp_ajax_nopriv_UpdateForm', [$this, 'ajaxUpdateForm'] );
        add_action( 'wp_ajax_ShowItemInfo', [$this, 'ajaxShowItemInfo'] );
        add_action( 'wp_ajax_nopriv_ShowItemInfo', [$this, 'ajaxShowItemInfo'] );     
    }

    public function maybeAuthenticate()
    {
        global $post;
        if (!is_a($post, '\WP_Post') || !is_page() || isset($_GET['require-sso-auth'])) {
            return;
        }
        add_shortcode('rsvp-booking', [$this, 'shortcodeBooking'], 10, 2);
        if ($this->hasShortcodeSSO($post->post_content, 'rsvp-booking')
            || ($post->ID < 0
                && isset($_GET['room_id'])
                && ($roomId = absint($_GET['room_id']))
                && (get_post_meta($roomId, 'rrze-rsvp-room-sso-required', true) == 'on'))
        ) {
            $this->sso = $this->idm->tryLogIn();
        }     
    }

    public function shortcodeBooking($atts, $content = '', $tag) { 
        global $post;
        $isFormPage = ($post->ID < 0);

        if (isset($_GET['transient-data']) && isset($_GET['nonce']) && wp_verify_nonce($_GET['nonce'], 'transient-data')) {
            $transient = $_GET['transient-data'];
            $transientData = new TransientData($transient);
            if (empty($fieldErrors = $transientData->getData())) {
                wp_redirect($this->getRequestLink());
                exit;
            }           
        }

        wp_enqueue_style('rrze-rsvp-shortcode');

        if ($output = $this->ssoAuthenticationError()) {
            return $output;
        }
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
        $input_room = sanitize_title($shortcode_atts[ 'room' ]);
        if (is_numeric($input_room)) {
            $post_room = get_post($input_room);
            if ( ! $post_room) {
                return __('Room specified in shortcode does not exist.', 'rrze-rsvp');
            }
        }

        if ($output = $this->selectRoom($input_room)) {
            return $output;
        }

        wp_enqueue_script('rrze-rsvp-shortcode');
        wp_localize_script('rrze-rsvp-shortcode', 'rsvp_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce( 'rsvp-ajax-nonce' ),
        ]);
                
        $output = '';

        $roomID = isset($_GET[ 'room_id' ]) ? absint($_GET[ 'room_id' ]) : $input_room;
        $roomMeta = get_post_meta($roomID);
        $days = $shortcode_atts[ 'days' ] != '' ? (int)$shortcode_atts[ 'days' ] : $roomMeta['rrze-rsvp-room-days-in-advance'][0];
        $comment = (isset($roomMeta['rrze-rsvp-room-notes-check']) && $roomMeta['rrze-rsvp-room-notes-check'][0] == 'on');
        
        if ($isFormPage) {
            $ssoRequired = (get_post_meta($roomID, 'rrze-rsvp-room-sso-required', true) == 'on');
        } else {
            $ssoRequired = ($shortcode_atts['sso'] == 'true');
        }

        if ($ssoRequired && !$this->sso) {
            return '<div class="alert alert-warning" role="alert">' . sprintf('%sSSO not available.%s Please activate SSO authentication or remove the SSO attribute from your shortcode.', '<strong>', '</strong><br />') . '</div>';
        }

        $get_date = isset($_GET[ 'bookingdate' ]) ? sanitize_text_field($_GET[ 'bookingdate' ]) : date('Y-m-d', current_time('timestamp'));
        $get_time = isset($_GET[ 'timeslot' ]) ? sanitize_text_field($_GET[ 'timeslot' ]) : false;
        $get_seat = isset($_GET[ 'seat_id' ]) ? absint($_GET[ 'seat_id' ]) : false;
        $get_instant = (isset($_GET[ 'instant' ]) && $_GET[ 'instant' ] == '1');

        $availability = Functions::getRoomAvailability(
            $roomID,
            $get_date,
            date('Y-m-d', strtotime($get_date . ' +1 days')),
            false
        );

        $output .= '<div class="rrze-rsvp">';
        $output .= '<form action="' . $this->getRequestLink() . '" method="post" id="rsvp_by_room">'
                    . '<div id="loading"><i class="fa fa-refresh fa-spin fa-4x"></i></div>';

        if ($get_instant) {
            $output .= '<div><input type="hidden" value="1" id="rsvp_instant" name="rsvp_instant"></div>';
        }

        $output .= '<p><input type="hidden" value="' . $roomID . '" id="rsvp_room">'
                    . wp_nonce_field('post_nonce', 'rrze_rsvp_post_nonce_field')
                    . __('Book a seat at', 'rrze-rsvp') . ': <strong>' . get_the_title($roomID) . '</strong>'
                    . '</p>';

        $output .= '<div class="rsvp-datetime-container form-group clearfix"><legend>' . __(
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
        $output .= $this->buildCalendar($month, $year, $start, $end, $roomID, $get_date);
        $output .= '</div>'; //.rsvp-date-container

        $output .= '<div class="rsvp-time-container">'
                    . '<h4>' . __('Available time slots:', 'rrze-rsvp') . '</h4>';
        if ($get_date) {
            $output .= $this->buildTimeslotSelect($roomID, $get_date, $get_time, $availability);
        } else {
            $output .= '<div class="rsvp-time-select error">' . __('Please select a date.', 'rrze-rsvp') . '</div>';
        }
        $output .= '</div>'; //.rsvp-time-container

        $output .= '</div>'; //.rsvp-datetime-container

        $output .= '<div class="rsvp-seat-container">';
        if ($get_date && $get_time) {
            $output .= $this->buildSeatSelect($roomID, $get_date, $get_time, $get_seat, $availability);
        } else {
            $output .= '<div class="rsvp-time-select error">' . __('Please select a date and a time slot.', 'rrze-rsvp') . '</div>';
        }
        $output .= '</div>'; //.rsvp-seat-container

        $output .= '<legend>' . __('Your data', 'rrze-rsvp') . '</legend>';
        if ($ssoRequired) {
            $data = $this->idm->getCustomerData();
            $output .= '<input type="hidden" value="' . $data['customer_lastname'] . '" id="rsvp_lastname" name="rsvp_lastname">';
            $output .= '<input type="hidden" value="' . $data['customer_firstname'] . '" id="rsvp_firstname" name="rsvp_firstname">';
            $output .= '<input type="hidden" value="' . $data['customer_email'] . '" id="rsvp_email" name="rsvp_email">';

            $output .= '<div class="form-group">'
                . '<p>' . __('Last name', 'rrze-rsvp') . ': <strong>' . $data['customer_lastname'] . '</strong></p>'
                . '<p>' . __('First name', 'rrze-rsvp') . ': <strong>' . $data['customer_firstname'] . '</strong></p>'
                . '<p>' . __('Email', 'rrze-rsvp') . ': <strong>' . $data['customer_email'] . '</strong></p>'
                . '</div>';
        } else {
            $error = isset($fieldErrors['rsvp_lastname']) ? ' error' : '';
            $value = isset($fieldErrors['rsvp_lastname']['value']) ? $fieldErrors['rsvp_lastname']['value'] : '';
            $message = isset($fieldErrors['rsvp_lastname']['message']) ? $fieldErrors['rsvp_lastname']['message'] : '';    
            $output .= '<div class="form-group' . $error . '"><label for="rsvp_lastname">'
                . __('Last name', 'rrze-rsvp') . ' *</label>'
                . '<input type="text" name="rsvp_lastname" value="' . $value . '" id="rsvp_lastname" required aria-required="true">'
                . '<div class="error-message">' . $message . '</div>'
                . '</div>';

            $error = isset($fieldErrors['rsvp_firstname']) ? ' error' : '';
            $value = isset($fieldErrors['rsvp_firstname']['value']) ? $fieldErrors['rsvp_firstname']['value'] : '';
            $message = isset($fieldErrors['rsvp_firstname']['message']) ? $fieldErrors['rsvp_firstname']['message'] : '';    
            $output .= '<div class="form-group' . $error . '"><label for="rsvp_firstname">'
                . __('First name', 'rrze-rsvp') . ' *</label>'
                . '<input type="text" name="rsvp_firstname" value="' . $value . '" id="rsvp_firstname" required aria-required="true">'
                . '<div class="error-message">' . $message . '</div>'
                . '</div>';

            $error = isset($fieldErrors['rsvp_email']) ? ' error' : '';
            $value = isset($fieldErrors['rsvp_email']['value']) ? $fieldErrors['rsvp_email']['value'] : '';
            $message = isset($fieldErrors['rsvp_email']['message']) ? $fieldErrors['rsvp_email']['message'] : '';    
            $output .= '<div class="form-group' . $error . '"><label for="rsvp_email">'
                . __('Email', 'rrze-rsvp') . ' *</label>'
                . '<input type="text" name="rsvp_email" value="' . $value . '" id="rsvp_email" required aria-required="true">'
                . '<div class="error-message">' . $message . '</div>'
                . '</div>';
        }
        $error = isset($fieldErrors['rsvp_phone']) ? ' error' : '';
        $value = isset($fieldErrors['rsvp_phone']['value']) ? $fieldErrors['rsvp_phone']['value'] : '';
        $message = isset($fieldErrors['rsvp_phone']['message']) ? $fieldErrors['rsvp_phone']['message'] : '';
        $output .= '<div class="form-group' . $error . '"><label for="rsvp_phone">'
            . __('Phone Number', 'rrze-rsvp') . ' *</label>'
            . '<input type="text" name="rsvp_phone" value="' . $value . '" pattern="^([+])?(\d{1,3})?\s?(\(\d{3,5}\)|\d{3,5})?\s?(\d{1,3}\s?|\d{1,3}[-])?(\d{3,8})$" id="rsvp_phone" required aria-required="true">'
            . '<div class="error-message">' . $message . '</div>'
            . '<p class="description">'
            . __('In order to track contacts during the measures against the corona pandemic, it is necessary to record the telephone number.','rrze-rsvp') . '</p>'
            . '</div>';
        $defaults = defaultOptions();
        if ($comment) {
            $label = $roomMeta['rrze-rsvp-room-notes-label'][0];
            if ($label == '') {
                $label = $defaults['room-notes-label'];
            }
            $output .= '<div class="form-group">'
                . '<label for="rsvp_comment">' . $label . '</label>'
                . '<textarea name="rsvp_comment" id="rsvp_comment"></textarea>';
        }

        $output .= '<div class="form-group">'
                    . '<input type="checkbox" value="1" id="rsvp_dsgvo" name="rsvp_dsgvo" required> '
                    . '<label for="rsvp_dsgvo">' . $defaults['dsgvo-declaration'] . '</label>'
                    . '</div>';

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
        if (!isset($_GET['booking']) || !wp_verify_nonce($_GET['booking'], 'save_error')) {
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
        if (!isset($_GET['booking']) || !wp_verify_nonce($_GET['booking'], 'multiple_booking')) {
            return '';
        }

        $data = [];
        $data['multiple_booking_error'] = true;        
        $data['multiple_booking'] = __('Multiple Booking', 'rrze-rsvp');
        $data['message'] = __('<strong>You have already booked a seat for the specified time slot.</strong><br>If you want to change your booking, please cancel the existing booking first. You will find the link to do so in your confirmation email.', 'rrze-rsvp');

        return $this->template->getContent('shortcode/booking-error', $data);
    }

    protected function seatUnavailableError()
    {
        if (!isset($_GET['url']) || !isset($_GET['booking']) || !wp_verify_nonce($_GET['booking'], 'seat_unavailable')) {
            return '';
        }

        $url = $_GET['url'];
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
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
        if (!isset($_GET['url']) || !isset($_GET['booking']) || !wp_verify_nonce($_GET['booking'], 'timeslot_unavailable')) {
            return '';
        }

        $url = $_GET['url'];
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
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
        $get_room = isset($_GET[ 'room_id' ]) ? absint($_GET[ 'room_id' ]) : '';
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

        $selectRoom .= '<form action="' . $this->getRequestLink() . '" method="get" id="rsvp_select_room">';
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

    protected function bookedNotice()
    {
        if (!isset($_GET['id']) || !isset($_GET['booking']) || !wp_verify_nonce($_GET['booking'], 'booked')) {
            return '';
        }

        $bookingId = absint($_GET['id']);
        $booking = Functions::getBooking($bookingId);
        if (!$booking) {
            return '';
        }

        $data = [];
        $roomId = $booking['room'];
        $roomMeta = get_post_meta($roomId);
        $autoconfirmation = (isset($roomMeta['rrze-rsvp-room-auto-confirmation']) && $roomMeta['rrze-rsvp-room-auto-confirmation'][0] == 'on') ? true : false;
        $forceToConfirm = (isset($roomMeta['rrze-rsvp-room-force-to-confirm']) && $roomMeta['rrze-rsvp-room-force-to-confirm'][0] == 'on') ? true : false;
        $forceToCheckin = (isset($roomMeta['rrze-rsvp-room-force-to-checkin']) && $roomMeta['rrze-rsvp-room-force-to-checkin'][0] == 'on') ? true : false;
        if ($autoconfirmation) {
            $data['autoconfirmation'] = true;
        }
        if ($forceToConfirm) {
            $data['force_to_confirm'] = true;
        }        
        if ($forceToCheckin) {
            $data['force_to_checkin'] = true;
        }

        $data['date'] = $booking['date'];
        $data['date_label'] = __('Date', 'rrze-rsvp');
        $data['time'] = $booking['time'];
        $data['time_label'] = __('Time', 'rrze-rsvp');
        $data['room_name'] = $booking['room_name'];
        $data['room_label'] = __('Room', 'rrze-rsvp');
        $data['seat_name'] = $booking['seat_name'];
        $data['seat_label'] = __('Seat', 'rrze-rsvp');        
        $data['customer']['name'] = sprintf('%s %s', $booking['guest_firstname'], $booking['guest_lastname']);
        $data['customer']['email'] = $booking['guest_email'];

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

        array_walk_recursive(
            $_POST, function ( &$value ) {
            if ( is_string( $value ) ) {
                $value = wp_strip_all_tags( trim( $value ) );
            }
            }
        );

        $posted_data = $_POST;
        // echo Helper::get_html_var_dump($posted_data);
        $booking_date = sanitize_text_field($posted_data['rsvp_date']);
        $booking_start = sanitize_text_field($posted_data['rsvp_time']);
        $booking_timestamp_start = strtotime($booking_date . ' ' . $booking_start);
        $booking_seat = absint($posted_data['rsvp_seat']);
        $booking_phone = sanitize_text_field($posted_data['rsvp_phone']);
        $booking_instant = (isset($posted_data['rsvp_instant']) && $posted_data['rsvp_instant'] == '1');
        $booking_comment = (isset($posted_data['rsvp_comment']) ? sanitize_textarea_field($posted_data['rsvp_comment']) : '');
        $booking_dsgvo = (isset($posted_data['rsvp_dsgvo']) && $posted_data['rsvp_dsgvo'] == '1');

        if ($this->sso) {
            if ($this->idm->isAuthenticated()){
                $sso_data = $this->idm->getCustomerData();
                $booking_lastname  = $sso_data['customer_lastname'];
                $booking_firstname  = $sso_data['customer_firstname'];
                $booking_email  = $sso_data['customer_email'];
            } else {
                $redirectUrl = add_query_arg(
                    [
                        'booking' => wp_create_nonce('sso_authentication')
                    ],
                    $this->getRequestLink()
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
        }
        if (empty($booking_firstname)) {
            $transientData->addData(
                'customer_firstname', 
                [
                    'value' => $booking_firstname,
                    'message' => __('Your name is required.', 'rrze-rsvp')
                ]
            );
        }        
        if (!filter_var($booking_email, FILTER_VALIDATE_EMAIL)) {
            $transientData->addData('
            rsvp_date', 
            [
                'value' => $booking_email,
                'message' => __('The email address is not valid.', 'rrze-rsvp')
            ]
        );
        }
        if (!Functions::validatePhone($booking_phone)) {
            $transientData->addData(
                'rsvp_phone', 
                [
                    'value' => $booking_phone,
                    'message' => __('Your phone number is not valid.', 'rrze-rsvp')
                ]
            );
        }

        if (!empty($transientData->getData(false))) {           
            $redirectUrl = add_query_arg(
                [
                    'nonce' => wp_create_nonce('transient-data'),
                    'transient-data' => $transientData->getTransient()
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
                    'key' => 'rrze-rsvp-booking-start',
                    'value' => $booking_timestamp_start
                ],
                [
                    'key' => 'rrze-rsvp-booking-status',
                    'value' => ['booked', 'confirmed', 'checked-in'],
                    'compare' => 'IN',
                ]
            ],
            'nopaging' => true,
        ];
        $check_bookings = get_posts($check_args);      
        if (!empty($check_bookings)) {
            $redirectUrl = add_query_arg(
                [
                    'booking' => wp_create_nonce('multiple_booking')
                ],
                $this->getRequestLink()
            );
            wp_redirect($redirectUrl);
            exit;            
        }

        // Überprüfen ob Timeslot in der Vergangenheit liegt
        $room_id = get_post_meta($booking_seat, 'rrze-rsvp-seat-room', true);
        $room_meta = get_post_meta($room_id);
        $room_timeslots = isset($room_meta['rrze-rsvp-room-timeslots']) ? unserialize($room_meta['rrze-rsvp-room-timeslots'][0]) : '';
        foreach ($room_timeslots as $week) {
            foreach ($week['rrze-rsvp-room-weekday'] as $day) {
                $schedule[$day][$week['rrze-rsvp-room-starttime']] = $week['rrze-rsvp-room-endtime'];
            }
        }
        $weekday = date('N', $booking_timestamp_start);
        $booking_end = array_key_exists($booking_start, $schedule[$weekday]) ? $schedule[$weekday][$booking_start] : $booking_start;
        $booking_timestamp_end = strtotime($booking_date . ' ' . $booking_end);

        if ($booking_timestamp_end < current_time('timestamp')) {
            $permalink = get_permalink($this->options->general_booking_page);
            $redirectUrl = add_query_arg(
                [
                    'url' => sprintf('%s?room_id=%s&bookingdate=%s&timeslot=%s', $permalink, $room_id, $booking_date, $booking_start),
                    'booking' => wp_create_nonce('timeslot_unavailable')
                ],
                $this->getRequestLink()
            );
            wp_redirect($redirectUrl);
            exit;
        }

        // Überprüfen ob der Platz in der Zwischenzeit bereits anderweitig gebucht wurde
        $check_availability = Functions::getSeatAvailability($booking_seat, $booking_date, date('Y-m-d', strtotime($booking_date. ' +1 days')));
        $seat_available = false;
        foreach ($check_availability[$booking_date] as $timeslot) {
            if (strpos($timeslot, $booking_start) == 0) {
                $seat_available = true;
                break;
            }
        }
        if (!$seat_available) {
            $permalink = get_permalink($this->options->general_booking_page);

            $redirectUrl = add_query_arg(
                [
                    'url' => sprintf('%s?room_id=%s&bookingdate=%s&timeslot=%s', $permalink, $room_id, $booking_date, $booking_start),
                    'booking' => wp_create_nonce('seat_unavailable')
                ],
                $this->getRequestLink()
            );
            wp_redirect($redirectUrl);
            exit;                
        }

        $autoconfirmation = get_post_meta($room_id, 'rrze-rsvp-room-auto-confirmation', true) == 'on' ? true : false;
        $forceToConfirm = get_post_meta($room_id, 'rrze-rsvp-room-force-to-confirm', true) == 'on' ? true : false;
        $forceToCheckin = get_post_meta($room_id, 'rrze-rsvp-room-force-to-checkin', true) == 'on' ? true : false;

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
                    'booking' => wp_create_nonce('save_error')
                ],
                $this->getRequestLink()
            );
            wp_redirect($redirectUrl);
            exit;            
        }

        // Successful booking saved
        update_post_meta($booking_id, 'rrze-rsvp-booking-start', $booking_timestamp_start);
        $weekday = date_i18n('w', $booking_timestamp_start);
        update_post_meta($booking_id, 'rrze-rsvp-booking-end', $booking_timestamp_end );
        update_post_meta($booking_id, 'rrze-rsvp-booking-seat', $booking_seat);
        update_post_meta($booking_id, 'rrze-rsvp-booking-guest-lastname', $booking_lastname);
        update_post_meta($booking_id, 'rrze-rsvp-booking-guest-firstname', $booking_firstname);
        update_post_meta($booking_id, 'rrze-rsvp-booking-guest-email', $booking_email);
        update_post_meta($booking_id, 'rrze-rsvp-booking-guest-phone', $booking_phone);
        if ($autoconfirmation) {
            $status = 'confirmed';
            $timestamp = current_time('timestamp');
            if ($booking_instant && $booking_date == date('Y-m-d', $timestamp) && $booking_timestamp_start < $timestamp) {
                $status = 'checked-in';
            }
        } else {
            $status = 'booked';
        }
        update_post_meta( $booking_id, 'rrze-rsvp-booking-status', $status );
        update_post_meta($booking_id, 'rrze-rsvp-booking-notes', $booking_comment);
        update_post_meta($booking_id, 'rrze-rsvp-booking-dsgvo', $booking_dsgvo);
        if ($forceToConfirm) {
            update_post_meta($booking_id, 'rrze-rsvp-customer-status', 'booked');
        }

        // E-Mail senden
        if ($autoconfirmation) {
            if ($forceToConfirm) {
                $this->email->bookingRequestedCustomer($booking_id);
            } else {
                $this->email->bookingConfirmedCustomer($booking_id);
            }
        } else {
            if ($this->options->email_notification_if_new == 'yes' && $this->options->email_notification_email != '') {
                $to = $this->options->email_notification_email;
                $subject = _x('[RSVP] New booking received', 'Mail Subject for room admin: new booking received', 'rrze-rsvp');
                $this->email->bookingRequestedAdmin($to, $subject, $booking_id);
            }
        }

        // Redirect zur Seat-Seite, falls
        if ($status == 'checked-in') {
            wp_redirect(get_permalink($booking_seat));
            exit;
        }

        $redirectUrl = add_query_arg(
            [
                'id' => $booking_id,
                'booking' => wp_create_nonce('booked')
            ],
            $this->getRequestLink()
        );
        wp_redirect($redirectUrl);
        exit;
    }

    /*
     * Inspirationsquelle:
     * https://css-tricks.com/snippets/php/build-a-calendar-table/
     */
    public function buildCalendar($month, $year, $start = '', $end = '', $roomID = '', $bookingdate_selected = '') {
        if ($start == '')
            $start = date_create();
        if (!is_object($end))
            $end = date_create($end);
        if ($roomID == 'select')
            $roomID = '';
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
        $availability = Functions::getRoomAvailability($roomID, $startDate, $endDate, false);

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
            if ($date < date_format($bookingDaysStart, 'Y-m-d') || $date > date_format($bookingDaysEnd, 'Y-m-d')) {
                $active = false;
                $title = __('Not bookable (outside booking period)','rrze-rsvp');
            } else {
                $active = false;
                $class = 'soldout';
                $title = __('Not bookable (soldout or room blocked)','rrze-rsvp');
                if ($roomID == '') {

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
                if ($bookingdate_selected == $date || ($bookingdate_selected == false && $date == $startDate)) {
                    $selected = 'checked="checked"';
                } else {
                    $selected = '';
                }
                //$selected = $bookingdate_selected == $date ? 'checked="checked"' : '';
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
        $roomID = (int)$_POST['room'];
        $output = '';
        $output .= $this->buildCalendar($period[1] + $mod, $period[0], $start, $end, $roomID);
        echo $output;
        wp_die();
    }

    public function ajaxUpdateForm() {
        check_ajax_referer( 'rsvp-ajax-nonce', 'nonce'  );
        $roomID = ((isset($_POST['room']) && $_POST['room'] > 0) ? (int)$_POST['room'] : '');
        $date = (isset($_POST['date']) ? sanitize_text_field($_POST['date']) : false);
        $time = (isset($_POST['time']) ? sanitize_text_field($_POST['time']) : false);
        $seat = (isset($_POST['seat']) ? sanitize_text_field($_POST['seat']) : false);
        $response = [];
        if ($date !== false) {
            $response['time'] = '<div class="rsvp-time-select error">'.__('Please select a date.', 'rrze-rsvp').'</div>';
        }
        if (!$date || !$time) {
            $response['seat'] = '<div class="rsvp-seat-select error">'.__('Please select a date and a time slot.', 'rrze-rsvp').'</div>';
        }
        $availability = Functions::getRoomAvailability($roomID, $date, date('Y-m-d', strtotime($date. ' +1 days')), false);

        if ($date) {
            $response['time'] = $this->buildTimeslotSelect($roomID, $date, $time, $availability);
            if ($time) {
                $response['seat'] = $this->buildSeatSelect($roomID, $date, $time, $seat, $availability);
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
        $output .= '<div class="rsvp-item-info">';
        if ($equipment !== false) {
            $output .= '<div class="rsvp-item-equipment"><h5 class="small">' . sprintf( __( 'Seat %s', 'rrze-rsvp' ), $seat_name ) . '</h5>';
            foreach  ($equipment as $e) {
                $e_arr[] = $e->name;
            }
            $output .= '<p><strong>' . __('Equipment','rrze-rsvp') . '</strong>: ' . implode(', ', $e_arr) . '</p>';
            $output .= '</div>';
        }
        $output .= '</div>';
        echo $output;
        wp_die();
    }

    private function buildTimeslotSelect($roomID, $date, $time = false, $availability) {
        $slots = [];
        $timeSelects = '';
        if (!empty($availability) && isset($availability[$date])) {
            $slots = array_keys($availability[$date]);
            foreach ($slots as $slot) {
                $slot_value = explode('-', $slot)[0];
                $id = 'rsvp_time_' . sanitize_title($slot_value);
                $checked = checked($time !== false && $time == $slot_value, true, false);
                $timeSelects .= "<div class='form-group'><input type='radio' id='$id' value='$slot_value' name='rsvp_time' " . $checked . " required aria-required='true'><label for='$id'>$slot</label></div>";
            }
        }
        if ($timeSelects == '') {
            $timeSelects .= __('No time slots available.', 'rrze-rsvp');
        }
        return '<div class="rsvp-time-select">' . $timeSelects . '</div>';
    }

    private function buildSeatSelect($roomID, $date, $time, $seat_id, $availability) {
        foreach ($availability as $xdate => $xtime) {
            foreach ($xtime as $k => $v) {
                $k_new = explode('-', $k)[0];
                $availability[$xdate][$k_new] = $v;
                unset($availability[$xdate][$k]);
            }
        }
        $seats = (isset($availability[$date][$time])) ? $availability[$date][$time] : [];
        //var_dump($seats);
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

    protected function getRequestLink()
    {
        global $wp;
        return site_url($wp->request);
    }

    protected function hasShortcodeSSO(string $content, string $shortcode): bool
    {
        if (has_shortcode($content, $shortcode)) {
            $result = [];
            $pattern = get_shortcode_regex();
            if (preg_match_all('/' . $pattern . '/s', $content, $matches)) {
                $keys = [];
                $result = [];
                foreach ($matches[0] as $key => $value) {
                    $get = str_replace(' ', '&', $matches[3][$key]);
                    parse_str($get, $output);
                    $keys = array_unique(array_merge($keys, array_keys($output)));
                    $result[][$matches[2][$key]] = $output;
                }
            }
            foreach ($result as $key => $value) {
                if (isset($value[$shortcode]['sso']) && $value[$shortcode]['sso'] == 'true') {
                    return true;
                }
            }
        }
        return false;
    } 
}
