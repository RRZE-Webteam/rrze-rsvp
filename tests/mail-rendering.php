<?php

/** Standalone renderer and legacy mail-context regressions; no database or mail transport. */
declare(strict_types=1);

namespace RRZE\RSVP\Config {
    /** @return array Legacy note defaults. */
    function defaultOptions(): array { return ['room-notes-label' => 'Notes']; }
}

namespace RRZE\RSVP {
    /** Minimal read boundary for exercising the real legacy mail orchestration. */
    class Functions
    {
        public static array $booking = [];
        /**
         * @param int $id Booking ID.
         * @return bool Fixture is a normal room.
         */
        public static function isCourseBooking(int $id): bool { return false; }
        /**
         * @param int $id Booking ID.
         * @return array Private fixture.
         */
        public static function getBooking(int $id): array { return self::$booking; }
        /**
         * @param string $value Legacy flag.
         * @return bool Parsed flag.
         */
        public static function getBoolValueFromAtt($value): bool { return $value === 'on'; }
        /**
         * @param string $action Action.
         * @param string $key Booking key.
         * @param int $id Booking ID.
         * @return string Synthetic URL.
         */
        public static function bookingReplyUrl($action, $key, $id): string { return 'https://example.invalid/' . $action . '?id=' . $id . '&key=' . $key; }
    }
    /** Calendar boundary; the existing calendar suites cover actual serialization. */
    class ICS
    {
        /**
         * @param array $booking Booking.
         * @param string $filename File name.
         * @param string $context Copy context.
         * @return string Capturable calendar payload.
         */
        public static function generate($booking, $filename, $context = ''): string { return 'calendar:' . $context; }
    }
    /** @return object Template path provider. */
    function plugin(): object { return new class {
        /** @return string Plugin directory. */
        public function getDirectory(): string { return dirname(__DIR__) . '/'; }
    }; }
}

