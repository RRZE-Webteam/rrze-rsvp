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
        public static array $revisionSequence = [];

        /** @return bool Whether the site-local feature is enabled. */
        public static function isEnabled(): bool { return ($GLOBALS['access_options']['general_room_access_enabled'] ?? 'off') === 'on'; }

        /**
         * @param int $roomId Room ID.
         * @return array|\WP_Error Stored list or injected failure.
         */
        public static function readEntries(int $roomId): array|\WP_Error { return self::$lists[$roomId] ?? []; }

        /**
         * @param int $roomId Room ID.
         * @return string|\WP_Error Opaque current revision or injected read failure.
         */
        public static function revision(int $roomId): string|\WP_Error
        {
            if (self::$revisionSequence) return array_shift(self::$revisionSequence);
            $entries = self::readEntries($roomId);
            return $entries instanceof \WP_Error ? $entries : hash('sha256', json_encode($entries));
        }

        /**
         * @param int $roomId Room ID.
         * @param array $emails Untrusted input delegated by the controller.
         * @param string|null $revision Expected opaque list revision.
         * @return true|\WP_Error Save outcome.
         */
        public static function saveEntries(int $roomId, array $emails, ?string $revision = null): true|\WP_Error
        {
            self::$calls[] = [$roomId, $emails, $revision];
            if (self::$failure) {
                return self::$failure;
            }
            self::$lists[$roomId] = $emails;
            return true;
        }
    }

    /** Catalog boundary double; persistence/name validation is tested with the real catalog separately. */
    final class DepartmentCatalog
    {
        public static array $records = [];
        public static array $calls = [];
        public static ?\WP_Error $failure = null;
        /** @return array|\WP_Error Current records or injected storage failure. */
        public static function read(): array|\WP_Error { return self::$records; }
        /**
         * @param string $name Proposed name.
         * @return array|\WP_Error Created record or injected validation error.
         */
        public static function create(string $name): array|\WP_Error
        {
            self::$calls[] = ['create', $name];
            return self::$failure ?? ['id' => 'new-department', 'name' => $name, 'archived' => false];
        }
        /**
         * @param string $id Stable UUID.
         * @param string $name New name.
         * @return true|\WP_Error Change outcome.
         */
        public static function rename(string $id, string $name): true|\WP_Error
        {
            self::$calls[] = ['rename', $id, $name];
            return self::$failure ?? true;
        }
        /**
         * @param string $id Stable UUID.
         * @param bool $archived Explicit desired state.
         * @return true|\WP_Error Change outcome.
         */
        public static function setArchived(string $id, bool $archived): true|\WP_Error
        {
            self::$calls[] = ['archive', $id, $archived];
            return self::$failure ?? true;
        }
    }
}

namespace {
    define('ABSPATH', dirname(__DIR__) . '/');

    /** Minimal WordPress error contract. */
    class WP_Error
    {
        /**
         * @param string $code Error identifier.
         * @param string $message Safe user feedback.
         * @param array $data Status metadata.
         */
        public function __construct(private string $code, private string $message = '', private array $data = []) {}
        /** @return string Error identifier. */
        public function get_error_code(): string { return $this->code; }
        /** @return string Error feedback. */
        public function get_error_message(): string { return $this->message; }
    }

