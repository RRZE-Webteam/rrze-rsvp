<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use DateTime;

class ICS
{


	public static function generate(array &$booking, string $filename, string $recipient = 'customer'): string
	{
		if (empty($booking)) {
			return '';
		}
		$output = '';
		$output .= "BEGIN:VCALENDAR\r\n";
		$output .= "VERSION:2.0\r\n";
		$output .= "PRODID:-//rrze//rsvp//EN\r\n";
		$output .= "CALSCALE:GREGORIAN\r\n";
		$output .= "BEGIN:VTIMEZONE\r\n";
		$output .= "TZID:Europe/Berlin\r\n";
		$output .= "TZURL:http://tzurl.org/zoneinfo-outlook/Europe/Berlin\r\n";
		$output .= "X-LIC-LOCATION:Europe/Berlin\r\n";
		$output .= "BEGIN:DAYLIGHT\r\n";
		$output .= "TZOFFSETFROM:+0100\r\n";
		$output .= "TZOFFSETTO:+0200\r\n";
		$output .= "TZNAME:CEST\r\n";
		$output .= "DTSTART:19700329T020000\r\n";
		$output .= "RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU\r\n";
		$output .= "END:DAYLIGHT\r\n";
		$output .= "BEGIN:STANDARD\r\n";
		$output .= "TZOFFSETFROM:+0200\r\n";
		$output .= "TZOFFSETTO:+0100\r\n";
		$output .= "TZNAME:CET\r\n";
		$output .= "DTSTART:19701025T030000\r\n";
		$output .= "RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU\r\n";
		$output .= "END:STANDARD\r\n";
		$output .= "END:VTIMEZONE\r\n";
		$output .= self::vevent($booking, $recipient);
		$output .= "END:VCALENDAR\r\n";
		return $output;
	}

	// // Die folgenden function funktioniert fehlerfrei mit korrekten Zeitangaben (ist valid https://icalendar.org/validator.html ) ABER zeigt bei Klick im Kalender die Zeiten als GMT an
	// public static function generate(array &$booking, string $filename, string $recipient = 'customer'): string
	// {
	// 	if (empty($booking)) {
	// 		return '';
	// 	}
	// 	$output = '';
	// 	$output .= "BEGIN:VCALENDAR\r\n";
	// 	$output .= "VERSION:2.0\r\n";
	// 	$output .= "PRODID:-//rrze//rsvp//EN\r\n";
	// 	$output .= self::vevent($booking, $recipient);
	// 	$output .= "END:VCALENDAR\r\n";
	// 	return $output;
	// }


	protected static function vevent(array &$booking, string $recipient = 'customer'): string
	{
		$timezoneString = 'Europe/Berlin';
		$dtstamp = date('Ymd\THis');
		$dtstampFormat = Functions::dateFormat(current_time('timestamp')) . ' ' . Functions::timeFormat(current_time('timestamp'));

		$timestamp = date('ymdHi', $booking['start']);
		$dtstamp = date('Ymd\THis');
		$dtstart = date('Ymd\THis', $booking['start']);
		$dtend = date('Ymd\THis', $booking['end']);

		$summary = $booking['room_name'];

		$description = $booking['room_name'] . '\\n';
		$description .= !empty($booking['seat_name']) ? $booking['seat_name'] . '\\n' : '';
		if ($recipient == 'customer') {
			$cancelUrl = Functions::bookingReplyUrl('cancel', sprintf('%s-%s-customer', $booking['id'], $booking['start']), $booking['id']);
			$description .= "\\n\\n" . __('Cancel Booking', 'rrze-rsvp') . ':\\n' . $cancelUrl;	
		}
		$description .= "\\n\\n" . __('Generated', 'rrze-rsvp') . ': ' . $dtstampFormat;

		$output = '';

		$output .= "BEGIN:VEVENT\r\n";
		if ($booking['status'] == 'cancelled'){
			$output .= "METHOD:CANCEL\r\n";
			$output .= "STATUS:CANCELLED\r\n";
			$uid = get_post_meta($booking['id'], 'rrze-rsvp-booking-ics-uid', TRUE);
		}else{
			$uid = md5($timestamp . date('ymdHi')) . "@rrze-rsvp";
			update_post_meta($booking['id'], 'rrze-rsvp-booking-ics-uid', $uid);
		}
		$output .= "UID:" . $uid . "\r\n";
		$output .= "DTSTAMP:" . $dtstamp . "\r\n";
		$output .= "DTSTART;TZID=" . $timezoneString . ":" . $dtstart . "\r\n";
		$output .= "DTEND;TZID=" . $timezoneString . ":" . $dtend . "\r\n";
		$output .= "SUMMARY:" . $summary . "\r\n";
		$output .= "DESCRIPTION:" . $description . "\r\n";
		$output .= "END:VEVENT\r\n";
		return $output;
	}

	// // Die folgenden function funktioniert fehlerfrei mit korrekten Zeitangaben (ist valid https://icalendar.org/validator.html ) ABER zeigt bei Klick im Kalender die Zeiten als GMT an
	// protected static function vevent(array &$booking, string $recipient = 'customer'): string {
	// 	$dtstamp = Functions::formatDateGMT(time());
	// 	$dtstampFormat = Functions::dateFormat(current_time('timestamp')) . ' ' . Functions::timeFormat(current_time('timestamp'));
	// 	$timestamp = date('ymdHi', $booking['start']);
	// 	$dtstart = Functions::formatDateGMT($booking['start']);
	// 	$dtend = Functions::formatDateGMT($booking['end']);

	// 	$summary = $booking['room_name'];
	// 	$description = $booking['room_name'] . '\\n';
	// 	$description .= !empty($booking['seat_name']) ? $booking['seat_name'] . '\\n' : '';

	// 	if ($recipient == 'customer') {
	// 		$cancelUrl = Functions::bookingReplyUrl('cancel', sprintf('%s-%s-customer', $booking['id'], $booking['start']), $booking['id']);
	// 		$description .= "\\n\\n" . __('Cancel Booking', 'rrze-rsvp') . ':\\n' . $cancelUrl;	
	// 	}

	// 	$description .= "\\n\\n" . __('Generated', 'rrze-rsvp') . ': ' . $dtstampFormat;

	// 	$output = '';
	// 	$output .= "BEGIN:VEVENT\r\n";

	// 	if ($booking['status'] == 'cancelled'){
	// 		$output .= "METHOD:CANCEL\r\n";
	// 		$output .= "STATUS:CANCELLED\r\n";
	// 		$uid = get_post_meta($booking['id'], 'rrze-rsvp-booking-ics-uid', TRUE);
	// 	}else{
	// 		$uid = md5($timestamp . date('ymdHi')) . "@rrze-rsvp";
	// 		update_post_meta($booking['id'], 'rrze-rsvp-booking-ics-uid', $uid);
	// 	}

	// 	$output .= "UID:" . $uid . "\r\n";
	// 	$output .= "DTSTAMP:" . $dtstamp . "\r\n";
	// 	$output .= "DTSTART:" . $dtstart . "\r\n";
	// 	$output .= "DTEND:" . $dtend . "\r\n";
	// 	$output .= "SUMMARY:" . $summary . "\r\n";
	// 	$output .= "DESCRIPTION:" . $description . "\r\n";
	// 	$output .= "END:VEVENT\r\n";
	// 	return $output;
	// }
}
