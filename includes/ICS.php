<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use DateTime;

class ICS
{
    public static function generate(int $bookingId) {
        $booking = Functions::getBooking($bookingId);
        if (empty($booking)) {
            return;
        }

        $filename = 'booking_' . date('Y-m-d-H-i', strtotime($booking['start'])) . '.ics';
        header('Content-type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        echo "BEGIN:VCALENDAR\r\n";
        echo "VERSION:2.0\r\n";
        echo "PRODID:-//rrze//rsvp//EN\r\n";
        self::vevent($booking);
        echo "END:VCALENDAR\r\n";
    }
    
	protected static function vevent(array $booking)
	{
		$timezoneString = get_option('timezone_string');
		$dtstamp = date('Ymd\THis');
		$dtstampFormat = Functions::dateFormat('now') . ' ' . Functions::timeFormat('now');

		$timestamp = date('ymdHi', strtotime($booking['start']));
		$uid = md5($timestamp . date('ymdHi')) . "@rrze-rsvp";
		$dtstamp = date("Ymd\THis");
		$dtstart = date("Ymd\THis", strtotime($booking['start']));
		$dtend = date("Ymd\THis", strtotime($booking['end']));

		$summary = get_the_title($booking['room']);
		if ($booking['confirmed'] == 'confirmed') $summary .= ' [' . __('Confirmed', 'rrze-rsvp') . ']';

		$cancelUrl = Functions::bookingReplyUrl('cancel', $booking['booking_date'], $booking['id']);

		$description = Functions::dataToStr($booking['fields'], '\\n');
		$description .= "\\n\\n" . __('Cancel Booking', 'rrze-rsvp') . ':\\n' . $cancelUrl;
		$description .= "\\n\\n" . __('Generated', 'rrze-rsvp') . ': ' . $dtstampFormat;


		echo "BEGIN:VEVENT\r\n";
		echo "UID:" . $uid . "\r\n";
		echo "DTSTAMP:" . $dtstamp . "\r\n";
		echo "DTSTART;TZID=" . $timezoneString . ":" . $dtstart . "\r\n";
		echo "DTEND;TZID=" . $timezoneString . ":" . $dtend . "\r\n";
		echo "SUMMARY:" . $summary . "\r\n";
		echo "DESCRIPTION:" . $description . "\r\n";
		echo "END:VEVENT\r\n";
	}    
}