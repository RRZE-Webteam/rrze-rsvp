<?php
/** Isolated WordPress and authentication boundaries for public room-access tests. */
declare(strict_types=1);

namespace RRZE\RSVP\Access {
    /** Authorization collaborator whose decisions and calls are controlled by each scenario. */
    final class RoomAccess
    {
        public static bool $enabled = true;
        public static ?\Closure $decision = null;
        public static array $calls = [];
        public static ?array $department = null;
        public static int $resolved = 0;

        /** @return bool Whether policy enforcement is enabled in this fixture. */
        public static function isEnabled(): bool { return self::$enabled; }

        /** @param int $room Room ID. @param array|null $identity Verified adapter identity. @return true|\WP_Error Decision. */
        public static function authorize(int $room, ?array $identity): true|\WP_Error
        {
            self::$calls[] = [$room, $identity];
            return self::$enabled && self::$decision ? (self::$decision)($room, $identity) : true;
        }

        /**
         * @param int $room Room ID.
         * @param array|null $identity Verified profile.
         * @return array|\WP_Error One final authorization and assignment decision.
         */
        public static function resolve(int $room, ?array $identity): array|\WP_Error
        {
            self::$resolved++;
            $access = self::authorize($room, $identity);
            return $access instanceof \WP_Error ? $access : ['department' => self::$enabled ? self::$department : null];
        }
    }

    /** Snapshot persistence boundary used to verify the public writer's atomic publication ordering. */
    final class BookingDepartment
    {
        public const META_KEY = '_rrze_rsvp_booking_department';
        public static bool $fail = false;
        public static array $snapshots = [];

        /**
         * @param int $id New booking.
         * @param int $room Authorized room.
         * @param array|null $department Resolved snapshot.
         * @return true|\WP_Error Persisted assignment or simulated failure.
         */
        public static function saveNew(int $id, int $room, ?array $department): true|\WP_Error
        {
            if (self::$fail) return new \WP_Error('booking_department_save_failed');
            if ($department !== null) self::$snapshots[$id] = ['room' => $room, 'department' => $department];
            return true;
        }
    }
}

namespace RRZE\RSVP\Auth {
    /** SSO boundary double, deliberately independent of WordPress login and request fields. */
    final class IdM
    {
        public static bool $authenticated = false;
        public static string $email = 'allowed@example.invalid';
        public mixed $simplesamlAuth = null;

        /** @return bool Fixture authentication state. */
        public function isAuthenticated(): bool { return self::$authenticated; }
        /** @return array<string,string> Server-supplied profile. */
        public function getCustomerData(): array
        {
            return ['customer_email' => self::$email, 'customer_firstname' => 'Trusted', 'customer_lastname' => 'SSO'];
        }
        /** @param string $url Return URL. @return void Record no external side effect. */
        public function logout(string $url = ''): void {}
    }

    /** Directory identity double with its own independent authenticated mailbox. */
    final class LDAP
    {
        public static bool $authenticated = false;
        public static string $email = 'allowed@example.invalid';

        /** @return bool Fixture directory state. */
        public function isAuthenticated(): bool { return self::$authenticated; }
        /** @return array{customer_email:string} Server-supplied directory mailbox. */
        public function getCustomerData(): array { return ['customer_email' => self::$email]; }
        /** @return void Keep the fixture's identity available for subsequent assertions. */
        public function logout(): void {}
    }
}

namespace RRZE\RSVP\Blocks {
    /** Minimal bound-block context; structural context validation has separate integration coverage. */
    final class Context
    {
        public string $mode = 'seat';
        public int $resourceId = 10;

        /** @param array $input Request fields. @return self|null Existing block identity or legacy form. */
        public static function fromRequest(array $input): ?self
        {
            return isset($input['rsvp_instance']) ? new self() : null;
        }
        /** @param self $other Request identity. @return bool Fixture identity match. */
        public function matches(self $other): bool { return true; }
        /** @param string $html Rendered content. @return string Unchanged fixture markup. */
        public function scopeHtml(string $html): string { return $html; }
        /** @return string Server-generated nonce purpose. */
        public function action(): string { return 'fixture-block'; }
        /** @param int $id Booking ID. @return string Receipt nonce purpose. */
        public function receiptAction(int $id): string { return 'receipt-' . $id; }
        /** @param array $parameters Addressed result fields. @return string Local return URL. */
        public function url(array $parameters = []): string { return \add_query_arg($parameters, 'https://example.invalid/form'); }
        /** @return string Hidden context marker. */
        public function hiddenFields(): string { return '<input name="rsvp_instance" value="fixture">'; }
    }
}

