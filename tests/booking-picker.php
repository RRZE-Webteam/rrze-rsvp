<?php
/** Standalone rendering and AJAX regression tests for the room booking picker. */
declare(strict_types=1);

namespace RRZE\RSVP {
    /** Availability boundary double; rendering must use the same authoritative data. */
    class Functions
    {
        public static array $availability = [];
        public static array $calls = [];
        /** @param mixed ...$args Query arguments. @return array Fixture availability. */
        public static function getRoomAvailability(...$args): array
        {
            self::$calls[] = $args;
            return self::$availability;
        }
    }
}
namespace RRZE\RSVP\Shortcodes {
    /** No initialization is needed to exercise the renderer in isolation. */
    class Shortcodes {}
}
namespace {
    define('ABSPATH', dirname(__DIR__) . '/');
    /** @param string $text Message. @param string $domain Domain. @return string Untranslated message. */
    function __($text, $domain) { return $text; }
    /** @param mixed $text Attribute/text value. @return string HTML-safe value. */
    function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
    /** @param mixed $text Text value. @return string HTML-safe value. */
    function esc_html($text) { return esc_attr($text); }
    /** @param string $text Message. @param string $domain Domain. @return string Escaped translation. */
    function esc_html__($text, $domain) { return esc_html($text); }
    /** @param string $text Message. @param string $domain Domain. @return string Escaped translation. */
    function esc_attr__($text, $domain) { return esc_attr($text); }
    /** @return DateTimeZone Site timezone. */
    function wp_timezone() { return new DateTimeZone('Europe/Berlin'); }
    /** @return DateTimeImmutable Stable site-local current date. */
    function current_datetime() { return new DateTimeImmutable('2026-10-29 12:00:00', wp_timezone()); }
    /** @param string $format Date format. @param int $stamp UTC timestamp. @return string Site-local date. */
    function wp_date($format, $stamp) { return (new DateTimeImmutable('@' . $stamp))->setTimezone(wp_timezone())->format($format); }
    /** @param mixed $a Actual value. @param mixed $b Expected value. @param bool $echo Unused. @return string Checked attribute. */
    function checked($a, $b, $echo = false) { return (string) $a === (string) $b ? ' checked="checked"' : ''; }
    /** @param mixed $a Actual value. @param mixed $b Expected value. @param bool $echo Unused. @return string Selected attribute. */
    function selected($a, $b, $echo = false) { return str_replace('checked', 'selected', checked($a, $b, $echo)); }
    /** @param string $text Label. @return string Predictable ID suffix. */
    function sanitize_title($text) { return str_replace(':', '-', (string) $text); }
    /** @param mixed $text Request value. @return string Sanitized scalar. */
    function sanitize_text_field($text) { return is_scalar($text) ? trim(strip_tags((string) $text)) : ''; }
    /** @param mixed $value Request value. @return mixed Unchanged fixture. */
    function wp_unslash($value) { return $value; }
    /** @param mixed $value ID. @return int Positive ID. */
    function absint($value) { return abs((int) $value); }
    /** @param int $id Post ID. @return string Fixture title, including unsafe characters. */
    function get_the_title($id) { return $id === 80 ? 'A80 <script>alert("seat")</script>' : 'A' . $id; }
    /** @param mixed ...$args Query arguments. @return string Fixture booking mode. */
    function get_post_meta(...$args) { return $GLOBALS['mode'] ?? 'reservation'; }
    /** @param mixed ...$args Query arguments. @return false No equipment fixture. */
    function get_the_terms(...$args) { return false; }
    /** @param mixed ...$args Nonce check arguments. @return void */
    function check_ajax_referer(...$args) {}
    /** Captures a JSON endpoint without terminating the test process. */
    class JsonResponse extends RuntimeException
    {
        public array $data;
        /** @param array $data Endpoint response. */
        public function __construct(array $data) { $this->data = $data; parent::__construct('JSON response'); }
    }
    /** @param array $data Endpoint response. @return never */
    function wp_send_json($data) { throw new JsonResponse($data); }
    /** @param mixed ...$args Error response arguments. @return never */
    function wp_die(...$args) { throw new RuntimeException('HTTP ' . ($args[2]['response'] ?? 200)); }
    /** @param bool $condition Assertion result. @param string $message Failure context. @return void */
    function expect($condition, $message) { if (!$condition) throw new RuntimeException($message); }
    /** @param string $html HTML fragment. @return DOMXPath Parsed document query. */
    function markup($html) {
        $dom = new DOMDocument();
        @$dom->loadHTML('<meta charset="utf-8">' . $html);
        return new DOMXPath($dom);
    }

