<?php

namespace RRZE\RSVP;
use function RRZE\RSVP\Config\defaultServiceOptions;
use function RRZE\RSVP\Config\defaultOptions;
use function RRZE\RSVP\Config\getOptionName;

defined('ABSPATH') || exit;

// Obsolet, cause of Settings.php ?
class Options
{


    public static function getOptions()
    {
        $defaults = defaultOptions();

        $options = (array) get_option(getOptionName());
        $options = wp_parse_args($options, $defaults);
        $options = array_intersect_key($options, $defaults);

        return (object) $options;
    }

 
    public static function getServiceOptions(int $termId): object
    {
        $defaults = defaultServiceOptions();

        $options = get_term_meta($termId, getOptionName(), true);
        if ($options === false) {
            $options = $defaults;
        }
        $options = self::parseArgs($options, $defaults);
        $options = self::arrayIntersectKey($options, $defaults);

        return (object) $options;
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
