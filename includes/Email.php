<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

class Email
{
    protected $options;

    protected $template;

    public function __construct($pluginFile, $settings) {
	    $this->pluginFile = $pluginFile;
	    $this->settings = $settings;
	    
        $this->options = $settings->options;
        $this->template = new Template();
    }

    public function send(string $to, string $subject, string $message)
    {
        $data = [
            'subject' => $subject,
            'message' => $message
        ];
        $body  =  $this->template->getContent('email-body', $data);

        $headers = array(
            'Content-type: text/html; charset=utf-8'
        );

        add_filter('wp_mail_from', [$this, 'filterEmail']);
        add_filter('wp_mail_from', [$this, 'filterName']);

        wp_mail($to, $subject, $body, $headers);

        remove_filter('wp_mail_from', array($this, 'filterName'));
        remove_filter('wp_mail_from', array($this, 'filterEmail'));
    }

    public function filterEmail($from)
    {
        $newFrom = $this->options->sender_email;
        return ($newFrom != '') ? $newFrom : $from;
    }

    public function filterName($name)
    {
        $newName = $this->options->sender_name;
        return ($newName != '') ? $newName : $name;
    }

    public function bookingNew(string $to, string $subject, int $bookingId)
    {
        $bookingData = Functions::getBooking($bookingId);
        if (empty($bookingData)) {
            return;
        }

        $bookingData['text'] = sprintf(__('You received a new request for a booking on %s.', 'rrze-rsvp'), get_bloginfo('name'));

        $bookingData['user_info'] = '';

        $bookingData['confirm_url'] = '';
        $bookingData['confirm_link_text'] = __('Confirm Booking', 'rrze-rsvp');
        $bookingData['cancel_url'] = '';
        $bookingData['cancel_link_text'] = __('Cancel Booking', 'rrze-rsvp');

        $message = $this->template->getContent('email-new', $bookingData);

        $this->send($to, $subject, $message);
    }

    public function bookingCancelNotification(int $bookingId)
    {
        $bookingData = Functions::getBooking($bookingId);
        if (empty($bookingData)) {
            return;
        }

        $to = $this->options->notification_email;

        $bookingData['booking_info'] = Functions::dataToStr($bookingData);

        $subject = __('Booking has been cancelled', 'rrze-rsvp');
        $text = sprintf(__('A booking on %s has been cancelled by the customer.', 'rrze-rsvp'), get_bloginfo('name'));

        $optionsData = [
            'subject' => $subject,
            'text' => $text
        ];

        $subject = $this->placeholderParser($subject, $optionsData);
        $bookingData['text'] = $this->placeholderParser($text, $optionsData);

        $message = $this->template->getContent('email-cancel-notification', $bookingData);

        $this->send($to, $subject, $message);
    }

    public function bookingReceived(int $bookingId)
    {
        $bookingData = Functions::getBooking($bookingId);
        if (empty($bookingData)) {
            return;
        }

        $subject = $this->options->received_subject;
        $text = $this->options->received_text;

        $optionsData = [
            'subject' => $subject,
            'text' => $text
        ];

        $subject = $this->placeholderParser($subject, $optionsData);
        $bookingData['text'] = $this->placeholderParser($text, $optionsData);

        $cancelUrl = '';
        $bookingData['cancel_booking'] = sprintf(__('Please <a href="%s">cancel your booking</a> in time if your plans change.', 'rzze-rsvp'), $cancelUrl) . '</p>';

        $message = $this->template->getContent('email-received', $bookingData);

        $this->send($bookingData['field_email'], $subject, $message);
    }

    public function bookingConfirmed(int $bookingId)
    {
        $bookingData = Functions::getBooking($bookingId);
        if (empty($bookingData)) {
            return;
        }

        $subject = $this->options->confirm_subject;
        $text = $this->options->confirm_text;

        $optionsData = [
            'subject' => $subject,
            'text' => $text
        ];

        $subject = $this->placeholderParser($subject, $optionsData);
        $bookingData['text'] = $this->placeholderParser($text, $optionsData);

        $cancelUrl = '';
        $bookingData['cancel_booking'] = sprintf(__('Please <a href="%s">cancel your booking</a> in time if your plans change.', 'rzze-rsvp'), $cancelUrl) . '</p>';

        $message = $this->template->getContent('email-canceled', $bookingData);

        $this->send($bookingData['field_email'], $subject, $message);
    }

    public function bookingCanceled(int $bookingId)
    {
        $bookingData = Functions::getBooking($bookingId);
        if (empty($bookingData)) {
            return;
        }

        $subject = $this->options->cancel_subject;
        $text = $this->options->cancel_text;

        $optionsData = [
            'subject' => $subject,
            'text' => $text
        ];

        $subject = $this->placeholderParser($subject, $optionsData);
        $bookingData['text'] = $this->placeholderParser($text, $optionsData);

        $message = $this->template->getContent('email-cancel', $bookingData);

        $this->send($bookingData['field_email'], $subject, $message);
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
