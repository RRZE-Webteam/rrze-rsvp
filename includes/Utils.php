<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use DateTime;

class Utils
{
    public static function getEndOfDayTimestamp(int $timestamp)
    {
        $date = new DateTime('@' . $timestamp);
        $date->setTime(23, 59, 59);

        return $date->getTimestamp();
    }
}
