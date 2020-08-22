<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use DateTime;

class ICS
{
	public static function generate(int $bookingId, string $filename): string
	{
		$booking = Functions::getBooking($bookingId);
		if (empty($booking)) {
			return '';
		}

		$output = '';
		$output .= "BEGIN:VCALENDAR\r\n";
		$output .= "VERSION:2.0\r\n";
		$output .= "PRODID:-//rrze//rsvp//EN\r\n";
		$output .= self::vevent($booking);
		$output .= "END:VCALENDAR\r\n";
		return $output;
	}

	protected static function vevent(array $booking): string
	{
		$timezoneString = get_option('timezone_string');
		$dtstamp = date('Ymd\THis');
		$dtstampFormat = Functions::dateFormat(current_time('timestamp')) . ' ' . Functions::timeFormat(current_time('timestamp'));

		$timestamp = date('ymdHi', $booking['start']);
		$uid = md5($timestamp . date('ymdHi')) . "@rrze-rsvp";
		$dtstamp = date('Ymd\THis');
		$dtstart = date('Ymd\THis', $booking['start']);
		$dtend = date('Ymd\THis', $booking['end']);

		$summary = $booking['room_name'];

		$cancelUrl = Functions::bookingReplyUrl('cancel', sprintf('%s-%s-customer', $booking['id'], $booking['start']), $booking['id']);

		$description = $booking['room_name'] . '\\n';
		$description .= !empty($booking['seat_name']) ? $booking['seat_name'] . '\\n' : '';
		$description .= "\\n\\n" . __('Cancel Booking', 'rrze-rsvp') . ':\\n' . $cancelUrl;
		$description .= "\\n\\n" . __('Generated', 'rrze-rsvp') . ': ' . $dtstampFormat;

		$output = '';
		$output .= "BEGIN:VEVENT\r\n";
		$output .= "UID:" . $uid . "\r\n";
		$output .= "DTSTAMP:" . $dtstamp . "\r\n";
		$output .= "DTSTART;TZID=" . $timezoneString . ":" . $dtstart . "\r\n";
		$output .= "DTEND;TZID=" . $timezoneString . ":" . $dtend . "\r\n";
		$output .= "SUMMARY:" . $summary . "\r\n";
		$output .= "DESCRIPTION:" . $description . "\r\n";
		$output .= "END:VEVENT\r\n";
		return $output;
	}
}
