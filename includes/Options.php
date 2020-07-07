<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

class Options
{
    const OPTION_NAME = 'rrze_rsvp';

    protected static function deafaultOptions()
    {
        return [
        ];
    }

    public static function getOptions()
    {
        $defaults = self::deafaultOptions();

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