namespace RRZE\RSVP\Shortcodes {
    /** Passive parent boundary for exercising the actual room adapter. */
    class Shortcodes
    {
        /** @param array $atts Attributes. @param string $tag Shortcode. @param mixed $settings Configuration. @return array Resolved attributes. */
        public static function shortcodeAtts(array $atts, string $tag, mixed $settings): array { return $atts + ['days' => '']; }
        /** @param bool $sso SSO requirement. @param bool $ldap LDAP requirement. @return string Login form marker. */
        protected function authForm(bool $sso, bool $ldap): string { return '<p>Sign in to continue.</p>'; }
    }
}

namespace RRZE\RSVP {
    /** Existing booking-data behavior; access-list cryptography is tested by its own suite. */
    final class Functions
    {
        public const SEAT_RESERVING_STATUSES = ['booked', 'confirmed', 'checked-in'];
        /** @param mixed $value Checkbox value. @return bool Enabled state. */
        public static function getBoolValueFromAtt(mixed $value): bool { return in_array($value, ['on', 'yes', true, 1], true); }
        /** @param string $value Date. @return bool Valid fixture date shape. */
        public static function validateDate(string $value): bool { return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value); }
        /** @param string $value Time. @param string $format Format. @return bool Valid fixture time shape. */
        public static function validateTime(string $value, string $format): bool { return (bool) preg_match('/^\d{2}:\d{2}$/D', $value); }
        /** @param string $value Guest detail. @param string $action Operation. @return string Deterministic fixture ciphertext/plaintext. */
        public static function crypt(string $value, string $action = 'encrypt'): string
        {
            return $action === 'decrypt' ? (string) base64_decode(substr($value, 4)) : 'enc:' . base64_encode($value);
        }
    }

    /** Availability boundary with a hook for revocation between initial validation and persistence. */
    final class RoomSlots
    {
        public static bool $stopRendering = false;
        public static bool $flexible = false;
        public static ?\Closure $duringAvailability = null;
        /** @param int $room Room ID. @return array|null Flexible booking rule. */
        public static function rule(int $room): ?array { return self::$flexible ? ['min' => 30, 'max' => 60, 'step' => 30] : null; }
        /** @param mixed ...$args Availability input. @return array Current available slot. */
        public static function available(...$args): array
        {
            if (self::$stopRendering) throw new \RoomAccessReachedForm();
            if (self::$duringAvailability) (self::$duringAvailability)();
            return ['slots' => ['2026-11-19' => ['09:00-10:00' => [20]]], 'longest' => 60];
        }
        /** @param array $slots Choices. @param string $start Time. @param int $seat Seat ID. @return string Authoritative end. */
        public static function end(array $slots, string $start, int $seat): string { return '10:00'; }
        /** @param int $room Room ID. @return bool Instant reservations are enabled in this fixture. */
        public static function instantAllowed(int $room): bool { return true; }
        /** @param mixed $duration Posted duration. @return int|null Parsed fixture duration. */
        public static function minutes(mixed $duration): ?int { return $duration === '' ? null : (int) $duration; }
        /** @param int $start Start. @param int $end End. @return array Additional query predicates. */
        public static function overlap(int $start, int $end): array { return []; }
    }

    /** Supplies only the LDAP setting accessed by the public adapter. */
    final class RoomAccessSettingsFixture
    {
        /** @param string $section Section. @param string $name Setting. @return string Configured directory server. */
        public function getOption(string $section, string $name): string { return 'ldap://example.invalid'; }
    }

    /** Mail delivery counter: rejected bookings must never reach it. */
    final class Email
    {
        public static int $sent = 0;
        /** @param mixed ...$args Notification arguments. @return void Count attempted notifications. */
        public function doEmail(...$args): void { self::$sent++; }
    }

    /** Collects validation errors only in process memory. */
    final class TransientData
    {
        private array $errors = [];
        /** @param string $id Ephemeral error identifier. */
        public function __construct(private string $id) {}
        /** @param string $field Invalid field. @param mixed $error Validation detail. @return void */
        public function addData(string $field, mixed $error): void { $this->errors[$field] = $error; }
        /** @param bool $delete Consume flag. @return array Validation details. */
        public function getData(bool $delete = true): array { return $this->errors; }
        /** @return string Error identifier. */
        public function getTransient(): string { return $this->id; }
    }
}

