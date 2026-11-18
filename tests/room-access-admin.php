<?php

/** Standalone tests for the room-permission editor's authorization and storage boundaries. */
declare(strict_types=1);

namespace RRZE\RSVP\Access {
    /** Storage boundary double; cryptography and domain policy have separate tests. */
    final class RoomAccess
    {
        public static array $calls = [];
        public static array $lists = [];
        public static ?\WP_Error $failure = null;

        /** @return bool Whether the site-local feature is enabled. */
        public static function isEnabled(): bool { return ($GLOBALS['access_options']['general_room_access_enabled'] ?? 'off') === 'on'; }

        /** @param int $roomId Room ID. @return array|\WP_Error Stored list or injected failure. */
        public static function read(int $roomId): array|\WP_Error { return self::$lists[$roomId] ?? []; }

        /** @param int $roomId Room ID. @param array $emails Untrusted input delegated by the controller. @return true|\WP_Error Save outcome. */
        public static function save(int $roomId, array $emails): true|\WP_Error
        {
            self::$calls[] = [$roomId, $emails];
            if (self::$failure) {
                return self::$failure;
            }
            self::$lists[$roomId] = $emails;
            return true;
        }
    }
}

namespace {
    define('ABSPATH', dirname(__DIR__) . '/');

    /** Minimal WordPress error contract. */
    class WP_Error
    {
        /** @param string $code Error identifier. @param string $message Safe user feedback. @param array $data Status metadata. */
        public function __construct(private string $code, private string $message = '', private array $data = []) {}
        /** @return string Error identifier. */
        public function get_error_code(): string { return $this->code; }
        /** @return string Error feedback. */
        public function get_error_message(): string { return $this->message; }
    }

