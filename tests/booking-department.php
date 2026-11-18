<?php
/** Standalone authenticated-storage and read-only admin tests for frozen department snapshots. */
declare(strict_types=1);

namespace RRZE\RSVP\Access {
    /** Existing website context boundary, independent of live catalog and feature state. */
    final class RoomAccess
    {
        public static string $context = '00112233445566778899aabbccddeeff';
        public static bool $featureEnabled = true;
        public static array $catalog = [];

        /** @return string Existing persistent website context. */
        public static function siteContext(): string { return self::$context; }
    }
}

namespace RRZE\RSVP {
    /** Course discriminator used exclusively by the admin display boundary. */
    final class Functions
    {
        public static array $courses = [];

        /**
         * @param int $bookingId Existing booking.
         * @return bool Whether this is a course registration.
         */
        public static function isCourseBooking(int $bookingId): bool { return in_array($bookingId, self::$courses, true); }
    }
}

namespace {
    use RRZE\RSVP\Access\{BookingDepartment, BookingDepartmentAdmin, BookingDepartmentCipher, EncryptionKey, RoomAccess};
    use RRZE\RSVP\Functions;

    define('ABSPATH', dirname(__DIR__) . '/');
    define('AUTH_KEY', 'department-test-auth-key-' . str_repeat('a1', 32));
    define('AUTH_SALT', 'department-test-auth-salt-' . str_repeat('b2', 32));

    /** Minimal WordPress post with mutable administrative metadata. */
    final class WP_Post
    {
        /**
         * @param int $ID Post ID.
         * @param string $post_status Status.
         * @param string $post_type Type.
         */
        public function __construct(public int $ID, public string $post_status = 'draft', public string $post_type = 'booking') {}
    }

    /** Non-disclosing storage result. */
    final class WP_Error
    {
        /**
         * @param string $code Failure identifier.
         * @param string $message Public message.
         * @param mixed $data Error metadata.
         */
        public function __construct(private string $code, private string $message = '', public mixed $data = null) {}
        /** @return string Error code. */
        public function get_error_code(): string { return $this->code; }
        /** @return string Safe diagnostic. */
        public function get_error_message(): string { return $this->message; }
    }

