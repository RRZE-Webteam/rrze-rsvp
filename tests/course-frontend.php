<?php

/** Standalone controller boundary tests; the real transactional service is tested separately. */
declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . '/');

/** Minimal WordPress error value used to exercise adapter decisions. */
class WP_Error
{
    public string $code;
    public string $message;
    public array $data;

    /** @param string $code Error identifier. @param string $message Explanation. @param array $data HTTP status. */
    public function __construct(string $code, string $message, array $data = [])
    {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }
}

/** Incoming request double supporting body and case-insensitive header access. */
class WP_REST_Request
{
    private array $params;
    private array $headers;

    /** @param array $params Body/route data. @param array $headers Request headers. */
    public function __construct(array $params = [], array $headers = [])
    {
        $this->params = $params;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
    }

    /** @param string $key Parameter name. @return mixed Parameter value. */
    public function get_param(string $key) { return $this->params[$key] ?? null; }

    /** @param string $key Header name. @return string Header value. */
    public function get_header(string $key): string { return $this->headers[strtolower($key)] ?? ''; }
}

/** Public JSON response double recording data and cache headers. */
class WP_REST_Response
{
    public array $data;
    public int $status;
    public array $headers = [];

    /** @param array $data JSON payload. @param int $status HTTP status. */
    public function __construct(array $data, int $status = 200) { $this->data = $data; $this->status = $status; }

    /** @param string $key Header name. @param string $value Header value. @return void */
    public function header(string $key, string $value): void { $this->headers[$key] = $value; }
}

/** @param mixed $value Candidate value. @return bool Whether this is an error result. */
function is_wp_error($value): bool { return $value instanceof WP_Error; }
/** @param string $message Source string. @param string $domain Text domain. @return string Source translation. */
function __(string $message, string $domain): string { return $message; }
/** @param string $one Singular. @param string $many Plural. @param int $count Number. @param string $domain Domain. @return string Chosen message. */
function _n(string $one, string $many, int $count, string $domain): string { return $count === 1 ? $one : $many; }
/** @param string $email Email address. @return bool Test validation. */
function is_email(string $email): bool { return filter_var($email, FILTER_VALIDATE_EMAIL) !== false; }
/** @return string Stable locale. */
function determine_locale(): string { return 'en_US'; }
/** @param string $path Optional endpoint. @return string Local origin. */
function rest_url(string $path = ''): string { return 'https://courses.example.test/wp-json/' . $path; }
/** @param string $url URL to parse. @return array|false Parsed URL. */
function wp_parse_url(string $url) { return parse_url($url); }
/** @param string $nonce Submitted nonce. @param string $action Expected action. @return bool Whether signature matches. */
function wp_verify_nonce(string $nonce, string $action): bool { return $nonce === 'valid-form-nonce' && $action === 'rrze-rsvp-course-booking'; }
/** @param string $cap Capability name. @param mixed ...$args Object IDs. @return bool Stub capability decision. */
function current_user_can(string $cap, ...$args): bool { return !empty($GLOBALS['frontend_test_capabilities'][$cap]); }
/** @param string $name Site option. @return string Formatting value. */
function get_option(string $name): string { return $name === 'date_format' ? 'Y-m-d' : 'H:i'; }
/** @param string $format PHP format. @param int $timestamp UTC epoch. @return string Site-local display. */
function wp_date(string $format, int $timestamp): string { return (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('Europe/Berlin'))->format($format); }
/** @param string $namespace REST namespace. @param string $path Endpoint path. @param array $config Route registration. @return void */
function register_rest_route(string $namespace, string $path, array $config): void { $GLOBALS['frontend_test_routes'][$path] = $config; }

require_once dirname(__DIR__) . '/includes/Courses/Model.php';
require_once dirname(__DIR__) . '/includes/Courses/Repository.php';
require_once dirname(__DIR__) . '/includes/Courses/BookingService.php';
require_once dirname(__DIR__) . '/includes/Courses/Frontend.php';

use RRZE\RSVP\Courses\Repository;
use RRZE\RSVP\Courses\BookingService;
use RRZE\RSVP\Courses\Frontend;

/** Fixture readers; direct database behavior belongs to the core integration suite. */
class FrontendRepository extends Repository
{
    public array $course = ['id' => 12, 'post_status' => 'publish', 'settings' => ['access' => 'sso_or_email']];
    public array $dates;

