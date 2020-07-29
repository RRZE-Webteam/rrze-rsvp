<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use RRZE\RSVP\CPT;
use Carbon\Carbon;
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

    public static function getServices($args = [])
    {

        if (!isset($args['hide_empty'])) {
            $args['hide_empty'] = 0;
        }

        $terms = get_terms(CPT::getTaxonomyServiceName(), $args);
        if (is_wp_error($terms) || empty($terms)) {
            return array();
        }

        $services = [];
        foreach ($terms as $term) {
            if ($service = get_term_by('id', $term->term_id, CPT::getTaxonomyServiceName())) {
                $services[] = $service;
            }
        }

        return $services;
    }

    public static function validateDate(string $date, string $format = 'Y-m-d\TH:i:s\Z')
    {
        $d = DateTime::createFromFormat($format, $date);
        if ($d && $d->format($format) === $date) {
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

        $bookingDate = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($post->post_date));

        $serviceTerms = get_the_terms($post->ID, CPT::getTaxonomyServiceName());
        $serviceName = $serviceTerms[0]->name;

        $data['id'] = $post->ID;
        $data['status'] = get_post_meta($post->ID, 'rrze_rsvp_status', true);
        $data['start'] = get_post_meta($post->ID, 'rrze_rsvp_start', true);
        $data['end'] = get_post_meta($post->ID, 'rrze_rsvp_end', true);
        $data['date'] = Functions::dateFormat($data['start']);
        $data['time'] = Functions::timeFormat($data['start']) . ' - ' . Functions::timeFormat($data['end']);
        $data['booking_date'] = $bookingDate;
        $data['service_name'] = $serviceName;

        $data['field_seat'] = get_post_meta($post->ID, 'rrze_rsvp_seat', true);

        $data['field_name'] = get_post_meta($post->ID, 'rrze_rsvp_user_name', true);
        $data['field_email'] = get_post_meta($post->ID, 'rrze_rsvp_user_email', true);
        $data['field_phone'] = get_post_meta($post->ID, 'rrze_rsvp_user_phone', true);

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