namespace {
    define('ABSPATH', dirname(__DIR__) . '/');
    /**
     * @param string $text Message.
     * @param string $domain Domain.
     * @return string Source translation.
     */
    function __($text, $domain) { return $text; }
    /**
     * @param string $text Message.
     * @param string $context Context.
     * @param string $domain Domain.
     * @return string Source translation.
     */
    function _x($text, $context, $domain) { return $text; }
    /**
     * @param mixed $text Plain value.
     * @return string Escaped HTML.
     */
    function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8', false); }
    /**
     * @param mixed $text Attribute value.
     * @return string Escaped attribute.
     */
    function esc_attr($text) { return esc_html($text); }
    /**
     * @param string $text Translation.
     * @param string $domain Domain.
     * @return string Escaped label.
     */
    function esc_html__($text, $domain) { return esc_html($text); }
    /**
     * @param string $url URL.
     * @return string Safe URL attribute.
     */
    function esc_url($url) { return preg_match('~^https?://~', $url) ? esc_attr($url) : ''; }
    /** @return string Active locale, distinct from site language. */
    function get_locale() { return $GLOBALS['mail_locale'] ?? 'en_US'; }
    /** @return bool Active text direction. */
    function is_rtl() { return get_locale() === 'ar'; }
    /**
     * @param string $key Setting.
     * @return string Site metadata.
     */
    function get_bloginfo($key) { return $key === 'name' ? '<Example & Site>' : 'de_DE'; }
    /**
     * @param string $path Path.
     * @return string Site URL.
     */
    function home_url($path = '') { return 'https://example.invalid' . $path; }
    /** @return string Existing site URL. */
    function site_url() { return home_url('/'); }
    /** @return bool Logo availability. */
    function has_custom_logo() { return isset($GLOBALS['mail_logo']); }
    /**
     * @param string $key Setting.
     * @return int Logo attachment ID.
     */
    function get_theme_mod($key) { return 1; }
    /**
     * @param int $id Attachment.
     * @param string $size Size.
     * @return array Logo metadata.
     */
    function wp_get_attachment_image_src($id, $size) { return $GLOBALS['mail_logo']; }
    /** @return string Optional configured privacy page. */
    function get_privacy_policy_url() { return $GLOBALS['mail_privacy'] ?? ''; }
    /**
     * @param string $path Slug.
     * @return object|null Existing legal page.
     */
    function get_page_by_path($path) { return $GLOBALS['mail_imprint'] ?? null; }
    /**
     * @param int $id Post.
     * @return string Public legal URL.
     */
    function get_permalink($id) { return 'https://example.invalid/impressum/'; }
    /**
     * @param string $text HTML.
     * @return string Fixture sanitizer preserving supported formatting.
     */
    function wp_kses_post($text) { return strip_tags($text, '<p><strong><em><br><a>'); }
    /**
     * @param string $text HTML.
     * @return string Text only.
     */
    function wp_strip_all_tags($text) { return strip_tags($text); }
    /**
     * @param string $text Rich text.
     * @return string Simple paragraph fixture.
     */
    function wpautop($text) { return '<p>' . $text . '</p>'; }
    /**
     * @param int $id Post.
     * @return array Room configuration.
     */
    function get_post_meta($id) { return $GLOBALS['mail_room_meta']; }
    /**
     * @param string $email Address.
     * @return bool Valid synthetic address.
     */
    function is_email($email) { return (bool) filter_var($email, FILTER_VALIDATE_EMAIL); }
    /**
     * @param string $text Title.
     * @return string File slug.
     */
    function sanitize_title($text) { return preg_replace('/[^a-z0-9]+/i', '-', $text); }
    /**
     * @param bool $condition Invariant.
     * @param string $message Failure description.
     * @return void
     */
    function mailExpect(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
    /**
     * @param string $html Markup.
     * @return DOMXPath Queryable rendered document.
     */
    function mailDom(string $html): DOMXPath {
        $dom = new DOMDocument();
        @$dom->loadHTML($html);
        return new DOMXPath($dom);
    }

    require ABSPATH . 'includes/Mail/EmailLayout.php';
    require ABSPATH . 'includes/Mail/RoomMessage.php';
    require ABSPATH . 'includes/Parser.php';
    require ABSPATH . 'includes/Template.php';
    require ABSPATH . 'includes/Email.php';
    require ABSPATH . 'includes/Courses/Notifications.php';
    require __DIR__ . '/fixtures/mail-data.php';

    use RRZE\RSVP\Mail\EmailLayout;
    use RRZE\RSVP\Mail\RoomMessage;

    $html = EmailLayout::wrap('<p>Trusted content</p>', '<Subject>', 'success');
    mailExpect(str_contains($html, 'lang="en-US"') && str_contains($html, '&lt;Subject&gt;') && str_contains($html, '&lt;Example &amp; Site&gt;'), 'Use active locale and escape metadata.');
    mailExpect(!str_contains($html, '___RRZE_EMAIL_') && substr_count(strtolower($html), '<!doctype html>') === 1, 'Wrap exactly once with every runtime marker substituted.');
    mailExpect(!str_contains($html, '/impressum/') && !str_contains($html, 'Privacy Policy'), 'Missing legal pages must not produce guessed footer links.');
    $GLOBALS['mail_locale'] = 'ar';
    mailExpect(str_contains(EmailLayout::wrap(''), 'dir="rtl"'), 'Active RTL locale sets the document direction.');
    $GLOBALS['mail_locale'] = 'en_US';
    foreach ([[1000, 200, 200, 40], [100, 1000, 6, 64], [80, 20, 80, 20]] as [$w, $h, $expectedW, $expectedH]) {
        $GLOBALS['mail_logo'] = ['https://example.invalid/logo.png', $w, $h];
        $dom = mailDom(EmailLayout::wrap(''));
        $logo = $dom->query('//img')->item(0);
        mailExpect((int) $logo->getAttribute('width') === $expectedW && (int) $logo->getAttribute('height') === $expectedH, 'Logo keeps bounded proportional dimensions.');
    }
    unset($GLOBALS['mail_logo']);
    $GLOBALS['mail_imprint'] = (object) ['ID' => 1, 'post_status' => 'draft'];
    mailExpect(!str_contains(EmailLayout::wrap(''), '/impressum/'), 'Do not expose draft legal pages.');
    $GLOBALS['mail_imprint']->post_status = 'publish';
    $GLOBALS['mail_privacy'] = 'https://example.invalid/privacy/';
    mailExpect(str_contains(EmailLayout::wrap(''), '/impressum/') && str_contains(EmailLayout::wrap(''), '/privacy/'), 'Existing legal pages appear in the footer.');
    $layout = file_get_contents(ABSPATH . 'build/email/layout.html');
    $valid = new ReflectionMethod(EmailLayout::class, 'hasRequiredLayoutMarkers');
    mailExpect($valid->invoke(null, $layout) && !$valid->invoke(null, str_replace('___RRZE_EMAIL_CONTENT___', '', $layout)), 'Incomplete build artifacts are rejected.');
    $fallback = (new ReflectionMethod(EmailLayout::class, 'getFallbackLayout'))->invoke(null);
    mailExpect($valid->invoke(null, $fallback), 'Fallback keeps the complete runtime contract.');
    foreach ([$layout, $fallback] as $shell) {
        mailExpect(str_contains($shell, '<!--[if mso]>') && str_contains($shell, '.rrze-email-button-cell') && str_contains($shell, '.rrze-email-button-link'), 'Compiled and fallback templates preserve Outlook-only runtime styles.');
    }
    $button = mailDom(EmailLayout::actionButton('https://example.invalid/action?a=1&b=2', '<Confirm>'));
    mailExpect($button->query('//a')->item(0)->getAttribute('href') === 'https://example.invalid/action?a=1&b=2', 'Action URL query parameters survive escaping.');
    mailExpect(!str_contains(EmailLayout::actionButton('javascript:alert(1)', 'Unsafe'), 'javascript:'), 'Unsafe URL schemes are never rendered.');
    mailExpect(EmailLayout::plainText('<p>A &amp; B<br>Second</p>') === "A & B\nSecond", 'Plain alternative has line breaks and decoded entities.');
    mailExpect(EmailLayout::plainText('<a href="https://example.invalid/help?a=1&amp;b=2">More information</a>')
        === 'More information (https://example.invalid/help?a=1&b=2)', 'Links embedded in configured rich text remain usable in plain text.');
    foreach (['booked' => 'warning', 'customer-confirmed' => 'warning', 'confirmed' => 'success', 'checked-in' => 'success', 'cancelled' => 'danger', 'checked-out' => 'neutral', 'other' => 'neutral'] as $state => $palette) {
        mailExpect(EmailLayout::bookingStatus($state)[0] === $palette && EmailLayout::bookingStatus($state)[1] !== '', 'Each status has a semantic palette and a visible label.');
    }
    $data = RRZE\RSVP\Tests\roomMailData();
    $data['room_name'] = '<Room & Test>';
    $data['notes'] = '<script>alert(1)</script>';
    $html = RoomMessage::render($data);
    mailExpect(substr_count(strtolower($html), '<!doctype html>') === 1 && str_contains($html, 'lang="en"') && str_contains($html, 'Please confirm your booking'), 'Bilingual messages share one shell with an English section.');
    mailExpect(!str_contains($html, $data['customer_email']) && !str_contains($html, '<script>') && str_contains($html, '&lt;Room &amp; Test&gt;'), 'Recipient visibility and escaping apply to runtime details.');
    $data['to_admin'] = true;
    $data['is_locale_not_english'] = false;
    $data['show_notes'] = false;
    $html = RoomMessage::render($data);
    mailExpect(str_contains($html, $data['customer_email']) && !str_contains($html, 'English version below') && !str_contains($html, 'alert(1)'), 'Admin details, note flag and single-language output remain distinct.');

    /** Capture the real legacy dispatcher without sending email or writing attachments. */
    class RoomMailCapture extends RRZE\RSVP\Email
    {
        public array $messages = [];
        /** Initialize only the dependencies needed by the dispatcher. */
        public function __construct() {
            $this->template = new RRZE\RSVP\Template();
            $this->isLocaleEnglish = false;
            $this->options = (object) ['email_notification_email' => 'admin@example.invalid'];
            foreach (['force_to_confirm', 'received', 'confirm', 'cancel'] as $kind) {
                foreach (['', '_en'] as $language) {
                    $this->options->{'email_' . $kind . '_subject' . $language} = $kind . ' {{room_name}}';
                    $this->options->{'email_' . $kind . '_text' . $language} = '<strong>{{guest_name}}</strong> &amp; {{notes}}';
                }
            }
        }
        /**
         * @param string $to Recipient.
         * @param string $subject Subject.
         * @param string $message HTML.
         * @param string $altMessage Text.
         * @param string $attachment Calendar.
         * @return bool Captured.
         */
        public function send(string $to, string $subject, string $message, string $altMessage = '', string $attachment = ''): bool {
            $this->messages[] = compact('to', 'subject', 'message', 'altMessage', 'attachment');
            return true;
        }
        /**
         * @param string $name Filename.
         * @param string $content Calendar.
         * @return string Virtual attachment path.
         */
        protected function tempFile(string $name, string $content) { return $name; }
    }
    RRZE\RSVP\Functions::$booking = [
        'room' => 7, 'status' => 'confirmed', 'start' => 1794466800, 'date' => '12.11.2026', 'date_en' => 'November 12, 2026',
        'time' => '09:00–10:00', 'time_en' => '9:00–10:00 am', 'room_name' => 'Room & One', 'seat_name' => 'A',
        'room_street' => 'Street & Square 1', 'room_zip' => '91054', 'room_city' => 'Erlangen',
        'guest_firstname' => '<Erika>', 'guest_lastname' => 'Example', 'guest_email' => 'guest@example.invalid', 'notes' => "Line one\nLine two & three",
    ];
    $GLOBALS['mail_room_meta'] = ['rrze-rsvp-room-bookingmode' => ['reservation'], 'rrze-rsvp-room-auto-confirmation' => ['off'],
        'rrze-rsvp-room-notes-check' => ['on'], 'rrze-rsvp-room-notes-label' => ['Notes']];
    foreach (['customerConfirmationRequired', 'customerConfirmed', 'adminConfirmationRequired', 'adminConfirmed', 'newBooking', 'bookingCancelled', 'bookingCheckedIn', 'bookingCheckedOut'] as $context) {
        foreach (['customer', 'admin'] as $recipient) {
            $mail = new RoomMailCapture();
            $mail->doEmail($context, $recipient, 42);
            mailExpect(count($mail->messages) === 1, 'Each legacy context still emits one intended message.');
            $sent = $mail->messages[0];
            mailExpect($sent['to'] === ($recipient === 'admin' ? 'admin@example.invalid' : 'guest@example.invalid'), 'Legacy recipient selection is unchanged.');
            mailExpect(substr_count(strtolower($sent['message']), '<!doctype html>') === 1 && !str_contains($sent['message'], '___RRZE_EMAIL_'), 'Every context uses the shared layout exactly once.');
            mailExpect(str_contains($sent['message'], 'Street &amp; Square 1') && str_contains($sent['altMessage'], "Street & Square 1\n91054 Erlangen"), 'Room address is present and context-escaped in both alternatives.');
            mailExpect(!str_contains($sent['message'], '<Erika>') && !str_contains($sent['altMessage'], '<strong>') && !str_contains($sent['altMessage'], '&amp;') && !str_contains($sent['altMessage'], '<br'), 'HTML and plain alternatives use separate escaping.');
            if ($context === 'bookingCancelled') mailExpect(!str_contains($sent['message'], 'rrze-email-button-link" href='), 'Cancelled room bookings show no obsolete actions.');
        }
    }
    $GLOBALS['mail_room_meta']['rrze-rsvp-room-send-to-email'] = ['copy@example.invalid'];
    $mail = new RoomMailCapture();
    $mail->doEmail('adminConfirmed', 'customer', 42);
    mailExpect(count($mail->messages) === 2 && $mail->messages[1]['to'] === 'copy@example.invalid' && str_ends_with($mail->messages[1]['attachment'], '-copy.ics'), 'Additional room notification keeps its recipient and ICS copy.');
    mailExpect(!str_contains($mail->messages[1]['message'], 'rrze-email-button-link" href='), 'Additional room notification has no participant action buttons.');
    mailExpect(!str_contains($mail->messages[1]['altMessage'], 'https://example.invalid/checkin')
        && !str_contains($mail->messages[1]['altMessage'], 'https://example.invalid/checkout'), 'The plain room copy follows the same action visibility as HTML.');
    unset($GLOBALS['mail_room_meta']['rrze-rsvp-room-send-to-email']);
    foreach (['no-check', 'consultation'] as $mode) {
        $GLOBALS['mail_room_meta']['rrze-rsvp-room-bookingmode'] = [$mode];
        $mail = new RoomMailCapture();
        $mail->doEmail('adminConfirmed', 'customer', 42);
        mailExpect(!str_contains($mail->messages[0]['message'], 'https://example.invalid/checkin')
            && !str_contains($mail->messages[0]['message'], 'https://example.invalid/checkout'), 'Modes without check-in must not gain check-in/out actions.');
    }
    RRZE\RSVP\Functions::$booking['notes'] = 'Literal {{date}} & notes';
    $mail = new RoomMailCapture();
    $mail->doEmail('customerConfirmationRequired', 'customer', 42);
    mailExpect(str_contains($mail->messages[0]['message'], 'Literal {{date}} &amp; notes')
        && str_contains($mail->messages[0]['altMessage'], 'Literal {{date}} & notes')
        && !str_contains($mail->messages[0]['altMessage'], "\f"), 'Participant text is never reinterpreted as a template or leaked as parser escapes.');
    $capture = new RoomMailCapture();
    $notifications = new RRZE\RSVP\Courses\Notifications($capture, []);
    $deliver = new ReflectionMethod($notifications, 'deliver');
    mailExpect($deliver->invoke($notifications, 'guest@example.invalid', '<Course & test>', ['Intro &amp; details'],
        [['Manage & review', 'https://example.invalid/action?a=1&b=2']],
        [['label' => 'Course', 'value' => '<Course &amp; test>', 'plain' => 'Course: <Course &amp; test>']], 'confirmed'), 'Structured course content renders through the shared transport boundary.');
    $course = $capture->messages[0];
    mailExpect(str_contains($course['message'], '&lt;Course &amp; test&gt;') && !str_contains($course['message'], '<Course'), 'Course details and subjects cannot introduce HTML.');
    mailExpect(str_contains($course['altMessage'], 'Course: <Course & test>')
        && str_contains($course['altMessage'], 'https://example.invalid/action?a=1&b=2')
        && !str_contains($course['altMessage'], '&amp;'), 'Course plain text preserves literal data and URLs without HTML entities.');
    echo "Mail renderer tests passed (layout, locale, fallback, escaping, room and course contexts, ICS copy).\n";
}
