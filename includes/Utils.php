<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use DateTime;
use DateTimeZone;

class Utils
{
    public static function getEndOfDayTimestamp(int $timestamp)
    {
        $timezone = wp_timezone()->getName();
        $date = new DateTime('@' . $timestamp);
        $date->setTimezone(new DateTimeZone($timezone));
        $endOfDay = new DateTime('tomorrow', new DateTimeZone($timezone));
        $endOfDay->setDate($date->format('Y'), $date->format('m'), $date->format('d'));
        $endOfDay->modify('-1 second');

        return $endOfDay->getTimestamp();
    }
}