namespace {
    define('ABSPATH', dirname(__DIR__, 2) . '/');
    define('DB_NAME', 'room-access-fixture');
    define('CORONA_MODE', false);

    /** WordPress failure boundary retaining a private diagnostic for disclosure checks. */
    final class WP_Error
    {
        /** @param string $code Error identifier. @param string $message Private diagnostic. @param mixed $data Metadata. */
        public function __construct(private string $code, public string $message = '', public mixed $data = null) {}
        /** @return string Stable error identifier. */
        public function get_error_code(): string { return $this->code; }
    }

    /** Captures terminal HTTP responses without terminating the test process. */
    final class RoomAccessResponse extends RuntimeException
    {
        /** @param string $body Message or redirect URL. @param int $status HTTP status. */
        public function __construct(public string $body, public int $status) { parent::__construct($body); }
    }
    /** Marks that a permitted renderer has reached the availability boundary. */
    final class RoomAccessReachedForm extends RuntimeException {}
    /** Simulates the connection-scoped day lock without a database. */
    final class RoomAccessDatabaseFixture
    {
        public string $prefix = 'wp_';
        public int $released = 0;
        /** @param string $query Query. @param mixed ...$args Bound values. @return string Query marker. */
        public function prepare(string $query, ...$args): string { return $query; }
        /** @param string $query Lock query. @return string Successful lock operation. */
        public function get_var(string $query): string
        {
            if (str_contains($query, 'RELEASE_LOCK')) $this->released++;
            return '1';
        }
    }

