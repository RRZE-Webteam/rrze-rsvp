<?php

namespace RRZE\RSVP\Courses;

defined('ABSPATH') || exit;

/** Portable course calendar messages using the booking's immutable UID and UTC times. */
final class Calendar
{
    /**
     * Build a calendar message without disclosing participant information or bearer tokens.
     *
     * @param array $booking Private course booking snapshot.
     * @param bool $cancelled Whether the previously sent event is being cancelled.
     * @param int|null $timestamp Optional deterministic creation time for tests.
     * @return string RFC 5545 calendar with CRLF and UTF-8-safe line folding.
     */
    public static function generate(array $booking, bool $cancelled = false, ?int $timestamp = null): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//RRZE//RSVP Courses//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:' . ($cancelled ? 'CANCEL' : 'PUBLISH'),
            'BEGIN:VEVENT',
            'UID:' . self::escape((string) $booking['ics_uid']),
            'DTSTAMP:' . gmdate('Ymd\THis\Z', $timestamp ?? time()),
            'DTSTART:' . gmdate('Ymd\THis\Z', (int) $booking['start']),
            'DTEND:' . gmdate('Ymd\THis\Z', (int) $booking['end']),
            'SUMMARY:' . self::escape((string) $booking['course_title']),
            'LOCATION:' . self::escape((string) $booking['location']),
            'STATUS:' . ($cancelled ? 'CANCELLED' : 'CONFIRMED'),
            'SEQUENCE:' . ($cancelled ? '1' : '0'),
        ];
        if (!empty($booking['calendar_organizer'])) {
            $lines[] = 'ORGANIZER:mailto:' . self::escape((string) $booking['calendar_organizer']);
        }
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    /**
     * Escape calendar TEXT so user-provided names cannot introduce properties.
     *
     * @param string $value Plain text.
     * @return string Escaped calendar TEXT.
     */
    private static function escape(string $value): string
    {
        return str_replace(["\\", "\r\n", "\r", "\n", ';', ','], ["\\\\", '\\n', '\\n', '\\n', '\\;', '\\,'], $value);
    }

    /**
     * Fold at 75 octets, preserving complete Unicode characters on each line.
     *
     * @param string $line Unfolded property.
     * @return string One or more CRLF-separated physical lines.
     */
    private static function fold(string $line): string
    {
        $characters = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            $characters = str_split($line);
        }
        $result = '';
        $length = 0;
        foreach ($characters as $character) {
            if ($length + strlen($character) > 75) {
                $result .= "\r\n ";
                $length = 1;
            }
            $result .= $character;
            $length += strlen($character);
        }
        return $result;
    }
}
