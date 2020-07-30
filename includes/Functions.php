<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use DateTime;

class Functions
{
    public static function actionUrl($atts = [])
    {
        $atts = array_merge(
            [
                'page' => 'rrze-rsvp'
            ],
            $atts
        );
        if (isset($atts['action'])) {
            switch ($atts['action']) {
                case 'add':
                    $atts['_wpnonce'] = wp_create_nonce('add');
                    break;
                case 'edit':
                    $atts['_wpnonce'] = wp_create_nonce('edit');
                    break;
                case 'delete':
                    $atts['_wpnonce'] = wp_create_nonce('delete');
                    break;
                default:
                    break;
            }
        }
        return add_query_arg($atts, get_admin_url(null, 'admin.php'));
    }

    public static function requestVar($param, $default = '')
    {
        if (isset($_POST[$param])) {
            return $_POST[$param];
        }

        if (isset($_GET[$param])) {
            return $_GET[$param];
        }

        return $default;
    }

    public static function isInactiveWorkday($option, $string1, $string2 = '')
    {
        echo ($option['start'] == '00:00' && $option['end'] == '00:00') ? $string1 : $string2;
    }

    public static function validateDate(string $date, string $format = 'Y-m-d\TH:i:s\Z')
    {
        $dateTime = DateTime::createFromFormat($format, $date);
        if ($dateTime && $dateTime->format($format) === $date) {
            return $date;
        } else {
            return false;
        }
    }

    public static function validateTime(string $time): string
    {
        $time = trim($time);
        if (preg_match("/^(2[0-3]|[01][0-9]):([0-5][0-9])$/", $time)) {
            return $time;
        } else if (preg_match("/^(2[0-3]|[01][0-9])$/", $time)) {
            return $time . ':00';
        } else if (preg_match("/^([0-9]):([0-5][0-9])$/", $time)) {
            return '0' . $time;
        } else if (preg_match("/^([0-9])$/", $time)) {
            return '0' . $time . ':00';
        } else {
            return '00:00';
        }
    }

    public static function dateFormat($date)
    {
        return date_i18n(get_option('date_format'), strtotime($date));
    }

    public static function timeFormat($time)
    {
        return date_i18n(get_option('time_format'), strtotime($time));
    }

    public static function getBooking(int $bookingId): array
    {
        $data = [];

        $post = get_post($bookingId);
        if (!$post) {
            return $data;
        }

        $data['id'] = $post->ID;
        $data['status'] = get_post_meta($post->ID, 'rrze-rsvp-booking-status', true);
        $data['start'] = get_post_meta($post->ID, 'rrze-rsvp-booking-start', true);
        $data['end'] = get_post_meta($post->ID, 'rrze-rsvp-booking-end', true);
        $data['date'] = Functions::dateFormat($data['start']);
        $data['time'] = Functions::timeFormat($data['start']) . ' - ' . Functions::timeFormat($data['end']);

        $data['booking_date'] = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($post->post_date));

        $data['seat'] = get_post_meta($post->ID, 'rrze-rsvp-booking-seat', true);
        $data['room'] = get_post_meta($data['seat'], 'rrze-rsvp-seat-room', true);

        $data['notes'] = get_post_meta($post->ID, 'rrze-rsvp-booking-notes', true);

        $data['guest_firstname'] = get_post_meta($post->ID, 'rrze-rsvp-booking-guest-firstname', true);
        $data['guest_lastname'] = get_post_meta($post->ID, 'rrze-rsvp-booking-guest-lastname', true);
        $data['guest_email'] = get_post_meta($post->ID, 'rrze-rsvp-booking-guest-email', true);
        $data['guest_phone'] = get_post_meta($post->ID, 'rrze-rsvp-booking-guest-phone', true);

        return $data;
    }

    public static function getBookingFields(int $bookingId): array
    {
        $data = [];
        $metaAry = get_post_meta($bookingId);
        foreach($metaAry as $key => $value) {
            if(strpos($key, 'field_') == 0) {
                $data[$key] = $value;
            }
        }
        return $data;
    }

    public static function dataToStr(array $data, string $delimiter = '<br>'): string
    {
        $output = '';

        foreach ($data as $key => $value) {
            $value = sanitize_text_field($value) ? sanitize_text_field($value) : '-';
            $output .= $key . ': ' . $value . $delimiter;
        }

        return $output;
    }

    public static function bookingReplyUrl(string $action, string $bookingDate, int $id): string
    {
        $hash = password_hash($bookingDate, PASSWORD_DEFAULT);
        return get_site_url() . "/?rrze-rsvp-booking-reply=" . $hash . "&id=" . $id . "&action=" . $action;
    }
}
