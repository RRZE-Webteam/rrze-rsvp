<?php

namespace RRZE\RSVP\Tests;

/** @return array Synthetic prepared room message, containing no real recipient or action token. */
function roomMailData(): array
{
    return [
        'is_locale_not_english' => true, 'mail_status' => 'booked',
        'subject' => 'Bitte bestätigen Sie Ihre Buchung', 'subject_en' => 'Please confirm your booking',
        'text_html' => 'Vielen Dank für Ihre Buchung. <strong>Bitte bestätigen Sie den Termin.</strong>',
        'text_en_html' => 'Thank you for your booking. <strong>Please confirm your appointment.</strong>',
        'reason' => '', 'reason_en' => '', 'date' => '12. November 2026', 'date_en' => 'November 12, 2026',
        'time' => '09:00 – 10:00', 'time_en' => '9:00 am – 10:00 am', 'room_name' => 'Sprechzimmer 1', 'seat_name' => 'A',
        'location' => "Beispielstraße 1\n91054 Erlangen",
        'to_admin' => false, 'customer_name' => 'Erika Beispiel', 'customer_email' => 'example@example.invalid',
        'show_notes' => true, 'notes_label' => 'Anmerkungen', 'notes' => "Bitte am Haupteingang abholen.\nVielen Dank!",
        'show_confirm_button' => true, 'confirm_url' => 'https://example.invalid/confirm?booking=42&token=synthetic',
        'confirm_text' => 'Bitte bestätigen Sie jetzt Ihre Buchung.', 'confirm_text_en' => 'Please confirm your booking now.',
        'confirm_btn' => 'Bestätigen', 'confirm_btn_en' => 'Confirm',
        'show_cancel_btn' => true, 'cancel_url' => 'https://example.invalid/cancel?booking=42&token=synthetic',
        'cancel_text' => 'Bitte stornieren Sie rechtzeitig, falls sich Ihre Pläne ändern.', 'cancel_text_en' => 'Please cancel in time if your plans change.',
        'cancel_btn' => 'Stornieren', 'cancel_btn_en' => 'Cancel',
        'show_check_btns' => false, 'show_checkin_btn' => false, 'show_checkout_btn' => false,
        'checkin_url' => 'https://example.invalid/checkin', 'checkout_url' => 'https://example.invalid/checkout',
        'checkin_text' => 'Bitte checken Sie vor Ort ein.', 'checkin_text_en' => 'Please check in on site.',
        'checkout_text' => 'Bitte checken Sie beim Verlassen aus.', 'checkout_text_en' => 'Please check out when you leave.',
        'checkin_btn' => 'Einchecken', 'checkin_btn_en' => 'Check In', 'checkout_btn' => 'Auschecken', 'checkout_btn_en' => 'Check Out',
    ];
}

/** @return array Synthetic course snapshot suitable for rendering without database writes. */
function courseMailBooking(): array
{
    return [
        'id' => 42, 'status' => 'booked', 'course_title' => 'Erste Hilfe für Mitarbeitende',
        'start' => strtotime('2026-11-12T09:00:00+01:00'), 'end' => strtotime('2026-11-12T16:00:00+01:00'),
        'expires_at' => strtotime('2026-11-02T16:30:00+01:00'), 'location' => 'Erlangen, Schulungszentrum, Raum 2',
        'guest_firstname' => 'Erika', 'guest_lastname' => 'Beispiel', 'guest_email' => 'example@example.invalid',
        'cancel_reason' => 'customer',
    ];
}
