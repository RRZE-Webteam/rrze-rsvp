<?php

/** Standalone administration boundary tests; transactional writes are tested separately against MySQL. */
declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . '/');

/** Minimal WordPress error contract for validation tests. */
class WP_Error
{
    private string $code;
    private string $message;
    private array $data;

    /** @param string $code Error code. @param string $message Error message. @param array $data Error context. */
    public function __construct(string $code, string $message, array $data = [])
    {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }

    /** @return string Error code. */
    public function get_error_code(): string { return $this->code; }
    /** @return string Error message. */
    public function get_error_message(): string { return $this->message; }
    /** @return array Error context. */
    public function get_error_data(): array { return $this->data; }
    /** @param array $data Error context. @return void */
    public function add_data(array $data): void { $this->data = $data; }
}

/** @param mixed $value Value under test. @return bool Whether this is a WordPress error. */
function is_wp_error($value): bool { return $value instanceof WP_Error; }
/** @param string $text Source message. @param string $domain Translation domain. @return string Source message. */
function __(string $text, string $domain = ''): string { return $text; }
/** @param mixed $text Text to sanitize. @return string Plain text. */
function sanitize_text_field($text): string { return is_scalar($text) ? trim(strip_tags((string) $text)) : ''; }
/** @param string $value Key input. @return string Safe key. */
function sanitize_key(string $value): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)); }
/** @param mixed $value Numeric input. @return int Absolute integer. */
function absint($value): int { return abs((int) $value); }
/** @param string $nonce Submitted nonce. @param string $action Expected action. @return bool Exact test nonce match. */
function wp_verify_nonce(string $nonce, string $action): bool { return $nonce === 'valid:' . $action; }
/** @param string $capability Capability name. @param int $id Object ID. @return bool Test permission state. */
function current_user_can(string $capability, int $id): bool
{
    $GLOBALS['admin_checked_capability'] = [$capability, $id];
    return $GLOBALS['admin_allowed'];
}
/** @return DateTimeZone Website timezone used in tests. */
function wp_timezone(): DateTimeZone { return new DateTimeZone('Europe/Berlin'); }
/** @return bool Test requests are administrative. */
function is_admin(): bool { return true; }

require_once dirname(__DIR__) . '/includes/Courses/Model.php';
require_once dirname(__DIR__) . '/includes/Courses/Repository.php';
require_once dirname(__DIR__) . '/includes/Courses/BookingService.php';
require_once dirname(__DIR__) . '/includes/Courses/Admin/Input.php';
require_once dirname(__DIR__) . '/includes/Courses/Admin.php';

use RRZE\RSVP\Courses\Admin;
use RRZE\RSVP\Courses\Admin\Input;
use RRZE\RSVP\Courses\BookingService;
use RRZE\RSVP\Courses\Model;
use RRZE\RSVP\Courses\Repository;

/** Stable records used to prove parent ownership and ID routing. */
class AdminTestRepository extends Repository
{
    /** @param int $id Occurrence ID. @return array Stable date identity. */
    public function getOccurrence(int $id) { return ['id' => $id, 'course_id' => $id === 999 ? 12 : 7]; }
    /** @param int $id Booking ID. @return array Stable booking identity. */
    public function getBooking(int $id) { return ['id' => $id, 'course_id' => $id === 999 ? 12 : 7]; }
}

/** Record routing to the shared service without writing to a database or sending mail. */
class AdminTestService extends BookingService
{
    public array $calls = [];

    /** @param int $courseId Course ID. @param array $data UTC occurrence fields. @param int $occurrenceId Stable date ID. @return array Service result. */
    public function saveOccurrence(int $courseId, array $data, int $occurrenceId = 0)
    {
        $this->calls[] = ['save', $courseId, $data, $occurrenceId];
        return ['id' => $occurrenceId ?: 123];
    }

