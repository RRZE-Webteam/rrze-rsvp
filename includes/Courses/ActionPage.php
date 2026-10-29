<?php

namespace RRZE\RSVP\Courses;

defined('ABSPATH') || exit;

/** Explicit, token-authorized POST actions; opening a mail link never changes a booking. */
final class ActionPage
{
    private const SLUG = 'rsvp-course-booking';
    private BookingService $service;

    /**
     * @param BookingService|null $service Optional transactional service for tests.
     */
    public function __construct(?BookingService $service = null)
    {
        $this->service = $service ?? new BookingService();
    }

    /** @return void Install a dedicated route before legacy room template selection. */
    public function onLoaded(): void
    {
        add_action('template_redirect', [$this, 'dispatch'], 0);
    }

    /**
     * Build a purpose-specific mail link, respecting the website's permalink setting.
     *
     * @param string $action verify or cancel.
     * @param int $bookingId Booking ID.
     * @param string $token Purpose-bound opaque token.
     * @return string Link containing no participant information.
     */
    public static function url(string $action = '', int $bookingId = 0, string $token = ''): string
    {
        $url = get_option('permalink_structure') ? home_url('/' . self::SLUG . '/') : add_query_arg(self::SLUG, '1', home_url('/'));
        return $action === '' ? $url : add_query_arg(['action' => $action, 'bookingId' => $bookingId, 'token' => $token], $url);
    }

    /** @return void Render the dedicated page with privacy headers and no redirect side effects. */
    public function dispatch(): void
    {
        global $wp, $wp_query;
        $plainRoute = isset($_GET[self::SLUG]) && is_string($_GET[self::SLUG]) && $_GET[self::SLUG] === '1';
        if (!$plainRoute && trim((string) ($wp->request ?? ''), '/') !== self::SLUG) {
            return;
        }
        nocache_headers();
        header('Cache-Control: no-store, private, max-age=0');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        status_header(200);
        $wp_query->is_404 = false;
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $input = $method === 'POST' ? wp_unslash($_POST) : wp_unslash($_GET);
        $content = $this->render($method, $input);
        // The route is deliberately independent of theme single-room/seat templates.
        get_header();
        echo '<main id="main-content" class="rrze-rsvp-course-action"><h1>' . esc_html__('Course booking', 'rrze-rsvp') . '</h1>';
        echo $content; // Escaped by render().
        echo '</main>';
        get_footer();
        exit;
    }

    /**
     * Render generic GET confirmation or execute a purpose-bound POST via the service.
     *
     * @param string $method HTTP request method.
     * @param array $input Unslashed GET or POST fields (never merged request/cookie data).
     * @return string Escaped accessible HTML without participant information.
     */
    public function render(string $method, array $input): string
    {
        $action = is_string($input['action'] ?? null) ? $input['action'] : '';
        $token = is_string($input['token'] ?? null) ? $input['token'] : '';
        $idValue = $input['bookingId'] ?? null;
        $bookingId = is_scalar($idValue) && ctype_digit((string) $idValue) ? (int) $idValue : 0;
        if (!in_array($method, ['GET', 'POST'], true) || !in_array($action, ['verify', 'cancel'], true)
            || $bookingId < 1 || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return $this->notice(__('This booking link is invalid. Please use the complete link from your email.', 'rrze-rsvp'), true);
        }
        if ($method === 'POST') {
            // The unpredictable, purpose-bound token authenticates guests; a WP nonce would not.
            $result = $action === 'verify' ? $this->service->verify($bookingId, $token)
                : $this->service->cancel($bookingId, 'customer', false, $token);
            if (is_wp_error($result)) {
                return $this->notice($result->get_error_message(), true);
            }
            if ($action === 'cancel') {
                return $this->notice(__('Your course booking has been cancelled.', 'rrze-rsvp'));
            }
            return $this->notice(($result['status'] ?? '') === 'confirmed'
                ? __('Your email address and your participation are confirmed.', 'rrze-rsvp')
                : __('Your email address is confirmed. Your booking is awaiting approval by the organizers.', 'rrze-rsvp'));
        }
        $description = $action === 'verify'
            ? __('Confirm your email address to complete this step of your course registration.', 'rrze-rsvp')
            : __('Cancel this course booking? Your reserved place will become available to other people.', 'rrze-rsvp');
        $button = $action === 'verify' ? __('Confirm email address', 'rrze-rsvp') : __('Cancel my booking', 'rrze-rsvp');
        return '<p>' . esc_html($description) . '</p><form method="post" action="' . esc_url(self::url()) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr($action) . '">'
            . '<input type="hidden" name="bookingId" value="' . esc_attr((string) $bookingId) . '">'
            . '<input type="hidden" name="token" value="' . esc_attr($token) . '">'
            . '<button type="submit">' . esc_html($button) . '</button></form>';
    }

    /**
     * @param string $message Human-readable result.
     * @param bool $error Whether the message describes an error.
     * @return string Accessible escaped notice.
     */
    private function notice(string $message, bool $error = false): string
    {
        return '<div role="' . ($error ? 'alert' : 'status') . '" tabindex="-1"><p>' . esc_html($message) . '</p></div>';
    }
}