    /** Create one public, one draft and one started date. */
    public function __construct()
    {
        $date = ['id' => 42, 'course_id' => 12, 'post_status' => 'publish', 'start' => time() + 7200];
        $this->dates = [42 => $date, 43 => array_merge($date, ['id' => 43, 'post_status' => 'draft']),
            44 => array_merge($date, ['id' => 44, 'start' => time() - 600])];
    }

    /** @param int $id Course ID. @return array Fixture. */
    public function getCourse(int $id) { return $this->course; }
    /** @param int $id Date ID. @return array|WP_Error Fixture or not found. */
    public function getOccurrence(int $id) { return $this->dates[$id] ?? new WP_Error('not_found', 'Missing'); }
    /** @param int $courseId Course ID. @param bool $futureOnly Future filter. @return array Fixtures. */
    public function getOccurrences(int $courseId, bool $futureOnly = false): array { return array_values($this->dates); }
}

/** Spy service records trust and command parameters without modifying data or sending mail. */
class FrontendService extends BookingService
{
    public array $calls = [];
    public string $reason = 'available';
    public array $result = ['id' => 71, 'course_id' => 12, 'occurrence_id' => 42, 'status' => 'booked', 'expires_at' => 1800000000,
        'created' => true, 'guest_email' => 'private@example.invalid', 'verify_token' => 'private-token'];

    /** @param int $id Occurrence ID. @return array Deliberately contains private fields to test the boundary allowlist. */
    public function availability(int $id)
    {
        return ['occurrence_id' => $id, 'course_id' => 12, 'capacity' => 20, 'free' => 2, 'confirmed' => 14, 'pending' => 4,
            'bookable' => $this->reason === 'available', 'reason' => $this->reason, 'start' => 1792888200, 'end' => 1792895400,
            'location' => 'Test room', 'guest_email' => 'private@example.invalid', 'token' => 'secret'];
    }

    /** @param int $id Date ID. @param array $person Submitted identity. @param string $requestId Retry identity. @param bool $trusted Trusted server flag. @return array Public result fixture. */
    public function book(int $id, array $person, string $requestId, bool $trustedIdentity = false)
    {
        $this->calls[] = ['method' => 'book', 'id' => $id, 'person' => $person, 'requestId' => $requestId, 'trusted' => $trustedIdentity];
        return $this->result;
    }

    /** @param int $id Booking ID. @param string $token Purpose-scoped token. @return array Public fixture. */
    public function verify(int $id, string $token) { $this->calls[] = ['method' => 'verify', 'id' => $id, 'token' => $token]; return $this->result; }
    /** @param int $id Booking ID. @param string $reason Cancellation reason. @param bool $manager Privileged path. @param string $token Secret. @return array Public fixture. */
    public function cancel(int $id, string $reason = 'admin', bool $manager = true, string $token = '')
    {
        $this->calls[] = ['method' => 'cancel', 'id' => $id, 'reason' => $reason, 'manager' => $manager, 'token' => $token];
        return $this->result;
    }
}

/** Injects server identity while keeping actual public controller decisions intact. */
class FrontendAdapter extends Frontend
{
    public array $serverIdentity = ['authenticated' => false, 'available' => false, 'participant' => []];
    /** @return array Simulated IdM state; never sourced from request parameters. */
    protected function identity(): array { return $this->serverIdentity; }
}

/** @param bool $condition Expected condition. @param string $message Failure context. @return void */
function frontendAssert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$repository = new FrontendRepository();
$service = new FrontendService($repository);
$frontend = new FrontendAdapter($repository, $service);
$request = new WP_REST_Request(['id' => 12]);
$response = $frontend->occurrences($request);
frontendAssert($response instanceof WP_REST_Response && count($response->data['occurrences']) === 1, 'Only published future occurrences may be public.');
$date = $response->data['occurrences'][0];
frontendAssert(!isset($date['guest_email'], $date['token'], $date['confirmed'], $date['pending']), 'Public availability must whitelist fields even if a service DTO grows.');
frontendAssert($date['free'] === 2 && $date['message'] === '2 places available', 'Capacity and plural label must agree.');
frontendAssert($response->headers['Cache-Control'] === 'no-store, private', 'Availability must never be cached.');
frontendAssert(strpos($date['label'], '2026-10-25, 02:30 – 03:30') !== false, 'UTC times across the DST boundary use the site timezone.');
$repository->course['post_status'] = 'draft';
frontendAssert($frontend->occurrences($request) instanceof WP_Error, 'Draft courses cannot leak occurrence details.');
$repository->course['post_status'] = 'publish';
foreach (['full', 'closed', 'cancelled', 'unsupported', 'unpublished'] as $reason) {
    $service->reason = $reason;
    frontendAssert(!$frontend->occurrences($request)->data['occurrences'][0]['bookable'], 'Unavailable reasons remain unbookable.');
}

