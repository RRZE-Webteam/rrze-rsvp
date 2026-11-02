<?php

/**
 * Generate synthetic complete messages through the real renderers, without booking writes or mail.
 * Run: wp --skip-plugins --skip-themes --url=SITE eval-file tests/mail-previews.php /private/tmp/rsvp-mail-preview
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/rrze-rsvp.php';
require_once __DIR__ . '/fixtures/mail-data.php';

use RRZE\RSVP\Courses\Notifications;
use RRZE\RSVP\Mail\RoomMessage;

/** Capture rendered content instead of ever invoking WordPress mail transport. */
class RsvpMailPreviewTransport extends \RRZE\RSVP\Email
{
    public string $html = '';
    public string $plain = '';
    /** No settings or transport are needed to capture synthetic messages. */
    public function __construct() {}
    /**
     * @param string $to Recipient.
     * @param string $subject Subject.
     * @param string $message HTML.
     * @param string $altMessage Text.
     * @param string $attachment Unused.
     * @return bool Captured.
     */
    public function send(string $to, string $subject, string $message, string $altMessage = '', string $attachment = ''): bool
    {
        $this->html = $message;
        $this->plain = $altMessage;
        return true;
    }
}

$directory = $args[0] ?? '';
if ($directory === '' || !str_starts_with($directory, '/')) {
    throw new RuntimeException('Provide an absolute output directory for synthetic previews.');
}
if (!is_dir($directory) && !wp_mkdir_p($directory)) {
    throw new RuntimeException('Cannot create the preview directory.');
}
// Also suppress any unrelated integration accidentally triggered by a site filter.
add_filter('pre_wp_mail', '__return_true', PHP_INT_MAX);
$transport = new RsvpMailPreviewTransport();
$notifications = new Notifications($transport, []);
$participant = new ReflectionMethod($notifications, 'participantMessage');
$administrator = new ReflectionMethod($notifications, 'administratorMessage');
$deliver = new ReflectionMethod($notifications, 'deliver');
$files = [];
foreach (['de_DE', 'en_US'] as $locale) {
    $switched = switch_to_locale($locale);
    try {
        load_textdomain('rrze-rsvp', dirname(__DIR__) . '/languages/rrze-rsvp-' . $locale . '.mo');
        foreach (['booked', 'customer-confirmed', 'confirmed', 'cancelled', 'checked-in', 'checked-out'] as $status) {
            $data = \RRZE\RSVP\Tests\roomMailData();
            $data['mail_status'] = $status;
            [$data['text_html'], $data['text_en_html']] = match ($status) {
                'booked' => ['Vielen Dank für Ihre Buchung. Bitte bestätigen Sie den Termin.', 'Thank you for your booking. Please confirm your appointment.'],
                'customer-confirmed' => ['Ihre Buchung wartet auf die Freigabe.', 'Your booking is awaiting approval.'],
                'confirmed' => ['Ihre Buchung wurde bestätigt.', 'Your booking has been confirmed.'],
                'cancelled' => ['Ihre Buchung wurde storniert.', 'Your booking has been cancelled.'],
                'checked-in' => ['Sie haben eingecheckt.', 'You have checked in.'],
                'checked-out' => ['Sie haben ausgecheckt.', 'You have checked out.'],
            };
            $data['is_locale_not_english'] = $locale === 'de_DE';
            $data['subject'] = \RRZE\RSVP\Mail\EmailLayout::bookingStatus($status)[1] . ': Sprechzimmer 1';
            $data['subject_en'] = 'Room booking: ' . $status;
            $data['show_confirm_button'] = $status === 'booked';
            $data['show_check_btns'] = in_array($status, ['confirmed', 'checked-in'], true);
            $data['show_checkin_btn'] = $status === 'confirmed';
            $data['show_checkout_btn'] = $data['show_check_btns'];
            $data['show_cancel_btn'] = in_array($status, ['booked', 'customer-confirmed', 'confirmed'], true);
            if ($locale === 'en_US') {
                foreach (['text', 'confirm_text', 'confirm_btn', 'cancel_text', 'cancel_btn', 'checkin_text', 'checkout_text', 'checkin_btn', 'checkout_btn', 'date', 'time'] as $key) {
                    if ($key === 'text') $data['text_html'] = $data['text_en_html'];
                    else $data[$key] = $data[$key . '_en'];
                }
            }
            foreach ([false, true] as $admin) {
                $data['to_admin'] = $admin;
                $name = 'room-' . $status . ($admin ? '-admin' : '') . '-' . $locale;
                file_put_contents($directory . '/' . $name . '.html', RoomMessage::render($data));
                $files[] = $name;
            }
        }
        foreach (['booked', 'customer-confirmed', 'confirmed', 'cancelled'] as $status) {
            $booking = \RRZE\RSVP\Tests\courseMailBooking();
            $booking['status'] = $status;
            foreach ([false, true] as $admin) {
                if ($admin && $status === 'booked') continue;
                $parts = $admin ? $administrator->invoke($notifications, $booking)
                    : $participant->invoke($notifications, $booking, ['verify' => 'synthetic-preview', 'cancel' => 'synthetic-preview']);
                [$subject, $paragraphs, $links, $details] = $parts;
                // Keep preview links unmistakably non-functional, even if the local DB happens to have ID 42.
                foreach ($links as &$link) $link[1] = 'https://example.invalid/action';
                unset($link);
                if (!$deliver->invoke($notifications, 'example@example.invalid', $subject, $paragraphs, $links, $details, $status)) {
                    throw new RuntimeException('Cannot render course preview.');
                }
                $name = 'course-' . $status . ($admin ? '-admin' : '') . '-' . $locale;
                file_put_contents($directory . '/' . $name . '.html', $transport->html);
                file_put_contents($directory . '/' . $name . '.txt', $transport->plain);
                $files[] = $name;
            }
        }
        $data = \RRZE\RSVP\Tests\roomMailData();
        $data['subject'] = str_repeat('Lange Buchungsinformationen & Hinweise ', 5);
        $data['room_name'] = str_repeat('RaumbezeichnungOhneLeerzeichen', 8);
        $data['notes'] = str_repeat('Zusätzliche Hinweise zu Anfahrt und Barrierefreiheit. ', 15);
        $data['to_admin'] = true;
        $data['customer_email'] = str_repeat('long', 15) . '@example.invalid';
        $data['is_locale_not_english'] = false;
        add_filter('theme_mod_custom_logo', '__return_zero');
        try {
            $name = 'room-long-no-logo-' . $locale;
            file_put_contents($directory . '/' . $name . '.html', RoomMessage::render($data));
            $files[] = $name;
        } finally {
            remove_filter('theme_mod_custom_logo', '__return_zero');
        }
    } finally {
        if ($switched) restore_previous_locale();
    }
}
$index = '<!doctype html><html lang="de"><meta charset="utf-8"><title>RSVP Mail-Vorschau</title><h1>Synthetische Mail-Vorschauen</h1><ul>';
foreach ($files as $name) $index .= '<li><a href="' . esc_attr($name) . '.html">' . esc_html($name) . '</a></li>';
file_put_contents($directory . '/index.html', $index . '</ul></html>');
echo count($files) . ' synthetic previews generated in ' . $directory . ". No messages sent.\n";