    require dirname(__DIR__) . '/includes/Shortcodes/Bookings.php';
    $reflection = new ReflectionClass(RRZE\RSVP\Shortcodes\Bookings::class);
    $picker = $reflection->newInstanceWithoutConstructor();
    RRZE\RSVP\Functions::$availability = ['2026-10-30' => ['09:00-09:30' => [1, 2], '10:00-10:30' => []]];
    $calendar = markup($picker->buildCalendar(10, 2026, '2026-10-29', '2026-10-31', 7, '2026-10-30'));
    expect($calendar->query('//button[@disabled]')->length === 2, 'Neither adjacent month is inside the booking horizon.');
    expect($calendar->query('//input[@name="rsvp_date"]')->length === 1, 'Only days with free seats are selectable.');
    expect($calendar->query('//input[@checked and @value="2026-10-30"]')->length === 1, 'Valid deep links retain the date.');
    expect(str_contains($calendar->query('//input')->item(0)->getAttribute('aria-label'), 'Friday, 30. October 2026'), 'Date accessible names include a complete date.');
    foreach ($calendar->query('//tbody/tr') as $row) expect($row->childNodes->length === 7, 'Every week has seven cells.');
    expect(RRZE\RSVP\Functions::$calls[0][1] === '2026-10-29' && RRZE\RSVP\Functions::$calls[0][2] === '2026-10-31', 'Query only the visible part of the booking window.');

    $first = markup($picker->buildCalendar(11, 2026, '2026-11-01', '2026-11-30', 7));
    expect($first->query('//button[@disabled]')->length === 2, 'Exact month boundaries must not expose adjacent months.');
    $leap = markup($picker->buildCalendar(2, 2028, '2028-02-01', '2028-03-01', 7));
    expect($leap->query('//td[contains(@class,"rsvp-calendar-day")]')->length === 29, 'Leap day remains in the grid.');
    expect($leap->query('//button[@data-direction="next" and not(@disabled)]')->length === 1, 'One bookable day next month enables navigation.');
    $clamped = $picker->buildCalendar(1, 2020, '2026-10-29', '2026-11-01', 7);
    expect(str_contains($clamped, 'data-period="2026-10"'), 'Out-of-range months are clamped.');

    $times = $reflection->getMethod('buildTimeslotSelect');
    $html = $times->invoke($picker, 7, '2026-10-30', '10:00', RRZE\RSVP\Functions::$availability);
    expect(markup($html)->query('//input')->length === 1, 'Sold-out intervals are not offered.');
    expect(!str_contains($html, 'checked='), 'A stale selected interval is cleared.');
    expect(str_contains($times->invoke($picker, 7, '', false, []), 'Select an available day'), 'No selected date produces helpful guidance.');
    expect(str_contains($times->invoke($picker, 7, '2026-10-31', false, []), 'No times are available'), 'An empty selected day has a contextual message.');

    $seats = $reflection->getMethod('buildSeatSelect');
    $many = ['2026-10-30' => ['09:00-09:30' => range(1, 80)]];
    $html = $seats->invoke($picker, 7, '2026-10-30', '09:00', 80, $many);
    $choices = markup($html);
    expect($choices->query('//select[@name="rsvp_seat" and @required]/option')->length === 81, 'Large rooms use a compact select with an empty prompt.');
    expect($choices->query('//option[@selected]')->item(0)->getAttribute('value') === '80', 'Large-room deep links retain their seat.');
    expect(strpos($html, '>A2<') < strpos($html, '>A10<'), 'Seat labels use natural ordering.');
    expect(!str_contains($html, '<script>') && str_contains($html, '&lt;script&gt;'), 'Seat names are escaped.');
    $small = markup($seats->invoke($picker, 7, '2026-10-30', '09:00', 2, RRZE\RSVP\Functions::$availability));
    expect($small->query('//input[@name="rsvp_seat"]')->length === 2, 'Small rooms use direct seat choices.');
    expect($small->query('//input[@checked]')->item(0)->getAttribute('value') === '2', 'Valid seat selection is retained.');

    $_POST = ['room' => 7, 'date' => '2026-02-30'];
    RRZE\RSVP\Functions::$calls = [];
    try { $picker->ajaxUpdateForm(); } catch (JsonResponse $response) {
        expect(str_contains($response->data['time'], 'Select an available day'), 'Invalid AJAX dates return the unselected state.');
        expect(!RRZE\RSVP\Functions::$calls, 'Invalid dates must not reach availability queries.');
    }
    $_POST = ['room' => 7, 'date' => '2026-10-30', 'time' => '09:00', 'seat' => 2];
    $GLOBALS['mode'] = 'consultation';
    try { $picker->ajaxUpdateForm(); } catch (JsonResponse $response) {
        expect(!isset($response->data['seat']), 'Consultation mode has no public seat-selection step.');
        expect(str_contains($response->data['time'], 'checked='), 'Consultation time selection is preserved.');
    }
    $_POST = ['room' => 7, 'month' => '2026-13', 'direction' => 'next', 'end' => '2026-12-31'];
    try { $picker->ajaxUpdateCalendar(); throw new RuntimeException('Invalid month was accepted.'); }
    catch (RuntimeException $error) { expect($error->getMessage() === 'HTTP 400', 'Malformed month navigation is rejected.'); }
    $_POST = ['room' => 7, 'month' => '2026-12', 'direction' => 'next', 'end' => '2027-01-31'];
    ob_start();
    try { $picker->ajaxUpdateCalendar(); } catch (RuntimeException $error) {
        expect($error->getMessage() === 'HTTP 200', 'Valid navigation returns calendar HTML.');
    }
    $january = ob_get_clean();
    expect(str_contains($january, 'data-period="2027-01"'), 'December navigation crosses the year boundary.');
    expect(str_contains($january, 'No available appointments this month.'), 'Empty months explain their lack of choices.');
    echo "booking-picker: all checks passed\n";
}