    /** @param int $bookingId Booking ID. @return array Service result. */
    public function approve(int $bookingId) { $this->calls[] = ['approve', $bookingId]; return ['id' => $bookingId]; }
    /** @param int $bookingId Booking ID. @param string $reason Cancellation reason. @param bool $manager Manager action. @param string $token Optional public token. @return array Service result. */
    public function cancel(int $bookingId, string $reason = 'admin', bool $manager = true, string $token = '')
    {
        $this->calls[] = ['cancel', $bookingId, $reason, $manager];
        return ['id' => $bookingId];
    }
    /** @param int $bookingId Booking ID. @return array Service result. */
    public function resend(int $bookingId) { $this->calls[] = ['resend', $bookingId]; return ['id' => $bookingId]; }
    /** @param int $occurrenceId Stable date ID. @return array Service result. */
    public function cancelOccurrence(int $occurrenceId) { $this->calls[] = ['cancel_date', $occurrenceId]; return ['id' => $occurrenceId]; }
    /** @param int $occurrenceId Stable date ID. @param bool $force Force deletion flag. @return array Service result. */
    public function deleteOccurrence(int $occurrenceId, bool $force = false) { $this->calls[] = ['delete_date', $occurrenceId, $force]; return ['id' => $occurrenceId]; }
}

/** Minimal query contract for testing the combined course/legacy filters. */
class AdminTestQuery
{
    public array $values;
    /** @param array $values Existing query vars. */
    public function __construct(array $values) { $this->values = $values; }
    /** @return bool This is the main admin listing. */
    public function is_main_query(): bool { return true; }
    /** @param string $key Query key. @return mixed Stored value. */
    public function get(string $key) { return $this->values[$key] ?? null; }
    /** @param string $key Query key. @param mixed $value New value. @return void */
    public function set(string $key, $value): void { $this->values[$key] = $value; }
}

/** @param bool $condition Expected result. @param string $message Failure detail. @return void */
function adminAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param string $operation Operation name. @param int $id Stable target. @param array $extra Input fields. @return array Scoped authorized payload. */
function adminRequest(string $operation, int $id, array $extra = []): array
{
    return array_merge(['operation' => $operation, 'course_id' => 7, 'target_id' => $id,
        '_rsvp_nonce' => 'valid:rsvp_course_admin_' . $operation . '_7_' . $id], $extra);
}

$timezone = wp_timezone();
adminAssert(Input::timestamp('2027-01-12T09:30', $timezone) === strtotime('2027-01-12 08:30:00 UTC'), 'Winter editor values convert from CET to UTC.');
adminAssert(Input::timestamp('2027-07-12T09:30', $timezone) === strtotime('2027-07-12 07:30:00 UTC'), 'Summer editor values convert from CEST to UTC.');
adminAssert(is_wp_error(Input::timestamp('2027-03-28T02:30', $timezone)), 'A nonexistent daylight-saving time is rejected instead of silently shifted.');
adminAssert(is_wp_error(Input::timestamp('2027-02-30T09:30', $timezone)), 'Impossible calendar dates cannot roll over into another month.');
adminAssert(Input::timestamp('', $timezone, true) === 0, 'An empty optional registration bound selects the service default.');
adminAssert(is_wp_error(Input::timestamp('', $timezone)), 'A required start or end cannot be empty.');

$repository = new AdminTestRepository();
$service = new AdminTestService($repository);
$admin = new Admin($repository, $service);
$GLOBALS['admin_allowed'] = true;
$input = ['start' => '2027-07-12T09:30', 'end' => '2027-07-12T12:30', 'location' => 'Training centre', 'capacity' => '20'];

$invalid = Input::occurrence(array_merge($input, ['capacity' => '1.5']), $timezone);
adminAssert(is_wp_error($invalid) && $invalid->get_error_data()['field'] === 'capacity', 'Fractional participant limits identify the invalid field.');
$invalid = Input::occurrence(array_merge($input, ['start' => ['unexpected']]), $timezone);
adminAssert(is_wp_error($invalid) && $invalid->get_error_data()['field'] === 'start', 'Malformed field arrays produce validation errors.');

