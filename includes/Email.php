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
        $this->settings = new Settings(plugin()->getFile());
        $this->options = (object) $this->settings->getOptions();
        $this->template = new Template;
        $this->isLocaleEnglish = Functions::isLocaleEnglish();
    }

    /**
     * doEmail
     * Prepare email data and send email depending on the email context and recipient.
     * @param string $mailContext Which Mail should be sent: 'customerConfirmationRequired', 'customerConfirmed', 'adminConfirmationRequired', 'adminConfirmed', 'newBooking', 'bookingCancelled', 'bookingCheckedIn' or 'bookingCheckedOut'
     * @param string $recipient Who will get the email: 'admin' or 'customer'
     * @param integer $bookingId Booking Id
     * @param string $status [optional] Booking status: 'booked', 'customer-confirmed', 'confirmed', 'cancelled', 'checked-in' or 'checked-out'
     * @param string $cancelReason [optional] Reason for cancelling in customer mails: 'notconfirmed' or 'notcheckedin'
     * @return void
     */
    public function doEmail(string $mailContext = '', string $recipient = 'admin', int $bookingId, string $status = '', string $cancelReason = '') {
        $booking = Functions::getBooking($bookingId);
        if (empty($booking) || $mailContext == '') {
            return;
        }

        $roomMeta = get_post_meta($booking['room']);
        $sendToEmail = isset($roomMeta['rrze-rsvp-room-send-to-email']) ? $roomMeta['rrze-rsvp-room-send-to-email'][0] : '';
        $to = $recipient == 'admin' ? $this->options->email_notification_email : $booking['guest_email'];
        $bookingMode = isset($roomMeta['rrze-rsvp-room-bookingmode']) ? $roomMeta['rrze-rsvp-room-bookingmode'][0] : '';
        $autoConfirmation = isset($roomMeta['rrze-rsvp-room-auto-confirmation']) ? Functions::getBoolValueFromAtt($roomMeta['rrze-rsvp-room-auto-confirmation'][0]) : false;
        $adminConfirmationRequired = $autoConfirmation ? false : true; // Verwirrende Post-Meta-Bezeichnung vereinfacht
        $showConfirmationButton = false;
        $showCheckinButton = false;
        $showCheckoutButton = false;
        $showCancelButton = false;
        if ($cancelReason == '') {
            $cancelReason = false;
            $cancelReason_en = false;
        }

        switch ($mailContext) {
            case 'customerConfirmationRequired':
                $subject = $this->options->email_force_to_confirm_subject;
                $subject_en = $this->options->email_force_to_confirm_subject_en;
                $text = $this->options->email_force_to_confirm_text;
                $text_en = $this->options->email_force_to_confirm_text_en;
                $showConfirmationButton = true;
                $showCancelButton = true;
                break;
            case 'customerConfirmed':
                if ($adminConfirmationRequired) {
                    if ($recipient == 'admin') {
                        $subject = _x('[RSVP] Confirm new booking', 'Mail Subject for room admin: new booking received', 'rrze-rsvp');
                        $subject_en = '[RSVP] Confirm new booking';
                        $text = __('You received a new request for a booking.', 'rrze-rsvp');
                        $text_en = 'You received a new request for a booking.';
                        $showConfirmationButton = true;
                        $showCancelButton = true;
                    } elseif ($recipient == 'customer') {
                        $subject = $this->options->email_received_subject;;
                        $subject_en = $this->options->email_received_subject_en;
                        $text = $this->options->email_received_text;
                        $text_en = $this->options->email_received_text_en;
                        $showCancelButton = true;
                    }
                } else {
                    $subject = $this->options->email_confirm_subject;
                    $subject_en = $this->options->email_confirm_subject_en;
                    $text = $this->options->email_confirm_text;
                    $text_en = $this->options->email_confirm_text_en;
                    $showCheckinButton = true;
                    $showCheckoutButton = true;
                    $showCancelButton = true;
                    $status = 'confirmed';
                }
                break;
            case 'adminConfirmationRequired':
                if ($recipient == 'admin') {
                    $subject = _x('[RSVP] Confirm new booking', 'Mail Subject for room admin: new booking received', 'rrze-rsvp');
                    $subject_en = '[RSVP] Confirm new booking';
                    $text = __('You received a new request for a booking.', 'rrze-rsvp');
                    $text_en = 'You received a new request for a booking.';
                    $showConfirmationButton = true;
                    $showCancelButton = true;
                } elseif ($recipient == 'customer') {
                    $subject = $this->options->email_received_subject;;
                    $subject_en = $this->options->email_received_subject_en;
                    $text = $this->options->email_received_text;
                    $text_en = $this->options->email_received_text_en;
                    $showCancelButton = true;
                }
                break;
            case 'adminConfirmed':
                $subject = $this->options->email_confirm_subject;
                $subject_en = $this->options->email_confirm_subject_en;
                $text = $this->options->email_confirm_text;
                $text_en = $this->options->email_confirm_text_en;
                $showCheckinButton = true;
                $showCheckoutButton = true;
                $showCancelButton = true;
                $status = 'confirmed';
                break;
            case 'newBooking':
                $subject = _x('[RSVP] New booking', 'Mail Subject for room admin: new booking received', 'rrze-rsvp');
                $subject_en = '[RSVP] New booking';
                $text = __('You received a new booking.', 'rrze-rsvp');
                $text_en = 'You received a new booking.';
                break;
            case 'bookingCancelled':
                if ($recipient == 'admin') {
                    $subject = __('[RSVP] Booking cancelled', 'rrze-rsvp');
                    $subject_en = '[RSVP] Booking cancelled';
                    $text = __('A booking has been cancelled by the customer.', 'rrze-rsvp');
                    $text_en = 'A booking has been cancelled by the customer.';
                } elseif ($recipient == 'customer') {
                    $subject = $this->options->email_cancel_subject;
                    $subject_en = $this->options->email_cancel_subject_en;
                    $text = $this->options->email_cancel_text;
                    $text_en = $this->options->email_cancel_text_en;
                    if ($cancelReason == 'notconfirmed'){
                        $cancelReason = $this->options->email_cancel_reason_notconfirmed;
                        $cancelReason_en = $this->options->email_cancel_reason_notconfirmed_en;
                    } elseif ($cancelReason == 'notcheckedin'){
                        $cancelReason = $this->options->email_cancel_reason_notcheckedin;
                        $cancelReason_en = $this->options->email_cancel_reason_notcheckedin_en;
                    }
                }
                break;
            case 'bookingCheckedIn':
                $subject = $this->options->email_confirm_subject;
                $subject_en = $this->options->email_confirm_subject_en;
                $text = $this->options->email_confirm_text;
                $text_en = $this->options->email_confirm_text_en;
                $showCheckinButton = false;
                $showCheckoutButton = true;
                $showCancelButton = false;
                $status = 'checked-in';
                break;
            case 'bookingCheckedOut':
                $subject = __('Seat checked out', 'rrze-rsvp');
                $subject_en = 'Seat checked out';
                $text = __('A customer has checked out from a seat:', 'rrze-rsvp');
                $text_en = 'A customer has checked out from a seat:';
                break;
            default:
                break;
        }
        $subject = $this->placeholderParser($subject, $booking);
        $subject_en = $this->placeholderParser($subject_en, $booking);
        $text = $this->placeholderParser($text, $booking);
        $text_en = $this->placeholderParser($text_en, $booking);

        $data = [];

        // Misc mail infos
        $data['is_locale_not_english'] = !$this->isLocaleEnglish ? true : false;
        $data['to_admin'] = $recipient == 'admin' ? true : false;
        $data['instant_checkin'] = false;
        $data['checked_in'] = ($status == 'checked-in');

        // Website data
        $data['header_image'] = has_header_image() ? get_header_image() : false;
        $data['site_url'] = site_url();
        $data['site_name'] = get_bloginfo('name') ? get_bloginfo('name') : parse_url(site_url(), PHP_URL_HOST);

        // Booking data
        $data['subject'] = $subject;
        $data['subject_en'] = $subject_en;
        $data['text'] = $this->placeholderParser($text, $booking);
        $data['text_en'] = $this->placeholderParser($text_en, $booking);
        $data['reason'] = $cancelReason;
        $data['reason_en'] = $cancelReason_en;
        $data['date'] = $booking['date'];
        $data['date_en'] = $booking['date_en'];
        $data['time'] = $booking['time'];
        $data['time_en'] = $booking['time_en'];
        $data['room_name'] = $booking['room_name'];
        $data['seat_name'] = ($bookingMode != 'consultation') ? $booking['seat_name'] : '';
        $data['customer']['name'] = sprintf('%s: %s %s', __('Name', 'rrze-rsvp'), $booking['guest_firstname'], $booking['guest_lastname']);
        $data['customer']['email'] = sprintf('%s: %s', __('Email', 'rrze-rsvp'), $booking['guest_email']);

        // Confirmation Button
        if ($showConfirmationButton) {
            $data['show_confirm_button'] = true;
            if ($recipient == 'admin') {
                $data['confirm_url'] = Functions::bookingReplyUrl('confirm', sprintf('%s-%s', $bookingId, $booking['start']), $bookingId);
                $data['confirm_text'] = __("Please confirm the customer's booking.", 'rrze-rsvp');
                $data['confirm_text_en'] = "Please confirm the customer's booking.";
                $data['alt_confirm_text'] = __("Please confirm the customer's booking.", 'rrze-rsvp');
                $data['alt_confirm_text_en'] = "Please confirm the customer's booking.";
            } else {
                $data['confirm_url'] = Functions::bookingReplyUrl('confirm', sprintf('%s-%s-customer', $bookingId, $booking['start']), $bookingId);
                $data['confirm_text'] = __('Please confirm your booking now.', 'rrze-rsvp');
                $data['confirm_text_en'] = 'Please confirm your booking now.';
                $data['alt_confirm_text'] = __('Please confirm your booking now.', 'rrze-rsvp');
                $data['alt_confirm_text_en'] = 'Please confirm your booking now.';
            }
            $data['confirm_btn'] = _x('Confirm', 'Booking', 'rrze-rsvp');
            $data['confirm_btn_en'] = 'Confirm';
        } else {
            $data['show_confirm_button'] = false;
        }

        // Cancel Button
        if ($showCancelButton) {
            $data['show_cancel_btn'] = true;
            if ($recipient == 'admin') {
                $data['cancel_url'] = Functions::bookingReplyUrl('cancel', sprintf('%s-%s', $bookingId, $booking['start']), $bookingId);
                $data['cancel_text'] = '';
                $data['cancel_text_en'] = '';
                $data['alt_cancel_text'] = '';
                $data['alt_cancel_text_en'] = '';

            } else {
                $data['cancel_url'] = Functions::bookingReplyUrl('cancel', sprintf('%s-%s-customer', $bookingId, $booking['start']), $bookingId);
                $data['cancel_text'] = __('Please cancel your booking in time if your plans change.', 'rrze-rsvp');
                $data['cancel_text_en'] = 'Please cancel your booking in time if your plans change.';
                $data['alt_cancel_text'] = __('Please cancel your booking in time if your plans change.', 'rrze-rsvp');
                $data['alt_cancel_text_en'] = 'Please cancel your booking in time if your plans change.';
            }
            $data['cancel_btn'] = _x('Cancel', 'Booking', 'rrze-rsvp');
            $data['cancel_btn_en'] = 'Cancel';

        } else {
            $data['show_cancel_btn'] = false;
        }

        // CheckIn Button
        if ($showCheckinButton) {
            $data['show_checkin_btn'] = true;
            $data['checkin_url'] = Functions::bookingReplyUrl('checkin', sprintf('%s-%s-customer', $bookingId, $booking['start']), $bookingId);
            $data['checkin_btn'] = __('Check In', 'rrze-rsvp');
            $data['checkin_btn_en'] = 'Check In';
            $forceCheckin = isset($roomMeta['rrze-rsvp-room-force-to-checkin']) ? $roomMeta['rrze-rsvp-room-force-to-checkin'][0] : '';

            if ($forceCheckin) {
                $checkInTime = isset($roomMeta['rrze-rsvp-room-check-in-time']) ? $roomMeta['rrze-rsvp-room-check-in-time'][0] : '';
                if ($checkInTime == '') {
                    $defaultCheckInTime = $this->settings->getDefault('general', 'check-in-time');
                    $settingsCheckInTime = $this->settings->getOption('general', 'check-in-time', $defaultCheckInTime, true);
                    $checkInTime = $settingsCheckInTime;
                }
                $bookingTimeStamp = $booking['booking_date_timestamp'];
                $bookingStart = $booking['start'];
                if ($bookingStart < $bookingTimeStamp) {
                    //Seat booked after beginning of timeslot
                    $timeStampCheckIn = $bookingTimeStamp + ($checkInTime * MINUTE_IN_SECONDS);
                } else {
                    $timeStampCheckIn = $bookingStart + ($checkInTime * MINUTE_IN_SECONDS);
                }
                $checkInLimit = Functions::timeFormat($timeStampCheckIn);
                $checkInLimit_en = date('g:i a', $timeStampCheckIn);
                $data['checkin_text'] = sprintf(__('Please check-in your booking on site until %s.', 'rrze-rsvp'), $checkInLimit);
                $data['checkin_text_en'] = sprintf('Please check-in your booking on site until %s.', $checkInLimit_en);
                $data['alt_checkin_text'] = sprintf(__('Please check-in your booking on site until %s.', 'rrze-rsvp'), $checkInLimit);
                $data['alt_checkin_text_en'] = sprintf('Please check-in your booking on site until %s.', $checkInLimit_en);
            } else {
                $data['checkin_text'] = __('Please check-in your booking on site.', 'rrze-rsvp');
                $data['checkin_text_en'] = 'Please check-in your booking on site.';
                $data['alt_checkin_text'] = __('Please check-in your booking on site.', 'rrze-rsvp');
                $data['alt_checkin_text_en'] = 'Please check-in your booking on site.';
            }
        } else {
            $data['show_checkin_btn'] = false;
        }

        // CheckOut Button
        if ($showCheckoutButton) {
            $data['show_checkout_btn'] = true;
            $data['checkout_url'] = Functions::bookingReplyUrl('checkout', sprintf('%s-%s-customer', $bookingId, $booking['start']), $bookingId);
            $data['checkout_btn'] = __('Check Out', 'rrze-rsvp');
            $data['checkout_btn_en'] = 'Check Out';
            $data['checkout_text'] = __('Please check out when you leave the site.', 'rrze-rsvp');
            $data['checkout_text_en'] = 'Please check out when you leave the site.';
            $data['alt_checkout_text'] = __('Please check out when you leave the site.', 'rrze-rsvp');
            $data['alt_checkout_text_en'] = 'Please check out when you leave the site.';
        } else {
            $data['show_checkout_btn'] = false;
        }

        if ($showCheckinButton || $showCheckoutButton) {
            $data['show_check_btns'] = true;
        } else {
            $data['show_check_btns'] = false;
        }

        // ICS attachment
        $attachment = '';
        if ($status == 'confirmed' || $mailContext == 'newBooking') {
            $icsFilename = sprintf('%s-%s.ics', sanitize_title($booking['room_name']), date('YmdHi', $booking['start']));
            $icsContent = ICS::generate($bookingId, $icsFilename);
            $attachment = $this->tempFile($icsFilename, $icsContent);
        }

        $message = $this->template->getContent('email/email', $data);
        $altMessage = $this->template->getContent('email/email.txt', $data);

        $this->send($to, $subject, $message, $altMessage, $attachment);

        // Send ICS to separate address if requested
        if (is_email($sendToEmail) && ($status == 'confirmed') && in_array($bookingMode, ['reservation', 'consultation', 'no-check'])) {
            $subject = __('New confirmed booking', 'rrze-rsvp');
            $text = __('There is a new confirmed booking for your room. Calendar file (.ics) attached.', 'rrze-rsvp');
            $customerName = sprintf('%s: %s %s', __('Name', 'rrze-rsvp'), $booking['guest_firstname'], $booking['guest_lastname']);
            $customerEmail = sprintf('%s: %s', __('Email', 'rrze-rsvp'), $booking['guest_email']);

            $data['subject'] = $subject;
            $data['text'] = $text;
            $data['customer']['name'] = $customerName;
            $data['customer']['email'] = $customerEmail;
            $data['show_check_btns'] = false;
            $data['show_cancel_btn'] = false;
            $data['show_confirm_button'] = false;

            $message = $this->template->getContent('email/email', $data);
            $altMessage = $this->template->getContent('email/email.txt', $data);

            $icsFilename = sprintf('%s-%s-copy.ics', sanitize_title($booking['room_name']), date('YmdHi', $booking['start']));
            $icsContent = ICS::generate($bookingId, $icsFilename, 'send-to-email');
            $attachment = $this->tempFile($icsFilename, $icsContent);

            $this->send($sendToEmail, $subject, $message, $altMessage, $attachment);
        }
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
        $body = $message;
        $altBody = $altMessage;

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
}