$body = ['occurrenceId' => 42, 'requestId' => 'same-request-123456', 'trustedIdentity' => true,
    'participant' => ['firstname' => 'Posted', 'lastname' => 'Name', 'email' => 'posted@example.invalid', 'consent' => true, 'trustedIdentity' => true]];
$response = $frontend->book(new WP_REST_Request($body));
$call = end($service->calls);
frontendAssert($call['trusted'] === false && $call['person']['email'] === 'posted@example.invalid', 'Posted identity flags cannot impersonate SSO.');
frontendAssert($call['requestId'] === $body['requestId'], 'Retry IDs must reach the service unchanged.');
frontendAssert(!isset($response->data['guest_email'], $response->data['verify_token']), 'Booking responses must not expose participant metadata or tokens.');
frontendAssert($response->status === 201, 'A newly committed registration returns 201.');
$service->result['created'] = false;
frontendAssert($frontend->book(new WP_REST_Request($body))->status === 200, 'Idempotent replay returns 200.');

$repository->course['settings']['access'] = 'sso';
$before = count($service->calls);
frontendAssert($frontend->book(new WP_REST_Request($body)) instanceof WP_Error && count($service->calls) === $before,
    'Missing SSO on an SSO-required course cannot fall back to guest registration.');
$frontend->serverIdentity = ['authenticated' => true, 'available' => true,
    'participant' => ['firstname' => 'Server', 'lastname' => 'Profile', 'email' => 'verified@example.invalid']];
$frontend->book(new WP_REST_Request($body));
$call = end($service->calls);
frontendAssert($call['trusted'] && $call['person']['email'] === 'verified@example.invalid' && $call['person']['firstname'] === 'Server',
    'Authenticated identity must come entirely from the server.');
$body['participant']['consent'] = 'yes';
$frontend->book(new WP_REST_Request($body));
frontendAssert(end($service->calls)['person']['consent'] === false, 'Consent must be explicit JSON true.');
$frontend->serverIdentity['participant']['email'] = 'no@email';
frontendAssert($frontend->book(new WP_REST_Request($body)) instanceof WP_Error, 'Invalid IdM fallback emails may not be treated as verified identities.');

frontendAssert($frontend->bookingPermission(new WP_REST_Request([], ['X-RSVP-Nonce' => 'valid-form-nonce', 'Origin' => 'https://courses.example.test'])) === true,
    'The valid same-origin form nonce is accepted.');
frontendAssert($frontend->bookingPermission(new WP_REST_Request()) instanceof WP_Error, 'Registrations require the public form nonce.');
frontendAssert($frontend->bookingPermission(new WP_REST_Request([], ['X-RSVP-Nonce' => 'valid-form-nonce', 'Origin' => 'https://evil.example'])) instanceof WP_Error,
    'A stolen nonce cannot authorize a browser request from another origin.');
frontendAssert($frontend->tokenPermission(new WP_REST_Request()) === true, 'Email action tokens do not require a WordPress login nonce.');
frontendAssert($frontend->tokenPermission(new WP_REST_Request([], ['Origin' => 'http://courses.example.test'])) instanceof WP_Error, 'Scheme downgrade is a different origin.');
frontendAssert($frontend->editorPermission() instanceof WP_Error, 'Anonymous visitors cannot list draft course labels.');
$GLOBALS['frontend_test_capabilities']['edit_rsvp_courses'] = true;
frontendAssert($frontend->editorPermission() === true, 'Course editors can access the selector.');

$frontend->verify(new WP_REST_Request(['bookingId' => 71, 'token' => 'verify-secret']));
frontendAssert(end($service->calls)['method'] === 'verify', 'Verification always delegates to the shared service.');
$frontend->cancel(new WP_REST_Request(['bookingId' => 71, 'token' => 'cancel-secret', 'manager' => true, 'reason' => 'admin']));
$call = end($service->calls);
frontendAssert($call['manager'] === false && $call['reason'] === 'customer' && $call['token'] === 'cancel-secret', 'Public cancellation cannot select the administrative bypass.');
$frontend->registerRoutes();
foreach (['/course-bookings', '/course-bookings/verify', '/course-bookings/cancel'] as $path) {
    frontendAssert($GLOBALS['frontend_test_routes'][$path]['methods'] === 'POST', 'Mutations must never be registered as GET.');
}
echo "Course frontend privacy, identity, permissions, retry and timezone tests passed.\n";