    /** @param mixed $value Candidate. @return bool Whether this is an error. */
    function is_wp_error($value): bool { return $value instanceof WP_Error; }
    /** @param string $text Source text. @param string $domain Translation domain. @return string Source text. */
    function __(string $text, string $domain = ''): string { return $text; }
    /** @param string $text Source text. @param string $domain Translation domain. @return string Escaped source text. */
    function esc_html__(string $text, string $domain = ''): string { return esc_html($text); }
    /** @param mixed $value Text. @return string HTML-safe text. */
    function esc_html($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
    /** @param mixed $value Attribute value. @return string Escaped attribute. */
    function esc_attr($value): string { return esc_html($value); }
    /** @param mixed $value URL. @return string Escaped URL. */
    function esc_url($value): string { return esc_html($value); }
    /** @param string $value Textarea value. @return string Escaped value. */
    function esc_textarea(string $value): string { return esc_html($value); }
    /** @param string $capability Requested capability. @param int $id Optional object ID. @return bool Injected permission state. */
    function current_user_can(string $capability, int $id = 0): bool
    {
        return $capability === 'manage_options' ? $GLOBALS['access_manage'] : $GLOBALS['access_edit'];
    }
    /** @param int $id Object ID. @return object|null Current website's object. */
    function get_post(int $id): ?object { return $GLOBALS['access_rooms'][$id] ?? null; }
    /** @param array $args Query options. @return array Current website's editable rooms. */
    function get_posts(array $args): array { return array_values($GLOBALS['access_rooms']); }
    /** @param int $id Room ID. @param string $key Meta key. @param bool $single Single-value mode. @return mixed Fixture metadata. */
    function get_post_meta(int $id, string $key, bool $single = false) { return $GLOBALS['access_meta'][$id][$key] ?? ''; }
    /** @param string $name Option name. @param mixed $default Fallback. @return mixed Site-local options. */
    function get_option(string $name, $default = false) { return $name === 'rrze_rsvp' ? $GLOBALS['access_options'] : $default; }
    /** @param string $nonce Submitted nonce. @param string $action Expected action. @return bool Whether the nonce matches the room. */
    function wp_verify_nonce(string $nonce, string $action): bool { return $nonce === 'valid:' . $action; }
    /** @param string $path Relative admin path. @return string Local administration URL. */
    function admin_url(string $path = ''): string { return 'https://test.local/wp-admin/' . $path; }
    /** @param array $args Query parameters. @param string $url Base URL. @return string Local URL with query. */
    function add_query_arg(array $args, string $url): string { return $url . '?' . http_build_query($args); }
    /** @param int $id Room ID. @param string $context Output context. @return string Room edit URL. */
    function get_edit_post_link(int $id, string $context = ''): string { return admin_url('post.php?post=' . $id); }
    /** @param mixed $actual Selected value. @param mixed $expected Option value. @param bool $echo Whether to echo. @return string Selected attribute. */
    function selected($actual, $expected, bool $echo = true): string { return $actual === $expected ? ' selected="selected"' : ''; }
    /** @param string $action Action name. @param string $name Field name. @return void Render a nonce without state. */
    function wp_nonce_field(string $action, string $name): void { echo '<input name="' . esc_attr($name) . '" value="valid:' . esc_attr($action) . '">'; }
    /** @param string $label Button label. @param string $type Button class. @param string $name Control name. @param bool $wrap Paragraph wrapper. @return void Render a button. */
    function submit_button(string $label = 'Save changes', string $type = 'primary', string $name = 'submit', bool $wrap = true): void { echo '<button>' . esc_html($label) . '</button>'; }
    /** @param mixed $value Slashed input. @return mixed Unslashed test data. */
    function wp_unslash($value) { return $value; }
    /** @return void Record the request's private response policy. */
    function nocache_headers(): void { $GLOBALS['access_no_cache'] = true; }
    /** @param string $hook Hook name. @param callable $callback Callback. @return void Record registered callbacks. */
    function add_action(string $hook, callable $callback): void { $GLOBALS['access_hooks'][$hook][] = $callback; }
    /** @param string $query User lookup. @return array No defaults require a real user account. */
    function get_users(string $query): array { return []; }
    /** @return string Test locale. */
    function get_locale(): string { return 'en_US'; }
    /** @return bool Match the administrator fixture. */
    function is_super_admin(): bool { return $GLOBALS['access_manage']; }
    /** @param string $path Plugin path. @return string Stable plugin basename. */
    function plugin_basename(string $path): string { return 'rrze-rsvp/rrze-rsvp.php'; }
    /** @param array $values Saved values. @param array $defaults Defaults. @return array Combined options. */
    function wp_parse_args(array $values, array $defaults): array { return array_merge($defaults, $values); }
    /** @param mixed $value Text. @return string Plain text. */
    function sanitize_text_field($value): string { return is_scalar($value) ? trim(strip_tags((string) $value)) : ''; }

    require_once dirname(__DIR__) . '/config/config.php';
    require_once dirname(__DIR__) . '/includes/Settings.php';
    require_once dirname(__DIR__) . '/includes/Access/Admin.php';

    use RRZE\RSVP\Access\Admin;
    use RRZE\RSVP\Access\RoomAccess;
    use RRZE\RSVP\Settings;

    /** @param bool $condition Required assertion. @param string $message Failure context. @return void */
    function accessAdminAssert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    /** @param Admin $admin Controller under test. @return string Request-local rendered markup. */
    function accessAdminHtml(Admin $admin): string
    {
        ob_start();
        $admin->render();
        return ob_get_clean();
    }

    $GLOBALS['access_manage'] = true;
    $GLOBALS['access_edit'] = true;
    $GLOBALS['access_options'] = ['general_room_access_enabled' => 'on'];
    $GLOBALS['access_meta'] = [7 => ['rrze-rsvp-room-sso-required' => 'on']];
    $GLOBALS['access_rooms'] = [7 => (object) ['ID' => 7, 'post_type' => 'room', 'post_status' => 'publish', 'post_title' => 'Room <7>']];
    $request = ['room_id' => '7', 'rrze_rsvp_room_access_nonce' => 'valid:rrze-rsvp-room-access-7', 'emails' => "first@example.test\r\n\nsecond@example.test"];
    $_GET = ['page' => 'rrze-rsvp', 'current-tab' => 'rrze-rsvp-room_access', 'room_id' => '7'];

    $admin = new Admin();
    $admin->onLoaded();
    accessAdminAssert(count($GLOBALS['access_hooks']['admin_init']) === 1, 'Editor registers its own POST boundary.');
    accessAdminAssert($admin->submit($request) === true, 'Valid administrator submission reaches storage.');
    accessAdminAssert(RoomAccess::$calls === [[7, ['first@example.test', 'second@example.test']]], 'Only nonempty lines from the selected room reach storage.');

    foreach ([
        ['manage' => false, 'edit' => true, 'enabled' => 'on', 'request' => $request],
        ['manage' => true, 'edit' => false, 'enabled' => 'on', 'request' => $request],
        ['manage' => true, 'edit' => true, 'enabled' => 'off', 'request' => $request],
        ['manage' => true, 'edit' => true, 'enabled' => 'on', 'request' => array_replace($request, ['room_id' => '999'])],
        ['manage' => true, 'edit' => true, 'enabled' => 'on', 'request' => array_replace($request, ['room_id' => '-7'])],
        ['manage' => true, 'edit' => true, 'enabled' => 'on', 'request' => array_replace($request, ['room_id' => ['7']])],
        ['manage' => true, 'edit' => true, 'enabled' => 'on', 'request' => array_replace($request, ['rrze_rsvp_room_access_nonce' => 'valid:rrze-rsvp-room-access-8'])],
        ['manage' => true, 'edit' => true, 'enabled' => 'on', 'request' => array_replace($request, ['emails' => ['injected@example.test']])],
    ] as $case) {
        $GLOBALS['access_manage'] = $case['manage'];
        $GLOBALS['access_edit'] = $case['edit'];
        $GLOBALS['access_options']['general_room_access_enabled'] = $case['enabled'];
        $before = count(RoomAccess::$calls);
        accessAdminAssert(is_wp_error((new Admin())->submit($case['request'])), 'Invalid boundary input must be rejected.');
        accessAdminAssert(count(RoomAccess::$calls) === $before, 'Rejected boundary must never call storage.');
    }

    $GLOBALS['access_manage'] = false;
    $GLOBALS['access_options']['general_room_access_enabled'] = 'on';
    accessAdminAssert(!str_contains(accessAdminHtml(new Admin()), 'first@example.test'), 'Unauthorized render cannot reveal addresses.');
    $GLOBALS['access_manage'] = $GLOBALS['access_edit'] = true;
    $html = accessAdminHtml(new Admin());
    accessAdminAssert(str_contains($html, 'first@example.test') && str_contains($html, 'Room &lt;7&gt;'), 'Authorized editor renders stored addresses and escapes titles.');
    accessAdminAssert(!str_contains($html, 'action="options.php"') && !str_contains($html, 'rrze_rsvp['), 'Lists are excluded from the general settings payload.');

    RoomAccess::$lists[7] = new WP_Error('room_access_unavailable', 'Unreadable list');
    $html = accessAdminHtml(new Admin());
    accessAdminAssert(!str_contains($html, '<textarea') && str_contains($html, 'restriction remains active'), 'Unreadable data never becomes an empty editable list.');
    RoomAccess::$lists[7] = [];
    $GLOBALS['access_meta'][7] = [];
    $html = accessAdminHtml(new Admin());
    accessAdminAssert(!str_contains($html, '<textarea') && str_contains($html, 'Edit room login settings'), 'A room without login offers setup instead of a new list.');
    RoomAccess::$lists[7] = ['existing@example.test'];
    accessAdminAssert(str_contains(accessAdminHtml(new Admin()), '<textarea'), 'An existing list remains explicitly clearable after login was removed.');

    $GLOBALS['access_meta'][7] = ['rrze-rsvp-room-ldap-required' => 'on'];
    $GLOBALS['access_options']['ldap_server'] = 'ldap.example.test';
    RoomAccess::$lists[7] = [];
    accessAdminAssert(str_contains(accessAdminHtml(new Admin()), '<textarea'), 'Configured LDAP permits list editing.');

    $admin = new Admin();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = array_replace($request, ['rrze_rsvp_room_access_action' => 'save', 'emails' => 'bad</textarea><script>example</script>']);
    RoomAccess::$failure = new WP_Error('room_access_invalid_emails', 'Enter one valid email address per line.');
    $admin->handlePost();
    $html = accessAdminHtml($admin);
    accessAdminAssert(str_contains($html, 'bad&lt;/textarea&gt;&lt;script&gt;example&lt;/script&gt;') && !str_contains($html, '<script>'), 'Validation feedback preserves and escapes input only in the current response.');
    accessAdminAssert($GLOBALS['access_no_cache'] === true, 'POST feedback is not cacheable.');
    RoomAccess::$failure = null;
    accessAdminAssert((new Admin())->submit(array_replace($request, ['emails' => " \n\n"])) === true, 'Explicit empty submission is allowed.');
    accessAdminAssert(end(RoomAccess::$calls) === [7, []], 'Clearing sends an explicit empty list.');

    $_GET = [];
    $settings = new Settings(dirname(__DIR__) . '/rrze-rsvp.php');
    $saved = $settings->sanitizeOptions(['general_room_access_enabled' => 'on', 'room_access_emails' => ['must-not-persist@example.test']]);
    accessAdminAssert($saved['general_room_access_enabled'] === 'on' && !array_key_exists('room_access_emails', $saved), 'Only the known switch enters the regular settings option.');
    $saved = $settings->sanitizeOptions(['general_room_access_enabled' => ['on']]);
    accessAdminAssert($saved['general_room_access_enabled'] === 'off', 'Unexpected switch values cannot enable access management.');
    accessAdminAssert(RRZE\RSVP\Config\defaultOptions()['room_access_enabled'] === 'off', 'Fresh sites default to disabled.');
    ob_start();
    $settings->showTabs();
    $tabs = ob_get_clean();
    accessAdminAssert(str_contains($tabs, 'rrze-rsvp-room_access'), 'Enabled site exposes the permissions tab.');
    $GLOBALS['access_options']['general_room_access_enabled'] = 'off';
    ob_start();
    (new Settings(dirname(__DIR__) . '/rrze-rsvp.php'))->showTabs();
    $tabs = ob_get_clean();
    accessAdminAssert(!str_contains($tabs, 'rrze-rsvp-room_access'), 'Disabled site hides the permissions tab.');

    echo "room-access-admin: all checks passed\n";
}