    /**
     * @param mixed $value Candidate.
     * @return bool Whether this is an error.
     */
    function is_wp_error($value): bool { return $value instanceof WP_Error; }
    /**
     * @param string $text Source text.
     * @param string $domain Translation domain.
     * @return string Source text.
     */
    function __(string $text, string $domain = ''): string { return $text; }
    /**
     * @param string $text Source text.
     * @param string $domain Translation domain.
     * @return string Escaped source text.
     */
    function esc_html__(string $text, string $domain = ''): string { return esc_html($text); }
    /**
     * @param mixed $value Text.
     * @return string HTML-safe text.
     */
    function esc_html($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
    /**
     * @param mixed $value Attribute value.
     * @return string Escaped attribute.
     */
    function esc_attr($value): string { return esc_html($value); }
    /**
     * @param mixed $value URL.
     * @return string Escaped URL.
     */
    function esc_url($value): string { return esc_html($value); }
    /**
     * @param string $value Textarea value.
     * @return string Escaped value.
     */
    function esc_textarea(string $value): string { return esc_html($value); }
    /**
     * @param string $capability Requested capability.
     * @param int $id Optional object ID.
     * @return bool Injected permission state.
     */
    function current_user_can(string $capability, int $id = 0): bool
    {
        return $capability === 'manage_options' ? $GLOBALS['access_manage'] : $GLOBALS['access_edit'];
    }
    /**
     * @param int $id Object ID.
     * @return object|null Current website's object.
     */
    function get_post(int $id): ?object { return $GLOBALS['access_rooms'][$id] ?? null; }
    /**
     * @param array $args Query options.
     * @return array Current website's editable rooms.
     */
    function get_posts(array $args): array { return array_values($GLOBALS['access_rooms']); }
    /**
     * @param int $id Room ID.
     * @param string $key Meta key.
     * @param bool $single Single-value mode.
     * @return mixed Fixture metadata.
     */
    function get_post_meta(int $id, string $key, bool $single = false) { return $GLOBALS['access_meta'][$id][$key] ?? ''; }
    /**
     * @param string $name Option name.
     * @param mixed $default Fallback.
     * @return mixed Site-local options.
     */
    function get_option(string $name, $default = false) { return $name === 'rrze_rsvp' ? $GLOBALS['access_options'] : $default; }
    /**
     * @param string $nonce Submitted nonce.
     * @param string $action Expected action.
     * @return bool Whether the nonce matches the room.
     */
    function wp_verify_nonce(string $nonce, string $action): bool { return $nonce === 'valid:' . $action; }
    /**
     * @param string $path Relative admin path.
     * @return string Local administration URL.
     */
    function admin_url(string $path = ''): string { return 'https://test.local/wp-admin/' . $path; }
    /**
     * @param array $args Query parameters.
     * @param string $url Base URL.
     * @return string Local URL with query.
     */
    function add_query_arg(array $args, string $url): string { return $url . '?' . http_build_query($args); }
    /**
     * @param int $id Room ID.
     * @param string $context Output context.
     * @return string Room edit URL.
     */
    function get_edit_post_link(int $id, string $context = ''): string { return admin_url('post.php?post=' . $id); }
    /**
     * @param mixed $actual Selected value.
     * @param mixed $expected Option value.
     * @param bool $echo Whether to echo.
     * @return string Selected attribute.
     */
    function selected($actual, $expected, bool $echo = true): string { return $actual === $expected ? ' selected="selected"' : ''; }
    /**
     * @param string $action Action name.
     * @param string $name Field name.
     * @return void Render a nonce without state.
     */
    function wp_nonce_field(string $action, string $name): void { echo '<input name="' . esc_attr($name) . '" value="valid:' . esc_attr($action) . '">'; }
    /**
     * @param string $label Button label.
     * @param string $type Button class.
     * @param string $name Control name.
     * @param bool $wrap Paragraph wrapper.
     * @return void Render a button.
     */
    function submit_button(string $label = 'Save changes', string $type = 'primary', string $name = 'submit', bool $wrap = true): void { echo '<button>' . esc_html($label) . '</button>'; }
    /**
     * @param mixed $value Slashed input.
     * @return mixed Unslashed test data.
     */
    function wp_unslash($value) { return $value; }
    /** @return void Record the request's private response policy. */
    function nocache_headers(): void { $GLOBALS['access_no_cache'] = true; }
    /**
     * @param string $hook Hook name.
     * @param callable $callback Callback.
     * @return void Record registered callbacks.
     */
    function add_action(string $hook, callable $callback): void { $GLOBALS['access_hooks'][$hook][] = $callback; }
    /**
     * @param string $query User lookup.
     * @return array No defaults require a real user account.
     */
    function get_users(string $query): array { return []; }
    /** @return string Test locale. */
    function get_locale(): string { return 'en_US'; }
    /** @return bool Match the administrator fixture. */
    function is_super_admin(): bool { return $GLOBALS['access_manage']; }
    /**
     * @param string $path Plugin path.
     * @return string Stable plugin basename.
     */
    function plugin_basename(string $path): string { return 'rrze-rsvp/rrze-rsvp.php'; }
    /**
     * @param array $values Saved values.
     * @param array $defaults Defaults.
     * @return array Combined options.
     */
    function wp_parse_args(array $values, array $defaults): array { return array_merge($defaults, $values); }
    /**
     * @param mixed $value Text.
     * @return string Plain text.
     */
    function sanitize_text_field($value): string { return is_scalar($value) ? trim(strip_tags((string) $value)) : ''; }
    /**
     * @param string $email Candidate address.
     * @return string|false Valid test address or failure.
     */
    function is_email(string $email): string|false { return filter_var($email, FILTER_VALIDATE_EMAIL); }

    require_once dirname(__DIR__) . '/config/config.php';
    require_once dirname(__DIR__) . '/includes/Settings.php';
    require_once dirname(__DIR__) . '/includes/Access/Admin.php';
    require_once dirname(__DIR__) . '/includes/Access/DepartmentAdmin.php';

    use RRZE\RSVP\Access\Admin;
    use RRZE\RSVP\Access\RoomAccess;
    use RRZE\RSVP\Access\DepartmentAdmin;
    use RRZE\RSVP\Access\DepartmentCatalog;
    use RRZE\RSVP\Settings;

    /**
     * @param bool $condition Required assertion.
     * @param string $message Failure context.
     * @return void
     */
    function accessAdminAssert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    /**
     * @param Admin $admin Controller under test.
     * @return string Request-local rendered markup.
     */
    function accessAdminHtml(Admin $admin): string
    {
        ob_start();
        $admin->render();
        return ob_get_clean();
    }

    /**
     * @param array $overrides Submitted edits.
     * @return array Complete browser-like table payload.
     */
    function accessAdminInput(array $overrides = []): array
    {
        $rows = [];
        foreach (RoomAccess::readEntries(7) as $entry) {
            $rows[] = ['email' => $entry['email'], 'department_id' => $entry['department_id'] ?? ''];
        }
        return array_replace(['room_id' => '7', 'rrze_rsvp_room_access_nonce' => 'valid:rrze-rsvp-room-access-7',
            'entries' => $rows, 'expected_rows' => (string) count($rows), 'revision' => RoomAccess::revision(7),
            'emails' => '', 'bulk_department' => '', 'complete' => '1'], $overrides);
    }

    /**
     * @param string $action Department operation.
     * @param string $id Optional UUID.
     * @return array Valid action payload.
     */
    function accessDepartmentInput(string $action, string $id = ''): array
    {
        return ['department_action' => $action, 'department_id' => $id, 'department_name' => 'Facilities',
            'department_nonce' => 'valid:rrze-rsvp-department-' . $action . '-' . $id];
    }

    $GLOBALS['access_manage'] = $GLOBALS['access_edit'] = true;
    $GLOBALS['access_options'] = ['general_room_access_enabled' => 'on'];
    $GLOBALS['access_meta'] = [7 => ['rrze-rsvp-room-sso-required' => 'on']];
    $GLOBALS['access_rooms'] = [7 => (object) ['ID' => 7, 'post_type' => 'room', 'post_status' => 'publish', 'post_title' => 'Room <7>']];
    $_GET = ['page' => 'rrze-rsvp', 'current-tab' => 'rrze-rsvp-room_access', 'room_id' => '7'];
    DepartmentCatalog::$records = [
        ['id' => 'active-id', 'name' => 'Library <West>', 'archived' => false],
        ['id' => 'archived-id', 'name' => 'Former department', 'archived' => true],
    ];
    $admin = new Admin();
    $admin->onLoaded();
    accessAdminAssert(count($GLOBALS['access_hooks']['admin_init']) === 1, 'The editor registers one POST boundary.');
    $request = accessAdminInput(['emails' => "first@example.test\r\n\nsecond@example.test", 'bulk_department' => 'active-id']);
    accessAdminAssert($admin->submit($request) === true, 'Bulk additions reach storage once.');
    accessAdminAssert(RoomAccess::$calls[0] === [7, [
        ['email' => 'first@example.test', 'department_id' => 'active-id'],
        ['email' => 'second@example.test', 'department_id' => 'active-id'],
    ], $request['revision']], 'New addresses receive the common department and expected revision.');
    $request = accessAdminInput(['emails' => "FIRST@example.test\nnew@example.test", 'bulk_department' => '']);
    accessAdminAssert((new Admin())->submit($request) === true, 'Re-pasting an existing email is harmless.');
    accessAdminAssert(RoomAccess::$lists[7][0]['department_id'] === 'active-id'
        && RoomAccess::$lists[7][2] === ['email' => 'new@example.test', 'department_id' => null], 'Existing assignments survive bulk defaults; new addresses may have none.');

    $request = accessAdminInput();
    foreach ([
        ['manage' => false, 'edit' => true, 'enabled' => 'on', 'request' => $request],
        ['manage' => true, 'edit' => false, 'enabled' => 'on', 'request' => $request],
        ['manage' => true, 'edit' => true, 'enabled' => 'off', 'request' => $request],
        ['manage' => true, 'edit' => true, 'enabled' => 'on', 'request' => array_replace($request, ['room_id' => '999'])],
        ['manage' => true, 'edit' => true, 'enabled' => 'on', 'request' => array_replace($request, ['room_id' => '-7'])],
        ['manage' => true, 'edit' => true, 'enabled' => 'on', 'request' => array_replace($request, ['room_id' => ['7']])],
        ['manage' => true, 'edit' => true, 'enabled' => 'on', 'request' => array_replace($request, ['rrze_rsvp_room_access_nonce' => 'wrong'])],
        ['manage' => true, 'edit' => true, 'enabled' => 'on', 'request' => array_replace($request, ['emails' => ['injected@example.test']])],
        ['manage' => true, 'edit' => true, 'enabled' => 'on', 'request' => array_replace($request, ['complete' => ''])],
        ['manage' => true, 'edit' => true, 'enabled' => 'on', 'request' => array_replace($request, ['expected_rows' => '2'])],
        ['manage' => true, 'edit' => true, 'enabled' => 'on', 'request' => array_replace($request, ['entries' => array_slice($request['entries'], 0, 1)])],
        ['manage' => true, 'edit' => true, 'enabled' => 'on', 'request' => array_replace($request, ['entries' => [], 'expected_rows' => '0'])],
    ] as $case) {
        $GLOBALS['access_manage'] = $case['manage'];
        $GLOBALS['access_edit'] = $case['edit'];
        $GLOBALS['access_options']['general_room_access_enabled'] = $case['enabled'];
        $before = count(RoomAccess::$calls);
        accessAdminAssert(is_wp_error((new Admin())->submit($case['request'])), 'Malformed or unauthorized payload is rejected.');
        accessAdminAssert(count(RoomAccess::$calls) === $before, 'Incomplete/unauthorized forms never reach storage.');
    }
    $GLOBALS['access_manage'] = $GLOBALS['access_edit'] = true;
    $GLOBALS['access_options']['general_room_access_enabled'] = 'on';
    $conflict = accessAdminInput();
    $conflict['entries'][1] = ['email' => strtoupper($conflict['entries'][0]['email']), 'department_id' => ''];
    $before = count(RoomAccess::$calls);
    accessAdminAssert((new Admin())->submit($conflict)->get_error_code() === 'room_access_conflicting_departments', 'Conflicting duplicate assignments reject the whole table.');
    accessAdminAssert(count(RoomAccess::$calls) === $before, 'Conflicts never partially update storage.');
    accessAdminAssert((new Admin())->submit(accessAdminInput(['emails' => "valid@example.test\nbroken"]))->get_error_code() === 'room_access_invalid_emails', 'One invalid bulk line prevents all additions.');
    accessAdminAssert(count(RoomAccess::$calls) === $before, 'Invalid bulk input does not partially save.');

    $GLOBALS['access_manage'] = false;
    accessAdminAssert(!str_contains(accessAdminHtml(new Admin()), 'first@example.test'), 'Non-admins cannot read addresses.');
    $GLOBALS['access_manage'] = true;
    $html = accessAdminHtml(new Admin());
    accessAdminAssert(str_contains($html, 'first@example.test') && str_contains($html, 'Room &lt;7&gt;')
        && str_contains($html, 'Library &lt;West&gt;'), 'Authorized table escapes room and department names.');
    accessAdminAssert(!str_contains($html, 'action="options.php"') && !str_contains($html, 'rrze_rsvp['), 'No permission fields enter general options.');
    accessAdminAssert(strpos($html, 'name="complete"') > strpos($html, 'name="bulk_department"'), 'Completion sentinel follows the entire form payload.');
    accessAdminAssert(!str_contains($html, 'value="archived-id"'), 'Archived departments are absent from new assignment choices.');
    RoomAccess::$lists[7][0]['department_id'] = 'archived-id';
    $html = accessAdminHtml(new Admin());
    accessAdminAssert(substr_count($html, 'value="archived-id"') === 1 && str_contains($html, 'Former department (archived)'), 'An archived department is retained only on its existing row.');

    $stale = accessAdminInput();
    RoomAccess::$lists[7][0]['department_id'] = null;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = $stale + ['rrze_rsvp_room_access_action' => 'save'];
    $admin = new Admin();
    $before = count(RoomAccess::$calls);
    $admin->handlePost();
    $html = accessAdminHtml($admin);
    accessAdminAssert(count(RoomAccess::$calls) === $before && str_contains($html, 'changed while you were editing'), 'Stale revision prevents any write.');
    accessAdminAssert(str_contains($html, 'name="revision" value="' . $stale['revision'] . '"'), 'Failed stale input retains its original revision, never silently upgrading the token.');
    RoomAccess::$revisionSequence = ['before', 'after'];
    accessAdminAssert(!str_contains(accessAdminHtml(new Admin()), '<textarea'), 'A read/revision race never renders a mismatched writable snapshot.');

    RoomAccess::$lists[7] = new WP_Error('room_access_unavailable', 'Unreadable list');
    $html = accessAdminHtml(new Admin());
    accessAdminAssert(!str_contains($html, '<textarea') && str_contains($html, 'restriction remains active'), 'Unreadable data never renders as empty editable rows.');
    RoomAccess::$lists[7] = [];
    $GLOBALS['access_meta'][7] = [];
    $html = accessAdminHtml(new Admin());
    accessAdminAssert(!str_contains($html, '<textarea') && str_contains($html, 'Edit room login settings'), 'A room without login shows setup instead of an editor.');
    RoomAccess::$lists[7] = [['email' => 'existing@example.test', 'department_id' => null]];
    accessAdminAssert(str_contains(accessAdminHtml(new Admin()), '<textarea'), 'Existing lists remain explicitly clearable when login configuration was removed.');
    $GLOBALS['access_meta'][7] = ['rrze-rsvp-room-ldap-required' => 'on'];
    $GLOBALS['access_options']['ldap_server'] = 'ldap.example.test';
    $clear = accessAdminInput();
    $clear['entries'][0]['remove'] = '1';
    accessAdminAssert((new Admin())->submit($clear) === true && RoomAccess::$lists[7] === [], 'Removing every row with no additions explicitly clears the restriction.');

    $_POST = accessAdminInput(['emails' => 'bad</textarea><script>example</script>']) + ['rrze_rsvp_room_access_action' => 'save'];
    $admin = new Admin();
    $admin->handlePost();
    $html = accessAdminHtml($admin);
    accessAdminAssert(str_contains($html, 'bad&lt;/textarea&gt;&lt;script&gt;example&lt;/script&gt;') && !str_contains($html, '<script>'), 'Invalid bulk input is preserved and escaped only in this response.');
    accessAdminAssert($GLOBALS['access_no_cache'], 'POST responses are private.');
    RoomAccess::$failure = new WP_Error('room_access_department_archived', 'Archived department cannot be assigned.');
    $_POST = accessAdminInput(['emails' => 'new@example.test', 'bulk_department' => 'archived-id']) + ['rrze_rsvp_room_access_action' => 'save'];
    $admin = new Admin();
    $admin->handlePost();
    $html = accessAdminHtml($admin);
    accessAdminAssert(str_contains($html, 'Archived department cannot be assigned.') && str_contains($html, 'new@example.test')
        && str_contains($html, 'value="archived-id" selected disabled'), 'Storage rejection of a new archived assignment preserves safe input for correction.');
    RoomAccess::$failure = null;

    foreach (['create', 'rename', 'archive', 'reactivate'] as $action) {
        $id = $action === 'create' ? '' : 'active-id';
        accessAdminAssert((new DepartmentAdmin())->submit(accessDepartmentInput($action, $id)) === true, 'Every department operation reaches its catalog method.');
    }
    accessAdminAssert(DepartmentCatalog::$calls === [['create', 'Facilities'], ['rename', 'active-id', 'Facilities'],
        ['archive', 'active-id', true], ['archive', 'active-id', false]], 'Archive/reactivation use explicit stable IDs and desired states.');
    $before = count(DepartmentCatalog::$calls);
    $GLOBALS['access_manage'] = false;
    accessAdminAssert(is_wp_error((new DepartmentAdmin())->submit(accessDepartmentInput('create'))), 'Non-admin department writes are blocked.');
    $GLOBALS['access_manage'] = true;
    $GLOBALS['access_options']['general_room_access_enabled'] = 'off';
    accessAdminAssert(is_wp_error((new DepartmentAdmin())->submit(accessDepartmentInput('archive', 'active-id'))), 'Disabled management blocks catalog writes.');
    $GLOBALS['access_options']['general_room_access_enabled'] = 'on';
    foreach ([['department_nonce' => 'wrong'], ['department_action' => 'delete'], ['department_name' => []]] as $invalid) {
        accessAdminAssert(is_wp_error((new DepartmentAdmin())->submit(array_replace(accessDepartmentInput('create'), $invalid))), 'Invalid department operations are rejected at the boundary.');
    }
    accessAdminAssert(count(DepartmentCatalog::$calls) === $before, 'Rejected catalog operations never mutate data.');
    $_GET['access-view'] = 'departments';
    $_POST = accessDepartmentInput('create');
    $admin = new Admin();
    $admin->handlePost();
    $html = accessAdminHtml($admin);
    accessAdminAssert(str_contains($html, 'Department saved.') && str_contains($html, 'Reactivate')
        && !str_contains($html, 'name="emails"'), 'Secondary department view routes through the existing Admin controller.');
    DepartmentCatalog::$failure = new WP_Error('department_duplicate', 'Department names must be unique.');
    $_POST = array_replace(accessDepartmentInput('rename', 'active-id'), ['department_name' => '<script>Duplicate</script>']);
    $admin = new Admin();
    $admin->handlePost();
    $html = accessAdminHtml($admin);
    accessAdminAssert(str_contains($html, 'Department names must be unique.') && str_contains($html, '&lt;script&gt;Duplicate&lt;/script&gt;')
        && !str_contains($html, '<script>'), 'Catalog validation errors keep escaped name input request-local.');
    DepartmentCatalog::$failure = null;

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
