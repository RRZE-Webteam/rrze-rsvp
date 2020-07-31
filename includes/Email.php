<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

class Email
{
    protected $options;

    protected $template;

    public function __construct() {
        $settings = new Settings(plugin()->getFile());
        $this->options = (object) $settings->getOptions();
        $this->template = new Template;
    }

    public function send(string $to, string $subject, string $message)
    {
        $data = [
            'subject' => $subject,
            'message' => $message
        ];
        $body = $this->template->getContent('email-body', $data);

        $headers = [
            'Content-type: text/html; charset=utf-8'
        ];

        add_filter('wp_mail_from', [$this, 'filterEmail']);
        add_filter('wp_mail_from_name', [$this, 'filterName']);

        wp_mail($to, $subject, $body, $headers);

        remove_filter('wp_mail_from_name', [$this, 'filterName']);
        remove_filter('wp_mail_from', [$this, 'filterEmail']);
    }

    public function filterEmail($from)
    {
        $newFrom = $this->options->email_sender_email;
        return ($newFrom != '') ? $newFrom : $from;
    }

    public function filterName($name)
    {
        $newName = $this->options->email_sender_name;
        return ($newName != '') ? $newName : $name;
    }

    public function bookingNew(string $to, string $subject, int $bookingId)
    {
        $booking = Functions::getBooking($bookingId);
        if (empty($booking)) {
            return;
        }

        $booking['text'] = sprintf(__('You received a new request for a booking on %s.', 'rrze-rsvp'), get_bloginfo('name'));

        $booking['user_info'] = '';

        $booking['confirm_url'] = '';
        $booking['confirm_link_text'] = __('Confirm Booking', 'rrze-rsvp');
        $booking['cancel_url'] = '';
        $booking['cancel_link_text'] = __('Cancel Booking', 'rrze-rsvp');

        $message = $this->template->getContent('email-new', $booking);

        $this->send($to, $subject, $message);
    }

    public function bookingCancelNotification(int $bookingId)
    {
        $booking = Functions::getBooking($bookingId);
        if (empty($booking)) {
            return;
        }

        $to = $this->options->email_notification_email;

        $booking['booking_info'] = Functions::dataToStr($booking);

        $subject = __('Booking has been cancelled', 'rrze-rsvp');
        $text = sprintf(__('A booking on %s has been cancelled by the customer.', 'rrze-rsvp'), get_bloginfo('name'));

        $optionsData = [
            'subject' => $subject,
            'text' => $text
        ];

        $subject = $this->placeholderParser($subject, $optionsData);
        $booking['text'] = $this->placeholderParser($text, $optionsData);

        $message = $this->template->getContent('email-cancel-notification', $booking);

        $this->send($to, $subject, $message);
    }

    public function bookingReceived(int $bookingId)
    {
        $booking = Functions::getBooking($bookingId);
        if (empty($booking)) {
            return;
        }

        $subject = $this->options->email_received_subject;
        $text = $this->options->email_received_text;

        $optionsData = [
            'subject' => $subject,
            'text' => $text
        ];

        $subject = $this->placeholderParser($subject, $optionsData);
        $booking['text'] = $this->placeholderParser($text, $optionsData);

        $cancelUrl = '';
        $booking['cancel_booking'] = sprintf(__('Please <a href="%s">cancel your booking</a> in time if your plans change.', 'rzze-rsvp'), $cancelUrl) . '</p>';

        $message = $this->template->getContent('email-received', $booking);

        $this->send($booking['guest_email'], $subject, $message);
    }

    public function bookingConfirmed(int $bookingId)
    {
        $booking = Functions::getBooking($bookingId);
        if (empty($booking)) {
            return;
        }

        $subject = $this->options->email_confirm_subject;
        $text = $this->options->email_confirm_text;

        $optionsData = [
            'subject' => $subject,
            'text' => $text
        ];

        $subject = $this->placeholderParser($subject, $optionsData);
        $booking['text'] = $this->placeholderParser($text, $optionsData);

        $cancelUrl = '';
        $booking['cancel_booking'] = sprintf(__('Please <a href="%s">cancel your booking</a> in time if your plans change.', 'rzze-rsvp'), $cancelUrl) . '</p>';

        $message = $this->template->getContent('email-cancel', $booking);

        $this->send($booking['guest_email'], $subject, $message);
    }

    public function bookingCancelled(int $bookingId)
    {
        $booking = Functions::getBooking($bookingId);
        if (empty($booking)) {
            return;
        }

        $subject = $this->options->email_cancel_subject;
        $text = $this->options->email_cancel_text;

        $optionsData = [
            'subject' => $subject,
            'text' => $text
        ];

        $subject = $this->placeholderParser($subject, $optionsData);
        $booking['text'] = $this->placeholderParser($text, $optionsData);

        $message = $this->template->getContent('email-cancel', $booking);

        $this->send($booking['guest_email'], $subject, $message);
    }

    protected function placeholderParser(string $text, array $data): string
    {
        foreach ($data as $key => $field) {
            $text = str_replace('{{' . $key . '}}', $field, $text);
        }
        $text = preg_replace('%\{\{.+\}\}%', '', $text);
        return $text;
    }

}
