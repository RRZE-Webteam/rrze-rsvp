<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

class Options
{
    const OPTION_NAME = 'rrze_rsvp';

    protected static function defaultOptions()
    {
        return [
            'weeks_in_advance' => 5,
            'event_duration' => '01:00',
            'event_gap' => '01:00',
            'workdays' => [
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
                ],
                [
                    'start' => '00:00',
                    'end' => '00:00'
                ]
            ]
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
}