    /** @param mixed $value Result. @return bool Whether it is a WordPress error. */
    function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
    /** @param string $text Translation key. @param string $domain Textdomain. @return string Untranslated key. */
    function __(string $text, string $domain = ''): string { return $text; }
    /** @param mixed $text Text. @return string Escaped text. */
    function esc_html(mixed $text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
    /** @param string $text Translation key. @param string $domain Domain. @return string Escaped translation. */
    function esc_html__(string $text, string $domain = ''): string { return esc_html($text); }
    /** @param mixed $text Attribute value. @return string Escaped value. */
    function esc_attr(mixed $text): string { return esc_html($text); }
    /** @param string $url URL. @return string Escaped URL. */
    function esc_url(string $url): string { return esc_html($url); }
    /** @param mixed $value Request field. @return int Positive integer. */
    function absint(mixed $value): int { return abs((int) $value); }
    /** @param mixed $value Slashed value. @return mixed Fixture value. */
    function wp_unslash(mixed $value): mixed { return $value; }
    /** @param mixed $value Database value. @return mixed Fixture value. */
    function wp_slash(mixed $value): mixed { return $value; }
    /** @param string $value Text field. @return string Cleaned text. */
    function wp_strip_all_tags(string $value): string { return strip_tags($value); }
    /** @param string $value Text field. @return string Cleaned scalar. */
    function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
    /** @param string $value Multiline field. @return string Cleaned scalar. */
    function sanitize_textarea_field(string $value): string { return sanitize_text_field($value); }
    /** @param string $value Email input. @return string Cleaned email. */
    function sanitize_email(string $value): string { return trim($value); }
    /** @param string $nonce Received nonce. @param string $action Purpose. @return bool Fixture verification. */
    function wp_verify_nonce(string $nonce, string $action): bool { return $nonce === 'valid-' . $action; }
    /** @param string $action Purpose. @return string Fixture nonce. */
    function wp_create_nonce(string $action): string { return 'valid-' . $action; }
    /** @param mixed ...$args AJAX nonce arguments. @return void Nonce coverage belongs to the routing suite. */
    function check_ajax_referer(...$args): void {}
    /** @param mixed $message Public message. @param string $title Page title. @param array $args Status arguments. @return never */
    function wp_die(mixed $message = '', string $title = '', array $args = []): never
    {
        throw new RoomAccessResponse((string) $message, $args['response'] ?? 200);
    }
    /** @param string $url Local target. @return never Capture a successful booking redirect. */
    function wp_safe_redirect(string $url): never { throw new RoomAccessResponse($url, 302); }
    /** @param string $handle Stylesheet. @return void No renderer side effect. */
    function wp_enqueue_style(string $handle): void {}
    /** @param string $handle Script. @return void No renderer side effect. */
    function wp_enqueue_script(string $handle): void {}
    /** @param int $id Resource ID. @return object|null Fixture resource. */
    function get_post(int $id): ?object { return $id > 0 ? (object) ['ID' => $id] : null; }
    /** @param int $id Resource ID. @return string Fixture resource type. */
    function get_post_type(int $id): string { return $id === 20 ? 'seat' : 'room'; }
    /** @param int $id Resource ID. @return string Published resource. */
    function get_post_status(int $id): string { return 'publish'; }
    /** @param int $id Resource ID. @param string $key Meta field. @param bool $single Single-value flag. @return mixed Fixture metadata. */
    function get_post_meta(int $id, string $key = '', bool $single = false): mixed
    {
        return $key === '' ? [] : ($GLOBALS['room_access_meta'][$id][$key] ?? '');
    }
    /** @param array $args Query. @return array Consultation seat or no conflicting bookings. */
    function get_posts(array $args): array { return ($args['post_type'] ?? '') === 'seat' ? [(object) ['ID' => 20]] : []; }
    /** @param array $post New reservation. @param bool $error Error flag. @return int Fixture post ID. */
    function wp_insert_post(array $post, bool $error = false): int
    {
        $GLOBALS['room_access_inserts'][] = $post;
        $id = 100 + count($GLOBALS['room_access_inserts']);
        $GLOBALS['room_access_meta'][$id] = array_map('strval', $post['meta_input']);
        return $id;
    }
    /**
     * @param array $post Publication data.
     * @param bool $error Error flag.
     * @return int Existing ID.
     */
    function wp_update_post(array $post, bool $error = false): int
    {
        $GLOBALS['room_access_publications'][] = $post;
        return $post['ID'];
    }
    /**
     * @param int $id Post ID.
     * @param bool $force Permanent deletion flag.
     * @return void Fixture rollback.
     */
    function wp_delete_post(int $id, bool $force = false): void
    {
        $GLOBALS['room_access_deletions'][] = $id;
        unset($GLOBALS['room_access_meta'][$id]);
    }
    /** @return DateTimeImmutable Fixed local date. */
    function current_datetime(): DateTimeImmutable { return new DateTimeImmutable('2026-11-18 12:00:00 UTC'); }
    /** @param string $format Date format or timestamp. @return int|string Fixed fixture clock. */
    function current_time(string $format): int|string { return $format === 'timestamp' ? current_datetime()->getTimestamp() : current_datetime()->format($format); }
    /** @param int|null $id Resource ID. @return string Local form/permalink. */
    function get_permalink(?int $id = null): string { return 'https://example.invalid/form'; }
    /** @return string Form referrer. */
    function wp_get_referer(): string { return get_permalink(); }
    /** @param string $url URL. @param int $component Desired component. @return mixed Parsed URL component. */
    function wp_parse_url(string $url, int $component = -1): mixed { return parse_url($url, $component); }
    /** @param array|string $args Parameters or key. @param mixed $value Value or URL. @param string $url URL. @return string Local URL. */
    function add_query_arg(array|string $args, mixed $value = '', string $url = ''): string
    {
        return is_array($args) ? $value . '?' . http_build_query($args) : $url . '?' . http_build_query([$args => $value]);
    }
}
