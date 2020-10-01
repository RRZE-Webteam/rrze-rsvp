<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

class Email
{
    /**
     * Options
     * @var object
     */
    protected $options;

    /**
     * Template object
     * @var object
     */
    protected $template;

    /**
     * True if locale is an english language
     * @var boolean
     */
    protected $isLocaleEnglish;

    /**
     * __construct
     */
    public function __construct()
    {
        $settings = new Settings(plugin()->getFile());
        $this->options = (object) $settings->getOptions();
        $this->template = new Template;
        $this->isLocaleEnglish = Functions::isLocaleEnglish();
    }

    /**
     * send
     * Send an email.
     * @param string $to
     * @param string $subject
     * @param string $message
     * @param string $altMessage
     * @param string $attachment
     * @return void
     */
    public function send(string $to, string $subject, string $message, string $altMessage = '', string $attachment = '')
    {
        $data = [
            'subject' => $subject,
            'message' => $message,
            'alt_message' => $altMessage
        ];

        $body = $this->template->getContent('email/email-body', $data);
        $altBody = $this->template->getContent('email/email-body.txt', $data);

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit'
        ];

        add_action('phpmailer_init', function ($phpmailer) use ($altBody) {
            $phpmailer->AltBody = $altBody;
        });

        add_filter('wp_mail_from', [$this, 'filterEmail']);
        add_filter('wp_mail_from_name', [$this, 'filterName']);

        wp_mail($to, $subject, $body, $headers, $attachment);

