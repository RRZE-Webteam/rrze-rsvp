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
     * __construct
     */
    public function __construct()
    {
        $settings = new Settings(plugin()->getFile());
        $this->options = (object) $settings->getOptions();
        $this->template = new Template;
    }

    /**
     * send
     * Send an email.
     * @param string $to
     * @param string $subject
     * @param string $message
     * @return void
     */
    public function send(string $to, string $subject, string $message)
    {
        $data = [
            'subject' => $subject,
            'message' => $message
        ];
        $body = $this->template->getContent('email/email-body', $data);

        $headers = [
            'Content-type: text/html; charset=utf-8'
        ];

        add_filter('wp_mail_from', [$this, 'filterEmail']);
        add_filter('wp_mail_from_name', [$this, 'filterName']);

        wp_mail($to, $subject, $body, $headers);

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
     * @return void
     */
    public function bookingRequestedAdmin(string $to, string $subject, int $bookingId)
    {
        $booking = Functions::getBooking($bookingId);
        if (empty($booking)) {
            return;
        }

        $customerName = sprintf('%s: %s %s', __('Name', 'rrze-rsvp'), $booking['guest_firstname'], $booking['guest_lastname']);
        $customerEmail = sprintf('%s: %s', __('Email', 'rrze-rsvp'), $booking['guest_email']);
        $customerPhone = sprintf('%s: %s', __('Phone', 'rrze-rsvp'), $booking['guest_phone']);
        $confirmUrl = Functions::bookingReplyUrl('confirm', sprintf('%s-%s', $bookingId, $booking['start']), $bookingId);
        $cancelUrl = Functions::bookingReplyUrl('cancel', sprintf('%s%-s', $bookingId, $booking['start']), $bookingId);

        $data = [];
        $data['text'] = sprintf(__('You received a new request for a booking on %s.', 'rrze-rsvp'), get_bloginfo('name'));
        $data['date'] = $booking['date'];
        $data['time'] = $booking['time'];
        $data['room_name'] = $booking['room_name'];
        $data['seat_name'] = $booking['seat_name'];
        $data['customer']['name'] = $customerName;
        $data['customer']['email'] = $customerEmail;
        $data['customer']['phone'] = $customerPhone;
        $data['confirm_url'] = $confirmUrl;
        $data['confirm_link_text'] = __('Confirm Booking', 'rrze-rsvp');
        $data['cancel_url'] = $cancelUrl;
        $data['cancel_link_text'] = __('Cancel Booking', 'rrze-rsvp');

        $message = $this->template->getContent('email/booking-requested-admin', $data);

        $this->send($to, $subject, $message);
    }

    /**
     * bookingCancelledAdmin
     * Send an email to the admin when the customer cancels the booking.
     * @param integer $bookingId Booking Id
     * @return void
     */
    public function bookingCancelledAdmin(int $bookingId)
    {
        $booking = Functions::getBooking($bookingId);
        if (empty($booking)) {
            return;
        }

        $to = $this->options->email_notification_email;
        $subject = __('Booking has been cancelled', 'rrze-rsvp');

        $text = sprintf(__('A booking on %s has been cancelled by the customer.', 'rrze-rsvp'), get_bloginfo('name'));

        $data = [];
        $data['text'] = $this->placeholderParser($text, $booking);
        $data['date'] = $booking['date'];
        $data['time'] = $booking['time'];
        $data['room_name'] = $booking['room_name'];
        $data['seat_name'] = $booking['seat_name'];
        $data['booking_info'] = Functions::dataToStr($booking);

        $message = $this->template->getContent('email/booking-cancelled-admin', $data);

        $this->send($to, $subject, $message);
    }

    /**
     * bookingRequestedCustomer
     * Send an email to the customer if a new booking is requested. The booking 
     * is not yet confirmed. An admin action is required for confirmation or 
     * cancellation of the booking. Optionally, the customer can cancel the 
     * reservation using the link included in the email message.
     * @param integer $bookingId
     * @return void
     */
    public function bookingRequestedCustomer(int $bookingId)
    {
        $booking = Functions::getBooking($bookingId);
        if (empty($booking)) {
            return;
        }

        $subject = $this->options->email_received_subject;
        $subject = $this->placeholderParser($subject, $booking);

        $text = $this->options->email_received_text;
        $cancelUrl = Functions::bookingReplyUrl('cancel', sprintf('%s-%s-customer', $bookingId, $booking['start']), $bookingId);

        $data = [];
        $data['text'] = $this->placeholderParser($text, $booking);
        $data['date'] = $booking['date'];
        $data['time'] = $booking['time'];
        $data['room_name'] = $booking['room_name'];
        $data['seat_name'] = $booking['seat_name'];
        $data['cancel_booking'] = sprintf(__('Please <a href="%s">cancel your booking</a> in time if your plans change.', 'rzze-rsvp'), $cancelUrl);
        $data['site_url'] = site_url();
        $data['site_url_text'] = get_bloginfo('name');

        $message = $this->template->getContent('email/booking-requested-customer', $data);

        $this->send($booking['guest_email'], $subject, $message);
    }

    /**
     * bookingConfirmedCustomer
     * Send a booking confirmation email to the customer. Optionally, the
     * customer can cancel the booking using the link included in the email message.
     * @param integer $bookingId Booking Id
     * @return void
     */
    public function bookingConfirmedCustomer(int $bookingId)
    {
        $booking = Functions::getBooking($bookingId);
        if (empty($booking)) {
            return;
        }

        $subject = $this->options->email_confirm_subject;
        $subject = $this->placeholderParser($subject, $booking);

        $text = $this->options->email_confirm_text;
        $icsUrl = Functions::bookingReplyUrl('ics', sprintf('%s-%s-customer', $bookingId, $booking['start']), $bookingId);
        $checkInUrl = Functions::bookingReplyUrl('checkin', sprintf('%s-%s-customer', $bookingId, $booking['start']), $bookingId);        
        $cancelUrl = Functions::bookingReplyUrl('cancel', sprintf('%s-%s-customer', $bookingId, $booking['start']), $bookingId);

        $data = [];
        $data['text'] = $this->placeholderParser($text, $booking);
        $data['date'] = $booking['date'];
        $data['time'] = $booking['time'];
        $data['room_name'] = $booking['room_name'];
        $data['seat_name'] = $booking['seat_name'];
        $data['ics_download'] = sprintf(__('<a href="%s">Add the booking to your calendar</a>.', 'rzze-rsvp'), $icsUrl);
        $data['checkin_booking'] = sprintf(__('Please <a href="%s">check-in your booking</a> on site.', 'rzze-rsvp'), $checkInUrl);
        $data['cancel_booking'] = sprintf(__('Please <a href="%s">cancel your booking</a> in time if your plans change.', 'rzze-rsvp'), $cancelUrl);
        $data['site_url'] = site_url();
        $data['site_url_text'] = get_bloginfo('name');

        $message = $this->template->getContent('email/booking-confirmed-customer', $data);

        $this->send($booking['guest_email'], $subject, $message);
    }

    /**
     * bookingCancelledCustomer
     * Send a booking cancellation email to the client. No further action 
     * is necessary.
     * @param integer $bookingId Booking Id
     * @return void
     */
    public function bookingCancelledCustomer(int $bookingId)
    {
        $booking = Functions::getBooking($bookingId);
        if (empty($booking)) {
            return;
        }

        $subject = $this->options->email_cancel_subject;
        $subject = $this->placeholderParser($subject, $booking);

        $text = $this->options->email_cancel_text;

        $data = [];
        $data['text'] = $this->placeholderParser($text, $booking);
        $data['date'] = $booking['date'];
        $data['time'] = $booking['time'];
        $data['room_name'] = $booking['room_name'];
        $data['seat_name'] = $booking['seat_name'];
        $data['site_url'] = site_url();
        $data['site_url_text'] = get_bloginfo('name');

        $message = $this->template->getContent('email/booking-cancelled-customer', $data);

        $this->send($booking['guest_email'], $subject, $message);
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
            'room_name' => $booking['room_name'],
            'seat_name' => $booking['seat_name'],
            'guest_name' => $booking['guest_firstname'] . ' ' . $booking['guest_lastname'],
            'guest_email' => $booking['guest_email'],
            'guest_phone' => $booking['guest_phone']
        ];

        foreach ($data as $key => $field) {
            $text = str_replace('{{' . $key . '}}', $field, $text);
        }
        $text = preg_replace('%\{\{.+\}\}%', '', $text);
        return $text;
    }
}
