<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

class Options
{
    const OPTION_NAME = 'rrze_rsvp';

    const SERVICE_OPTION_NAME = 'rrze_rsvp';

    protected static function defaultOptions()
    {
        return [];
    }

    protected static function defaultServiceOptions()
    {
        return [
            'notification_email' => '',
            'weeks_in_advance' => 4,
            'auto_confirmation' => 1,
            'event_duration' => '01:00',
            'event_gap' => '01:00',
            'weekdays_timeslots' => [
                [
                    'start' => '09:00',
                    'end' => '18:00'
                ],
                [
                    'start' => '09:00',
                    'end' => '18:00'
                ],
                [
                    'start' => '09:00',
                    'end' => '18:00'
                ],
                [
                    'start' => '09:00',
                    'end' => '18:00'
                ],
                [
                    'start' => '09:00',
                    'end' => '18:00'
                ],
                [
                    'start' => '09:00',
                    'end' => '18:00'
                ],
                [
                    'start' => '00:00',
                    'end' => '00:00'
                ]
            ],
            'sender_name' => '',
            'sender_email' => '',
            'receiver_subject' => __('Thank you for booking', 'rrze-rsvp'),
            'receiver_text' => __('We received your booking and we will notify you once it has been confirmed.', 'rrze-rsvp'),
            'confirm_subject' => __('Your booking has been confirmed', 'rrze-rsvp'),
            'confirm_text' => __('We are happy to inform you that your booking has been confirmed.', 'rrze-rsvp'),
            'cancel_subject' => __('Your booking has been cancelled', 'rrze-rsvp'),
            'cancel_text' => __('Unfortunately we have to cancel your booking on {{=date}} at {{=time}}.', 'rrze-rsvp')
        ];
    }

    public static function getOptions()
    {
        $defaults = self::defaultOptions();

        $options = (array) get_option(static::OPTION_NAME);
        $options = wp_parse_args($options, $defaults);
        $options = array_intersect_key($options, $defaults);

        return (object) $options;
    }

    public static function getOptionName()
    {
        return static::OPTION_NAME;
    }

    public static function getServiceOptions(int $termId): object
    {
        $defaults = self::defaultServiceOptions();

        $options = get_term_meta($termId, static::SERVICE_OPTION_NAME, true);
        if ($options === false) {
            $options = $defaults;
        }
        $options = self::parseArgs($options, $defaults);
        $options = self::arrayIntersectKey($options, $defaults);

        return (object) $options;
    }

    public static function getServiceOptionName()
    {
        return static::SERVICE_OPTION_NAME;
    }

    protected static function parseArgs(&$options, $defaults)
    {
        $options = (array) $options;
        $defaults = (array) $defaults;
        $result = $defaults;
        foreach ($options as $key => &$value) {
            if (is_array($value) && isset($result[$key])) {
                $result[$key] = self::parseArgs($value, $result[$key]);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    protected static function arrayIntersectKey(array $options, array $defaults)
    {
        $options = array_intersect_key($options, $defaults);
        foreach ($options as $key => $value) {
            if (is_array($value) && is_array($defaults[$key])) {
                $value = self::arrayIntersectKey($value, $defaults[$key]);
            }
        }
        return $options;
    }
}
