<?php

namespace RRZE\RSVP\Mail;

defined('ABSPATH') || exit;

/** Render existing room-mail data without altering recipients, links or lifecycle decisions. */
final class RoomMessage
{
    /**
     * Place the primary and optional English message inside one shared layout.
     * @param array $data Prepared room-mail values and existing visibility flags.
     * @return string Complete HTML message.
     */
    public static function render(array $data): string
    {
        $content = '';
        if (!empty($data['is_locale_not_english'])) {
            $content .= EmailLayout::paragraph('(English version below)');
        }
        $content .= self::languageSection($data, false);
        if (!empty($data['is_locale_not_english'])) {
            $content .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
                . '<tr><td lang="en" dir="ltr" style="padding-top:28px;border-top:1px solid #d7dde5;">'
                . '<h2 style="margin:0 0 16px;font-family:Arial,Helvetica,sans-serif;font-size:24px;line-height:30px;">'
                . esc_html($data['subject_en']) . '</h2>' . self::languageSection($data, true) . '</td></tr></table>';
        }
        [$palette, $label] = EmailLayout::bookingStatus($data['mail_status']);
        return EmailLayout::wrap($content, $data['subject'], $palette, $label);
    }

    /**
     * Render one language using the original action and personal-data visibility rules.
     * @param array $data Prepared values; only *_html fields contain trusted rich text.
     * @param bool $english Whether this is the separate English translation.
     * @return string Escaped message fragment with shared details and button components.
     */
    private static function languageSection(array $data, bool $english): string
    {
        $suffix = $english ? '_en' : '';
        $content = '';
        if (!empty($data['reason' . $suffix])) {
            $content .= EmailLayout::paragraph((string) $data['reason' . $suffix]);
        }
        $content .= wpautop($data['text' . $suffix . '_html']);
        $rows = [
            $english ? 'Date' : __('Date', 'rrze-rsvp') => esc_html($data['date' . $suffix]),
            $english ? 'Time' : __('Time', 'rrze-rsvp') => esc_html($data['time' . $suffix]),
            $english ? 'Room' : __('Room', 'rrze-rsvp') => esc_html($data['room_name']),
        ];
        if (!empty($data['location'])) {
            $rows[$english ? 'Location' : __('Location', 'rrze-rsvp')] = nl2br(esc_html($data['location']));
        }
        if (!empty($data['seat_name'])) {
            $rows[$english ? 'Seat' : __('Seat', 'rrze-rsvp')] = esc_html($data['seat_name']);
        }
        if (!empty($data['to_admin'])) {
            $rows[$english ? 'Name' : __('Name', 'rrze-rsvp')] = esc_html($data['customer_name']);
            $rows[$english ? 'Email' : __('Email', 'rrze-rsvp')] = esc_html($data['customer_email']);
        }
        if (!empty($data['show_notes'])) {
            $rows[$data['notes_label']] = nl2br(esc_html($data['notes']));
        }
        $content .= EmailLayout::detailsTable($rows);
        foreach (['confirm' => 'show_confirm_button', 'checkin' => 'show_checkin_btn',
            'checkout' => 'show_checkout_btn', 'cancel' => 'show_cancel_btn'] as $action => $flag) {
            if (empty($data[$flag]) || (in_array($action, ['checkin', 'checkout'], true) && empty($data['show_check_btns']))) {
                continue;
            }
            if (!empty($data[$action . '_text' . $suffix])) {
                $content .= EmailLayout::paragraph($data[$action . '_text' . $suffix]);
            }
            $content .= EmailLayout::actionButton($data[$action . '_url'], $data[$action . '_btn' . $suffix]);
        }
        return $content;
    }
}