$result = $admin->execute(adminRequest('save_date', 87, ['occurrence' => $input]));
adminAssert(!is_wp_error($result) && $service->calls[0][3] === 87, 'Updating a row sends its persisted ID, never its visual position.');
adminAssert($service->calls[0][2]['start'] === strtotime('2027-07-12 07:30:00 UTC'), 'The service receives UTC, not wall-clock epoch values.');
adminAssert($service->calls[0][2]['registration_start'] === 0 && $service->calls[0][2]['registration_end'] === 0, 'Optional bounds use the published/start defaults.');
adminAssert($GLOBALS['admin_checked_capability'] === ['edit_post', 7], 'Date edits require the parent course edit capability.');

$calls = count($service->calls);
$result = $admin->execute(adminRequest('save_date', 87, ['_rsvp_nonce' => 'valid:rsvp_course_admin_save_date_7_86', 'occurrence' => $input]));
adminAssert(is_wp_error($result) && $result->get_error_data()['status'] === 403, 'A nonce for a different occurrence cannot authorize this date.');
$result = $admin->execute(adminRequest('cancel_date', 87, ['_rsvp_nonce' => 'valid:rsvp_course_admin_save_date_7_87']));
adminAssert(is_wp_error($result), 'A save nonce cannot be reused to cancel a date.');
$GLOBALS['admin_allowed'] = false;
$result = $admin->execute(adminRequest('save_date', 87, ['occurrence' => $input]));
adminAssert(is_wp_error($result) && $result->get_error_code() === 'course_admin_forbidden', 'A valid nonce does not replace the edit capability.');
$GLOBALS['admin_allowed'] = true;
$result = $admin->execute(adminRequest('save_date', 999, ['occurrence' => $input]));
adminAssert(is_wp_error($result) && $result->get_error_code() === 'course_admin_target', 'A date belonging to another course cannot be edited through this course.');
$result = $admin->execute(adminRequest('save_date', 87, ['occurrence' => array_merge($input, ['start' => '2027-02-30T09:30'])]));
adminAssert(is_wp_error($result) && $result->get_error_data()['field'] === 'start', 'Invalid date values never reach the writer.');
adminAssert(count($service->calls) === $calls, 'Rejected requests do not call any write service.');

foreach (['approve_booking', 'reject_booking', 'cancel_booking', 'resend_booking'] as $operation) {
    $result = $admin->execute(adminRequest($operation, 55));
    adminAssert(!is_wp_error($result), 'Authorized booking action is routed: ' . $operation);
    adminAssert($GLOBALS['admin_checked_capability'] === ['edit_post', 55], 'Booking actions require the booking edit capability.');
}
adminAssert($service->calls[2] === ['cancel', 55, 'rejected', true], 'Rejection keeps its explicit reason and manager authorization.');
$result = $admin->execute(adminRequest('approve_booking', 999));
adminAssert(is_wp_error($result), 'A booking from another course is rejected before its status can change.');
$admin->execute(adminRequest('cancel_date', 87));
$admin->execute(adminRequest('delete_date', 87));
adminAssert(end($service->calls) === ['delete_date', 87, false], 'Unused date deletion uses the guarded service and normal trash semantics.');

$legacy = ['relation' => 'OR', ['key' => 'legacy-status', 'value' => 'confirmed'], ['key' => 'legacy-status', 'value' => 'booked']];
$query = new AdminTestQuery(['post_type' => 'booking', 'meta_query' => $legacy]);
$_GET = ['rsvp_course_id' => '7', 'rsvp_occurrence_id' => '87'];
$admin->filterBookings($query);
$meta = $query->get('meta_query');
adminAssert($meta['relation'] === 'AND' && $meta[2] === $legacy, 'Course/date filters preserve the full legacy OR expression inside an AND group.');
adminAssert($meta[0]['key'] === Model::BOOKING_COURSE_META && $meta[1]['key'] === Model::BOOKING_DATE_META, 'Course filters use explicit course references, not room/seat fields.');

echo "Course admin input, UTC, stable-ID, authorization and filter tests passed.\n";
