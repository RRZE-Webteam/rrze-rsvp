<?php

/** Standalone calendar regression tests; no WordPress installation or mail transport needed. */
declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . '/');
require_once dirname(__DIR__) . '/includes/Courses/Calendar.php';

use RRZE\RSVP\Courses\Calendar;

/**
 * @param bool $condition Expected truth.
 * @param string $message Failure explanation.
 * @return void
 */
function courseCalendarAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$booking = [
    'ics_uid' => 'stable-test-uid@rrze-rsvp',
    'start' => strtotime('2027-03-28 00:30:00 UTC'),
    'end' => strtotime('2027-03-28 02:30:00 UTC'),
    'course_title' => str_repeat('Erste Hilfe äöü; ', 15) . "\r\nATTENDEE:malicious",
    'location' => 'A, B; C\\D',
    'guest_email' => 'must-not-appear@example.invalid',
    'calendar_organizer' => 'organizer@example.invalid',
];
$confirmed = Calendar::generate($booking, false, 1800000000);
$cancelled = Calendar::generate($booking, true, 1800000001);
$unfolded = str_replace("\r\n ", '', $confirmed);
courseCalendarAssert(str_contains($confirmed, "METHOD:PUBLISH\r\nBEGIN:VEVENT"), 'The publication method belongs to VCALENDAR.');
courseCalendarAssert(str_contains($cancelled, "METHOD:CANCEL\r\nBEGIN:VEVENT"), 'The cancellation method belongs to VCALENDAR.');
courseCalendarAssert(str_contains($confirmed, "UID:stable-test-uid@rrze-rsvp\r\n") && str_contains($cancelled, "UID:stable-test-uid@rrze-rsvp\r\n"), 'Confirmation and cancellation must retain the same UID.');
courseCalendarAssert(str_contains($cancelled, "STATUS:CANCELLED\r\nSEQUENCE:1"), 'Cancellation must supersede the original calendar event.');
courseCalendarAssert(str_contains($confirmed, 'DTSTART:20270328T003000Z') && str_contains($confirmed, 'DTEND:20270328T023000Z'), 'DST boundaries must preserve the original UTC instants.');
courseCalendarAssert(str_contains($unfolded, 'LOCATION:A\\, B\\; C\\\\D'), 'Calendar text escapes delimiters and backslashes.');
courseCalendarAssert(!str_contains($unfolded, "\r\nATTENDEE:") && str_contains($unfolded, '\\nATTENDEE:malicious'), 'User text must not inject calendar properties.');
courseCalendarAssert(!str_contains($confirmed, $booking['guest_email']), 'Calendar must not leak participant information.');
foreach (explode("\r\n", $confirmed) as $line) {
    courseCalendarAssert(strlen($line) <= 75, 'Every folded line is at most 75 octets.');
    courseCalendarAssert(preg_match('//u', $line) === 1, 'Folding must not split a UTF-8 character.');
}
echo "Course calendar tests passed (UID, cancellation, DST, escaping, UTF-8 folding).\n";