        remove_filter('wp_mail_from_name', [$this, 'filterName']);
        remove_filter('wp_mail_from', [$this, 'filterEmail']);
    }

    /**
     * filterEmail
     * Callable function of the hook 'wp_mail_from'. 
     * Filters the email address to send from.
     * @param string $from Sender's email address
     * @return string
     */
    public function filterEmail($from): string
    {
        $newFrom = $this->options->email_sender_email;
        return ($newFrom != '') ? $newFrom : $from;
    }

    /**
     * filterName
     * Callable function of the hook 'wp_mail_from_name'.
     * Filters the name to associate with the "from" email address.
     * @param string $name Sender's name
     * @return string
     */
    public function filterName($name): string
    {
        $newName = $this->options->email_sender_name;
        return ($newName != '') ? $newName : $name;
    }

    /**
     * bookingRequestedAdmin
     * Send an email to the admin if a new booking is requested. Optionally, 
     * the admin can confirm or cancel the reservation using the corresponding 
     * links included in the email message.
     * @param string $to Customer email address
     * @param string $subject Email subject
     * @param integer $bookingId Booking Id
     * @param string $bookingMode Booking mode: 'check-only', 'reservation' or 'consultation'
     * @return void
     */
    public function bookingRequestedAdmin(string $to, string $subject, int $bookingId, string $bookingMode = 'reservation')
    {
        $booking = Functions::getBooking($bookingId);
        if (empty($booking)) {
            return;
        }

        $customerName = sprintf('%s: %s %s', __('Name', 'rrze-rsvp'), $booking['guest_firstname'], $booking['guest_lastname']);
        $customerEmail = sprintf('%s: %s', __('Email', 'rrze-rsvp'), $booking['guest_email']);
        $confirmUrl = Functions::bookingReplyUrl('confirm', sprintf('%s-%s', $bookingId, $booking['start']), $bookingId);
        $cancelUrl = Functions::bookingReplyUrl('cancel', sprintf('%s-%s', $bookingId, $booking['start']), $bookingId);

        $data = [];
        $data['header_image'] = has_header_image() ? get_header_image() : false;

        $data['subject'] = $subject;
        $data['text'] = __('You received a new request for a booking.', 'rrze-rsvp');
        $data['date'] = $booking['date'];
        $data['time'] = $booking['time'];
        $data['room_name'] = $booking['room_name'];
        $data['seat_name'] = ($bookingMode != 'consultation') ? $booking['seat_name'] : '';
        $data['customer']['name'] = $customerName;
        $data['customer']['email'] = $customerEmail;
        // Confirm booking
        $data['confirm_url'] = $confirmUrl;
        $data['confirm_btn'] = _x('Confirm', 'Booking', 'rrze-rsvp');
        $data['alt_confirm_text'] = __('Confirm the booking.', 'rrze-rsvp');
        // Cancel booking
        $data['cancel_url'] = $cancelUrl;
        $data['cancel_btn'] = _x('Cancel', 'Booking', 'rrze-rsvp');
        $data['alt_cancel_text'] = __('Cancel the booking.', 'rrze-rsvp');
        // Site URL
        $data['site_url'] = site_url();
        $data['site_name'] = get_bloginfo('name') ? get_bloginfo('name') : parse_url(site_url(), PHP_URL_HOST);

        $message = $this->template->getContent('email/booking-requested-admin', $data);
        $altMessage = $this->template->getContent('email/booking-requested-admin.txt', $data);

        $this->send($to, $subject, $message, $altMessage);
    }

    /**
     * bookingCancelledAdmin
     * Send an email to the admin when the customer cancels the booking.
     * @param integer $bookingId Booking Id
     * @param string $bookingMode Booking mode: 'check-only', 'reservation' or 'consultation'
     * @return void
     */
    public function bookingCancelledAdmin(int $bookingId, string $bookingMode = 'reservation')
    {
        $booking = Functions::getBooking($bookingId);
        if (empty($booking)) {
            return;
        }

        $to = $this->options->email_notification_email;
        $subject = __('Booking has been cancelled', 'rrze-rsvp');

        $customerName = sprintf('%s: %s %s', __('Name', 'rrze-rsvp'), $booking['guest_firstname'], $booking['guest_lastname']);
        $customerEmail = sprintf('%s: %s', __('Email', 'rrze-rsvp'), $booking['guest_email']);

        $text = __('A booking has been cancelled by the customer.', 'rrze-rsvp');

        $data = [];
        $data['header_image'] = has_header_image() ? get_header_image() : false;

        $data['subject'] = $subject;
        $data['text'] = $this->placeholderParser($text, $booking);
        $data['date'] = $booking['date'];
        $data['time'] = $booking['time'];
        $data['room_name'] = $booking['room_name'];
        $data['seat_name'] = ($bookingMode != 'consultation') ? $booking['seat_name'] : '';
        $data['customer']['name'] = $customerName;
        $data['customer']['email'] = $customerEmail;
        // Site URL
        $data['site_url'] = site_url();
        $data['site_name'] = get_bloginfo('name') ? get_bloginfo('name') : parse_url(site_url(), PHP_URL_HOST);

        $message = $this->template->getContent('email/booking-cancelled-admin', $data);
        $altMessage = $this->template->getContent('email/booking-cancelled-admin.txt', $data);

        $this->send($to, $subject, $message, $altMessage);
    }

    /**
     * bookingCheckedoutAdmin
     * Send an email to the admin when the customer has checked out from the seat.
     * @param integer $bookingId Booking Id
     * @param string $bookingMode Booking mode: 'check-only', 'reservation' or 'consultation'
     * @return void
     */
    public function bookingCheckedoutAdmin(int $bookingId, string $bookingMode = 'reservation')
    {
        $booking = Functions::getBooking($bookingId);
        if (empty($booking)) {
            return;
        }

        $to = $this->options->email_notification_email;
        $subject = __('Seat checked out', 'rrze-rsvp');

        $customerName = sprintf('%s: %s %s', __('Name', 'rrze-rsvp'), $booking['guest_firstname'], $booking['guest_lastname']);
        $customerEmail = sprintf('%s: %s', __('Email', 'rrze-rsvp'), $booking['guest_email']);

        $text = __('A customer has checked out from a seat.', 'rrze-rsvp');

        $data = [];
        $data['header_image'] = has_header_image() ? get_header_image() : false;

        $data['subject'] = $subject;
        $data['text'] = $this->placeholderParser($text, $booking);
        $data['date'] = $booking['date'];
        $data['time'] = $booking['time'];
        $data['room_name'] = $booking['room_name'];
        $data['seat_name'] = ($bookingMode != 'consultation') ? $booking['seat_name'] : '';
        $data['customer']['name'] = $customerName;
        $data['customer']['email'] = $customerEmail;
        // Site URL
        $data['site_url'] = site_url();
        $data['site_name'] = get_bloginfo('name') ? get_bloginfo('name') : parse_url(site_url(), PHP_URL_HOST);

        $message = $this->template->getContent('email/booking-checkedout-admin', $data);
        $altMessage = $this->template->getContent('email/booking-checkedout-admin.txt', $data);

        $this->send($to, $subject, $message, $altMessage);
    }

    /**
     * bookingRequestedCustomer
     * Send an email to the customer if a new booking is requested. The booking 
     * is not yet confirmed. An admin action is required for confirmation or 
     * cancellation of the booking. Optionally, the customer can cancel the 
     * reservation using the link included in the email message.
     * @param integer $bookingId
     * @param string $bookingMode Booking mode: 'check-only', 'reservation' or 'consultation'
     * @return void
     */
    public function bookingRequestedCustomer(int $bookingId, string $bookingMode = 'reservation')
    {
        $booking = Functions::getBooking($bookingId);
        if (empty($booking)) {
            return;
        }

        $forceToConfirm = get_post_meta($booking['room'], 'rrze-rsvp-room-force-to-confirm', true);

        if (!$forceToConfirm) {
            $subject = $this->options->email_received_subject;
            $subject = $this->placeholderParser($subject, $booking);
            $subjectEnglish = $this->options->email_received_subject_en;
            $subjectEnglish = $this->placeholderParser($subjectEnglish, $booking);
            $text = $this->options->email_received_text;
            $textEnglish = $this->options->email_received_text_en;
        } else {
            $subject = $this->options->email_force_to_confirm_subject;
            $subject = $this->placeholderParser($subject, $booking);
            $subjectEnglish = $this->options->email_force_to_confirm_subject_en;
            $subjectEnglish = $this->placeholderParser($subjectEnglish, $booking);
            $text = $this->options->email_force_to_confirm_text;
            $textEnglish = $this->options->email_force_to_confirm_text_en;
        }

        $confirmUrl = Functions::bookingReplyUrl('confirm', sprintf('%s-%s-customer', $bookingId, $booking['start']), $bookingId);
        $cancelUrl = Functions::bookingReplyUrl('cancel', sprintf('%s-%s-customer', $bookingId, $booking['start']), $bookingId);

        $data = [];
        // Is locale not english?
        $data['is_locale_not_english'] = !$this->isLocaleEnglish ? true : false;
        // Header Image
        $data['header_image'] = has_header_image() ? get_header_image() : false;
        // Force to confirm
        $data['force_to_confirm'] = $forceToConfirm ? true : false;

        $data['subject'] = $subject;
        $data['subject_en'] = $subjectEnglish;
        $data['text'] = $this->placeholderParser($text, $booking);
        $data['text_en'] = $this->placeholderParser($textEnglish, $booking);
        $data['date'] = $booking['date'];
        $data['date_en'] = $booking['date_en'];
        $data['time'] = $booking['time'];
        $data['time_en'] = $booking['time_en'];
        $data['room_name'] = $booking['room_name'];
        $data['seat_name'] = ($bookingMode != 'consultation') ? $booking['seat_name'] : '';

        // Confirm booking
        $data['confirm_url'] = $confirmUrl;
        $data['confirm_btn'] = _x('Confirm', 'Booking', 'rrze-rsvp');
        $data['confirm_btn_en'] = 'Confirm';
        $data['confirm_text'] = __('Please confirm your booking now.', 'rrze-rsvp');
        $data['confirm_text_en'] = 'Please confirm your booking now.';
        $data['alt_confirm_text'] = __('Please confirm your booking now.', 'rrze-rsvp');
        $data['alt_confirm_text_en'] = 'Please confirm your booking now.';
        // Cancel booking
        $data['cancel_url'] = $cancelUrl;
        $data['cancel_btn'] = _x('Cancel', 'Booking', 'rrze-rsvp');
        $data['cancel_btn_en'] = 'Cancel';
        $data['cancel_text'] = __('Please cancel your booking in time if your plans change.', 'rrze-rsvp');
        $data['cancel_text_en'] = 'Please cancel your booking in time if your plans change.';
        $data['alt_cancel_text'] = __('Please cancel your booking in time if your plans change.', 'rrze-rsvp');
        $data['alt_cancel_text_en'] = 'Please cancel your booking in time if your plans change.';
        // Site URL
        $data['site_url'] = site_url();
        $data['site_name'] = get_bloginfo('name') ? get_bloginfo('name') : parse_url(site_url(), PHP_URL_HOST);

        $message = $this->template->getContent('email/booking-requested-customer', $data);
        $altMessage = $this->template->getContent('email/booking-requested-customer.txt', $data);

        $this->send($booking['guest_email'], $subject, $message, $altMessage);
    }

    /**
     * bookingConfirmedCustomer
     * Send a booking confirmation email to the customer. Optionally, 
     * the customer can check in, check out or cancel the booking through 
     * the respective links included in the email message.
     * @param integer $bookingId Booking Id
     * @param string $bookingMode Booking mode: 'check-only', 'reservation' or 'consultation'
     * @param boolean $status Status of the booking
     * @return void
     */
    public function bookingConfirmedCustomer(int $bookingId, string $bookingMode = 'reservation', string $status = 'confirmed')
    {
        $booking = Functions::getBooking($bookingId);
        if (empty($booking)) {
            return;
        }

        $subject = $this->options->email_confirm_subject;
        $subject = $this->placeholderParser($subject, $booking);
        $subjectEnglish = $this->options->email_confirm_subject_en;
        $subjectEnglish = $this->placeholderParser($subjectEnglish, $booking);

        $text = $this->options->email_confirm_text;
        $textEnglish = $this->options->email_confirm_text_en;
        $checkInUrl = Functions::bookingReplyUrl('checkin', sprintf('%s-%s-customer', $bookingId, $booking['start']), $bookingId);
        $checkOutUrl = Functions::bookingReplyUrl('checkout', sprintf('%s-%s-customer', $bookingId, $booking['start']), $bookingId);
        $cancelUrl = Functions::bookingReplyUrl('cancel', sprintf('%s-%s-customer', $bookingId, $booking['start']), $bookingId);

        $data = [];
        // Is locale not english?
        $data['is_locale_not_english'] = !$this->isLocaleEnglish ? true : false;

        $data['header_image'] = has_header_image() ? get_header_image() : false;

        $data['subject'] = $subject;
        $data['subject_en'] = $subjectEnglish;
        $data['text'] = $this->placeholderParser($text, $booking);
        $data['text_en'] = $this->placeholderParser($textEnglish, $booking);
        $data['date'] = $booking['date'];
        $data['date_en'] = $booking['date_en'];
        $data['time'] = $booking['time'];
        $data['time_en'] = $booking['time_en'];
        $data['room_name'] = $booking['room_name'];
        $data['seat_name'] = ($bookingMode != 'consultation') ? $booking['seat_name'] : '';
        // Checked in
        $data['checked_in'] = ($status == 'checked-in');
        // Check in booking
        $data['checkin_url'] = $checkInUrl;
        $data['checkin_btn'] = __('Check In', 'rrze-rsvp');
        $data['checkin_btn_en'] = 'Check In';
        $data['checkin_text'] = __('Please check-in your booking on site.', 'rrze-rsvp');
        $data['checkin_text_en'] = 'Please check-in your booking on site.';
        $data['alt_checkin_text'] = __('Please check-in your booking on site.', 'rrze-rsvp');
        $data['alt_checkin_text_en'] = 'Please check-in your booking on site.';
        // Check out booking
        $data['checkout_url'] = $checkOutUrl;
        $data['checkout_btn'] = __('Check Out', 'rrze-rsvp');
        $data['checkout_btn_en'] = 'Check Out';
        $data['checkout_text'] = __('Please check out when you leave the site.', 'rrze-rsvp');
        $data['checkout_text_en'] = 'Please check out when you leave the site.';
        $data['alt_checkout_text'] = __('Please check out when you leave the site.', 'rrze-rsvp');
        $data['alt_checkout_text_en'] = 'Please check out when you leave the site.';
        // Cancel booking
        $data['cancel_url'] = $cancelUrl;
        $data['cancel_btn'] = _x('Cancel', 'Booking', 'rrze-rsvp');
        $data['cancel_btn_en'] = 'Cancel';
        $data['cancel_text'] = __('Please cancel your booking in time if your plans change.', 'rrze-rsvp');
        $data['cancel_text_en'] = 'Please cancel your booking in time if your plans change.';
        $data['alt_cancel_text'] = __('Please cancel your booking in time if your plans change.', 'rrze-rsvp');
        $data['alt_cancel_text_en'] = 'Please cancel your booking in time if your plans change.';
        // Site URL
        $data['site_url'] = site_url();
        $data['site_name'] = get_bloginfo('name') ? get_bloginfo('name') : parse_url(site_url(), PHP_URL_HOST);

        if ($bookingMode == 'consultation') {
            $message = $this->template->getContent('email/booking-consultation-confirmed-customer', $data);
            $altMessage = $this->template->getContent('email/booking-consultation-confirmed-customer.txt', $data);
        } else {
            $message = $this->template->getContent('email/booking-confirmed-customer', $data);
            $altMessage = $this->template->getContent('email/booking-confirmed-customer.txt', $data);
        }

        $attachment = '';
        if ($status == 'confirmed') {
            $icsFilename = sprintf('%s-%s.ics', sanitize_title($booking['room_name']), date('YmdHi', $booking['start']));
            $icsContent = ICS::generate($bookingId, $icsFilename);
            $attachment = $this->tempFile($icsFilename, $icsContent);
        }

        $this->send($booking['guest_email'], $subject, $message, $altMessage, $attachment);

        $sendToEmail = get_post_meta($booking['room'], 'rrze-rsvp-room-send-to-email', true);
        if (is_email($sendToEmail) && ($status == 'confirmed') && in_array($bookingMode, ['reservation', 'consultation'])) {
            $subject = __('A booking has been confirmed', 'rrze-rsvp');
            $text = __('The booking has been confirmed by the customer.', 'rrze-rsvp');
            $customerName = sprintf('%s: %s %s', __('Name', 'rrze-rsvp'), $booking['guest_firstname'], $booking['guest_lastname']);
            $customerEmail = sprintf('%s: %s', __('Email', 'rrze-rsvp'), $booking['guest_email']);

            $data['subject'] = $subject;
            $data['text'] = $text;
            $data['customer']['name'] = $customerName;
            $data['customer']['email'] = $customerEmail;

            $message = $this->template->getContent('email/booking-confirmed-send-to-email', $data);
            $altMessage = $this->template->getContent('email/booking-confirmed-send-to-email.txt', $data);
            
            $icsFilename = sprintf('%s-%s-copy.ics', sanitize_title($booking['room_name']), date('YmdHi', $booking['start']));
            $icsContent = ICS::generate($bookingId, $icsFilename, 'send-to-email');
            $attachment = $this->tempFile($icsFilename, $icsContent);            
            
            $this->send($sendToEmail, $subject, $message, $altMessage, $attachment);
        }
    }

    /**
     * bookingCancelledCustomer
     * Send a booking cancellation email to the customer. No further action 
     * is necessary.
     * @param integer $bookingId Booking Id
     * @param string $bookingMode Booking mode: 'check-only', 'reservation' or 'consultation'
     * @return void
     */
    public function bookingCancelledCustomer(int $bookingId, string $bookingMode = 'reservation', string $cancelreason = '')
    {
        $booking = Functions::getBooking($bookingId);
        if (empty($booking)) {
            return;
        }

        $subject = $this->options->email_cancel_subject;
        $subject = $this->placeholderParser($subject, $booking);
        $subjectEnglish = $this->options->email_cancel_subject_en;
        $subjectEnglish = $this->placeholderParser($subjectEnglish, $booking);

        $reason = '';
        $reasonEnglish = '';
        
        if ($cancelreason == 'notconfirmed'){
            $reason = $this->options->email_cancel_reason_notconfirmed;
            $reasonEnglish = $this->options->email_cancel_reason_notconfirmed_en;
        }elseif ($cancelreason == 'notcheckedin'){
            $reason = $this->options->email_cancel_reason_notcheckedin;
            $reasonEnglish = $this->options->email_cancel_reason_notcheckedin_en;
        }

        $text = $this->options->email_cancel_text;
        $textEnglish = $this->options->email_cancel_text_en;

        $data = [];
        // Is locale not english?
        $data['is_locale_not_english'] = !$this->isLocaleEnglish ? true : false;

        $data['header_image'] = has_header_image() ? get_header_image() : false;

        $data['subject'] = $subject;
        $data['subject_en'] = $subjectEnglish;
        $data['reason'] = $reason;
        $data['reason_en'] = $reasonEnglish;
        $data['text'] = $this->placeholderParser($text, $booking);
        $data['text_en'] = $this->placeholderParser($textEnglish, $booking);
        $data['date'] = $booking['date'];
        $data['date_en'] = $booking['date_en'];
        $data['time'] = $booking['time'];
        $data['time_en'] = $booking['time_en'];
        $data['room_name'] = $booking['room_name'];
        $data['seat_name'] = ($bookingMode != 'consultation') ? $booking['seat_name'] : '';
        // Site URL
        $data['site_url'] = site_url();
        $data['site_name'] = get_bloginfo('name') ? get_bloginfo('name') : parse_url(site_url(), PHP_URL_HOST);

        $message = $this->template->getContent('email/booking-cancelled-customer', $data);
        $altMessage = $this->template->getContent('email/booking-cancelled-customer.txt', $data);

        $this->send($booking['guest_email'], $subject, $message, $altMessage);
    }

    /**
     * placeholderParser
     * YA Email Template Parser.
     * @param string $text
     * @param array $booking Booking data
     * @return string
     */
    protected function placeholderParser(string $text, array $booking): string
    {
        $data = [
            'date' => $booking['date'],
            'time' => $booking['time'],
            'date_en' => $booking['date_en'],
            'time_en' => $booking['time_en'],
            'room_name' => $booking['room_name'],
            'seat_name' => $booking['seat_name'],
            'guest_name' => $booking['guest_firstname'] . ' ' . $booking['guest_lastname'],
            'guest_email' => $booking['guest_email']
        ];

        foreach ($data as $key => $field) {
            $text = str_replace('{{' . $key . '}}', $field, $text);
        }
        $text = preg_replace('%\{\{.+\}\}%', '', $text);
        return $text;
    }

    /**
     * tempFile
     * Create temporary file in system temporary directory.
     * @param string $name File name
     * @param string $content File content
     * @return string File path
     */
    protected function tempFile(string $name, string $content)
    {
        $file = DIRECTORY_SEPARATOR
            . trim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . ltrim($name, DIRECTORY_SEPARATOR);
        file_put_contents($file, $content);
        register_shutdown_function(function () use ($file) {
            @unlink($file);
        });
        return $file;
    }
}