    /**
     * @param string $text Translation key.
     * @param string $domain Domain.
     * @return string Fixture translation.
     */
    function __(string $text, string $domain = ''): string { return $text; }
    /**
     * @param mixed $value Display text.
     * @return string Escaped output.
     */
    function esc_html(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
    /**
     * @param string $text Translation key.
     * @param string $domain Domain.
     * @return string Escaped translation.
     */
    function esc_html__(string $text, string $domain = ''): string { return esc_html($text); }
    /**
     * @param mixed $value Result.
     * @return bool Whether it is a WordPress error.
     */
    function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
    /**
     * @param string $scheme Requested salt scheme.
     * @return string Configured deterministic test-only secret.
     */
    function wp_salt(string $scheme): string { return $GLOBALS['department_test_salt'] ?? AUTH_KEY . AUTH_SALT; }
    /**
     * @param int $id Post ID.
     * @return string|false Existing type.
     */
    function get_post_type(int $id): string|false { return $GLOBALS['department_test_posts'][$id]->post_type ?? false; }
    /**
     * @param int $id Post ID.
     * @return string|false Existing status.
     */
    function get_post_status(int $id): string|false { return $GLOBALS['department_test_posts'][$id]->post_status ?? false; }
    /**
     * @param int $id Cache key.
     * @param string $group Cache group.
     * @return bool Simulated invalidation success.
     */
    function wp_cache_delete(int $id, string $group): bool { return true; }
    /**
     * @param int $id Post.
     * @param string $key Meta name.
     * @param bool $single Single-value mode.
     * @return mixed Raw ciphertext metadata.
     */
    function get_post_meta(int $id, string $key, bool $single = false): mixed
    {
        $values = $GLOBALS['department_test_meta'][$id][$key] ?? [];
        return $single ? ($values[0] ?? '') : $values;
    }
    /**
     * @param int $id Post.
     * @param string $key Name.
     * @param mixed $value Slashed value.
     * @param bool $unique Unique constraint.
     * @return int|false Insert result.
     */
    function add_post_meta(int $id, string $key, mixed $value, bool $unique = false): int|false
    {
        if ($GLOBALS['department_test_write_fail'] || ($unique && isset($GLOBALS['department_test_meta'][$id][$key]))) return false;
        $GLOBALS['department_test_meta'][$id][$key][] = stripslashes($value);
        return 1;
    }
    /**
     * @param string $value Database string.
     * @return string WordPress-compatible slashed value.
     */
    function wp_slash(string $value): string { return addslashes($value); }
    /**
     * @param string $hook Hook name.
     * @param callable $callback Handler.
     * @return void Record registration.
     */
    function add_action(string $hook, callable $callback): void { $GLOBALS['department_test_hooks'][$hook][] = $callback; }
    /**
     * @param mixed ...$args WordPress metabox arguments.
     * @return void Record the read-only box.
     */
    function add_meta_box(...$args): void { $GLOBALS['department_test_boxes'][] = $args; }
    /**
     * @param string $capability Required capability.
     * @param int $id Object ID.
     * @return bool Object-specific permission.
     */
    function current_user_can(string $capability, int $id): bool
    {
        return $capability === 'edit_post' && in_array($id, $GLOBALS['department_test_editable'], true);
    }
    /**
     * @param bool $condition Expected truth.
     * @param string $message Failure context.
     * @return void
     */
    function departmentCheck(bool $condition, string $message): void
    {
        if (!$condition) throw new RuntimeException($message);
    }
    /**
     * @param BookingDepartmentAdmin $admin Reader.
     * @param WP_Post $post Requested post.
     * @return string Buffered markup.
     */
    function departmentRender(BookingDepartmentAdmin $admin, WP_Post $post): string
    {
        ob_start();
        try {
            $admin->render($post);
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
    /**
     * @param string $ciphertext Original value.
     * @param string $field Envelope field.
     * @param mixed $value Tampered value.
     * @return string Altered ciphertext.
     */
    function departmentTamper(string $ciphertext, string $field, mixed $value): string
    {
        $envelope = json_decode(substr($ciphertext, strlen(BookingDepartmentCipher::PREFIX)), true, 8, JSON_THROW_ON_ERROR);
        $envelope[$field] = $value;
        return BookingDepartmentCipher::PREFIX . json_encode($envelope, JSON_THROW_ON_ERROR);
    }

    require dirname(__DIR__) . '/includes/Access/EncryptionKey.php';
    require dirname(__DIR__) . '/includes/Access/BookingDepartmentCipher.php';
    require dirname(__DIR__) . '/includes/Access/BookingDepartment.php';
    require dirname(__DIR__) . '/includes/Access/BookingDepartmentAdmin.php';

    $GLOBALS['wpdb'] = (object) ['last_error' => ''];
    $GLOBALS['department_test_meta'] = $GLOBALS['department_test_hooks'] = $GLOBALS['department_test_boxes'] = [];
    $GLOBALS['department_test_editable'] = [100, 101, 102, 103];
    $GLOBALS['department_test_write_fail'] = false;
    foreach (range(100, 120) as $id) $GLOBALS['department_test_posts'][$id] = new WP_Post($id);
    $department = ['id' => 'c8585f41-29a7-482f-a427-b31c4b67503d', 'name' => 'Forschung & Entwicklung'];
    $meta = BookingDepartment::META_KEY;
    departmentCheck(BookingDepartment::read(100) === null, 'Existing bookings without snapshot must remain unassigned.');
    departmentCheck(BookingDepartment::saveNew(100, 10, null) === true && !isset($GLOBALS['department_test_meta'][100]),
        'An absent assignment must not create encrypted metadata or require catalog data.');
    departmentCheck(BookingDepartment::saveNew(100, 10, $department) === true, 'A new draft must retain its authorized department.');
    $stored = get_post_meta(100, $meta, true);
    departmentCheck(!str_contains($stored, $department['id']) && !str_contains($stored, $department['name'])
        && str_starts_with($stored, BookingDepartmentCipher::PREFIX), 'Department ID and name must both be encrypted.');
    departmentCheck(BookingDepartment::read(100) === $department, 'A snapshot must round-trip exactly.');
    $cipher = new BookingDepartmentCipher(RoomAccess::$context, 100);
    $another = $cipher->encrypt($department, 10);
    departmentCheck($another !== $stored && $cipher->decrypt($another) === $department, 'Repeated writes require independent random nonces.');
    $key = EncryptionKey::forWriting();
    departmentCheck($key->forBookingDepartment(RoomAccess::$context) !== $key->forStorage(RoomAccess::$context)
        && $key->bookingDepartmentIdentifier(RoomAccess::$context) !== $key->identifier(RoomAccess::$context),
        'Snapshot encryption and key identification must be purpose-separated from room-access lists.');

    RoomAccess::$featureEnabled = false;
    RoomAccess::$catalog = [$department['id'] => 'Renamed Department'];
    $GLOBALS['department_test_meta'][100]['rrze-rsvp-booking-seat'] = [999];
    $GLOBALS['department_test_posts'][100]->post_status = 'publish';
    departmentCheck(BookingDepartment::read(100) === $department, 'Disabling permissions, renaming departments and editing seats must preserve history.');
    RoomAccess::$catalog = [];
    departmentCheck(BookingDepartment::read(100) === $department, 'Removing the current department must not remove its historical name.');
    departmentCheck(is_wp_error(BookingDepartment::saveNew(100, 50, ['id' => $department['id'], 'name' => 'Replacement']))
        && get_post_meta(100, $meta, true) === $stored, 'Snapshots cannot be overwritten or attached to existing published bookings.');

    foreach ([
        departmentTamper($stored, 'version', 2), departmentTamper($stored, 'room_id', 11),
        departmentTamper($stored, 'key_id', str_repeat('0', 32)), departmentTamper($stored, 'key_source', 'unknown'),
        departmentTamper($stored, 'nonce', base64_encode(str_repeat('0', 24))),
        departmentTamper($stored, 'ciphertext', base64_encode(str_repeat('0', 40))), '', 'not ciphertext',
    ] as $tampered) {
        $GLOBALS['department_test_meta'][100][$meta] = [$tampered];
        departmentCheck(is_wp_error(BookingDepartment::read(100)), 'Corrupted snapshots must be explicit errors, never unassigned.');
    }
    $GLOBALS['department_test_meta'][100][$meta] = [$stored, $stored];
    departmentCheck(is_wp_error(BookingDepartment::read(100)), 'Duplicate snapshot records must fail closed.');
    $GLOBALS['department_test_meta'][100][$meta] = [$stored];
    $GLOBALS['department_test_meta'][101][$meta] = [$stored];
    departmentCheck(is_wp_error(BookingDepartment::read(101)), 'Copying ciphertext to a different booking must fail authentication.');
    RoomAccess::$context = str_repeat('a', 32);
    departmentCheck(is_wp_error(BookingDepartment::read(100)), 'Copying ciphertext to another website context must fail authentication.');
    RoomAccess::$context = '00112233445566778899aabbccddeeff';
    $GLOBALS['department_test_meta'][101][$meta] = ['broken original'];
    departmentCheck(is_wp_error(BookingDepartment::saveNew(101, 10, $department))
        && get_post_meta(101, $meta, true) === 'broken original', 'Unreadable existing data must never be overwritten.');

    $GLOBALS['department_test_write_fail'] = true;
    departmentCheck(is_wp_error(BookingDepartment::saveNew(102, 10, $department)) && BookingDepartment::read(102) === null,
        'A metadata write failure must be reported without pretending that a snapshot exists.');
    $GLOBALS['department_test_write_fail'] = false;
    foreach ([['id' => 'not-a-uuid', 'name' => 'Valid'], ['id' => $department['id'], 'name' => '<script>bad</script>'],
        ['id' => $department['id'], 'name' => ' padded '], ['id' => $department['id'], 'name' => str_repeat('ä', 201)],
        ['id' => $department['id'], 'name' => "Invalid\xFF"]] as $invalid) {
        departmentCheck(is_wp_error(BookingDepartment::saveNew(102, 10, $invalid)), 'Invalid snapshot schemas must not be persisted.');
    }
    $GLOBALS['department_test_meta'][102][$meta] = [(new BookingDepartmentCipher(RoomAccess::$context, 102))
        ->encrypt(['id' => $department['id'], 'name' => 'Valid', 'extra' => 'unexpected'], 10)];
    departmentCheck(is_wp_error(BookingDepartment::read(102)), 'Authenticated but unsupported payload shapes must not be exposed.');

    define('RRZE_APPOINTMENT_ENCRYPTION_KEY', base64_encode(str_repeat('appointment-test', 4)));
    departmentCheck(BookingDepartment::read(100) === $department, 'Adding the Appointment key must preserve old salt-based snapshots.');
    departmentCheck(BookingDepartment::saveNew(103, 10, $department) === true, 'New snapshots must use the configured Appointment key.');
    $appointment = json_decode(substr(get_post_meta(103, $meta, true), strlen(BookingDepartmentCipher::PREFIX)), true);
    departmentCheck($appointment['key_source'] === EncryptionKey::APPOINTMENT, 'The envelope must pin the newly selected source.');
    $GLOBALS['department_test_salt'] = 'changed-authentication-salt';
    departmentCheck(is_wp_error(BookingDepartment::read(100)) && BookingDepartment::read(103) === $department,
        'Changing the old key must fail explicitly without affecting independently keyed snapshots.');
    unset($GLOBALS['department_test_salt']);
    $GLOBALS['wpdb']->last_error = 'private database diagnostic';
    departmentCheck(is_wp_error(BookingDepartment::read(104)), 'Database errors must not become an absent snapshot.');
    $GLOBALS['wpdb']->last_error = '';

    $admin = new BookingDepartmentAdmin();
    $admin->onLoaded();
    departmentCheck(array_keys($GLOBALS['department_test_hooks']) === ['add_meta_boxes_booking'],
        'The snapshot display must not register any mutation or public-exposure hook.');
    $admin->register($GLOBALS['department_test_posts'][100]);
    departmentCheck(count($GLOBALS['department_test_boxes']) === 1, 'An authorized editor must receive the read-only metabox.');
    $html = departmentRender($admin, $GLOBALS['department_test_posts'][100]);
    departmentCheck(str_contains($html, 'Forschung &amp; Entwicklung') && !str_contains($html, '<input')
        && !str_contains($html, '<select') && !str_contains($html, $stored), 'The metabox must render escaped immutable text only.');
    $GLOBALS['department_test_editable'] = [];
    $admin->register($GLOBALS['department_test_posts'][100]);
    departmentCheck(count($GLOBALS['department_test_boxes']) === 1 && departmentRender($admin, $GLOBALS['department_test_posts'][100]) === '',
        'Registering and directly rendering the metabox both require object-level permission.');
    $GLOBALS['department_test_editable'] = [100, 101, 104, 105];
    Functions::$courses = [100];
    departmentCheck(departmentRender($admin, $GLOBALS['department_test_posts'][100]) === '', 'Course registrations must not expose this room snapshot box.');
    Functions::$courses = [];
    $GLOBALS['department_test_posts'][105]->post_status = 'auto-draft';
    departmentCheck(departmentRender($admin, $GLOBALS['department_test_posts'][105]) === '', 'Unsaved manual bookings must not receive a snapshot display.');
    departmentCheck(str_contains(departmentRender($admin, $GLOBALS['department_test_posts'][104]), 'No department was recorded'),
        'Historical bookings without a snapshot need an explicit absence message.');
    $html = departmentRender($admin, $GLOBALS['department_test_posts'][101]);
    departmentCheck(str_contains($html, 'role="alert"') && !str_contains($html, 'broken original'),
        'Corrupted snapshots need a clear error without raw metadata disclosure.');

    echo "booking-department: all checks passed\n";
}
