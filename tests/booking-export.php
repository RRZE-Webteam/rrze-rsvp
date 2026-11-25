<?php
/** Standalone export-policy, CSV encoding and download-boundary regressions. */
declare(strict_types=1);

namespace RRZE\RSVP\Access {
    /** Room policy boundary with observable reads, never real participant data. */
    final class RoomAccess
    {
        public static bool $enabled = true;
        public static array $lists = [];
        public static array $reads = [];
        /** @return bool Fixture feature state. */
        public static function isEnabled(): bool { return self::$enabled; }
        /**
         * @param int $id Room ID.
         * @return array|\WP_Error Current fixture assignments.
         */
        public static function readEntries(int $id): array|\WP_Error { self::$reads[] = $id; return self::$lists[$id] ?? []; }
    }
    /** Historical department boundary, independent of current names and permissions. */
    final class BookingDepartment
    {
        public static array $values = [];
        public static array $reads = [];
        /**
         * @param int $id Booking ID.
         * @return array|null|\WP_Error Stored snapshot or injected corruption.
         */
        public static function read(int $id): array|null|\WP_Error { self::$reads[] = $id; return self::$values[$id] ?? null; }
    }
}
namespace RRZE\RSVP {
    /** Observable legacy decryption seam; SQL and encryption are covered in WordPress integration. */
    final class Functions
    {
        public static int $decryptions = 0;
        /**
         * @param string $value Ciphertext.
         * @param string $action Requested operation.
         * @return string|false Fixture plaintext or error.
         */
        public static function crypt(string $value, string $action = 'encrypt'): string|false
        {
            self::$decryptions++;
            return str_starts_with($value, 'enc:') ? base64_decode(substr($value, 4), true) : false;
        }
    }
}
namespace {
    use RRZE\RSVP\Access\{BookingDepartment, RoomAccess};
    use RRZE\RSVP\Export\{Admin, BookingExport, CsvWriter};
    use RRZE\RSVP\Functions;

    define('ABSPATH', dirname(__DIR__) . '/');
    define('ARRAY_A', 'ARRAY_A');
    /** Minimal safe WordPress error result. */
    final class WP_Error
    {
        /**
         * @param string $code Failure identifier.
         * @param string $message Display text.
         * @param mixed $data Status metadata.
         */
        public function __construct(private string $code, private string $message = '', public mixed $data = null) {}
        /** @return string Failure code. */
        public function get_error_code(): string { return $this->code; }
        /** @return string Safe text. */
        public function get_error_message(): string { return $this->message; }
    }
    /** ID batches and raw metadata boundary; intentionally does not reimplement SQL filters. */
    final class ExportDatabaseDouble
    {
        public string $posts = 'wp_fixture_posts';
        public string $postmeta = 'wp_fixture_postmeta';
        public string $last_error = '';
        public bool $fail = false;
        public array $reads = [];
        public array $queries = [];
        /**
         * @param string $sql Bound query.
         * @param mixed ...$args Values.
         * @return array Query and parameters for observation.
         */
        public function prepare(string $sql, mixed ...$args): array { return [$sql, $args]; }
        /**
         * @param string $sql Upper-bound query.
         * @return int Largest candidate ID.
         */
        public function get_var(string $sql): int { $this->last_error = $this->fail ? 'injected' : ''; return max([0, ...array_keys($GLOBALS['export_posts'])]); }
        /**
         * @param array $query Query and parameters.
         * @return array Next stable candidate batch.
         */
        public function get_col(array $query): array
        {
            $this->queries[] = $query;
            $this->last_error = $this->fail ? 'injected' : '';
            [$after, $ceiling] = $query[1];
            $ids = [];
            foreach ($GLOBALS['export_posts'] as $id => $post) {
                if ($id > $after && $id <= $ceiling && $post->post_type === 'booking') $ids[] = $id;
            }
            sort($ids);
            return array_slice($ids, 0, end($query[1]));
        }
        /**
         * @param array $query Query and keys.
         * @param string $format Result format.
         * @return array Requested raw values.
         */
        public function get_results(array $query, string $format): array
        {
            $this->last_error = $this->fail ? 'injected' : '';
            $id = $query[1][0];
            $keys = array_slice($query[1], 1);
            $this->reads[] = [$id, $keys];
            $rows = [];
            foreach ($keys as $key) {
                foreach ($GLOBALS['export_meta'][$id][$key] ?? [] as $value) $rows[] = ['meta_key' => $key, 'meta_value' => $value];
            }
            return $rows;
        }
    }
    /**
     * @param string $text Source.
     * @param string $domain Domain.
     * @return string Test text.
     */
    function __(string $text, string $domain = ''): string { return $text; }
    /**
     * @param string $text Source.
     * @param string $context Context.
     * @param string $domain Domain.
     * @return string Test label.
     */
    function _x(string $text, string $context, string $domain): string { return $text; }
    /**
     * @param mixed $value Candidate result.
     * @return bool Whether it is an error.
     */
    function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
    /** @return DateTimeImmutable Fixed website-local calendar date near DST. */
    function current_datetime(): DateTimeImmutable { return new DateTimeImmutable('2026-03-29 12:00:00', new DateTimeZone('Europe/Berlin')); }
    /**
     * @param int $id Post ID.
     * @return object|null Fixture post.
     */
    function get_post(int $id): ?object { return $GLOBALS['export_posts'][$id] ?? null; }
    /**
     * @param string $cap Capability.
     * @param int $id Optional object.
     * @return bool Fixture permissions.
     */
    function current_user_can(string $cap, int $id = 0): bool
    {
        return $cap === 'edit_bookings' ? $GLOBALS['export_allowed'] : !in_array($id, $GLOBALS['export_denied'], true);
    }
    /**
     * @param string $nonce Submitted token.
     * @param string $action Expected action.
     * @return bool Correct export nonce.
     */
    function wp_verify_nonce(string $nonce, string $action): bool { return $nonce === 'valid' && $action === 'rrze-rsvp-export-bookings'; }

    require_once dirname(__DIR__) . '/includes/Export/BookingExport.php';
    require_once dirname(__DIR__) . '/includes/Export/CsvWriter.php';
    require_once dirname(__DIR__) . '/includes/Export/Admin.php';

    /**

     * @param bool $ok Assertion.

     * @param string $message Failure description without personal data.

     * @return void

     */
    function exportCheck(bool $ok, string $message): void
    {
        $GLOBALS['export_checks']++;
        if (!$ok) throw new RuntimeException($message);
    }
    /**
     * @param int $id Booking ID.
     * @param string $start Local date/time.
     * @param string|null $end Local end or absent.
     * @param string $status Booking state.
     * @param int $seat Seat reference.
     * @return void
     */
    function exportFixture(int $id, string $start, ?string $end, string $status = 'confirmed', int $seat = 20): void
    {
        $GLOBALS['export_posts'][$id] = (object) ['ID' => $id, 'post_type' => 'booking', 'post_status' => 'publish'];
        $GLOBALS['export_meta'][$id] = [
            'rrze-rsvp-booking-start' => [(string) strtotime($start . ' UTC')],
            'rrze-rsvp-booking-status' => [$status], 'rrze-rsvp-booking-seat' => [(string) $seat],
            'rrze-rsvp-booking-guest-firstname' => ['enc:' . base64_encode('Änne')],
            'rrze-rsvp-booking-guest-lastname' => ['enc:' . base64_encode('Test; "West"')],
            'rrze-rsvp-booking-guest-email' => ['enc:' . base64_encode('anne@example.test')],
        ];
        if ($end !== null) $GLOBALS['export_meta'][$id]['rrze-rsvp-booking-end'] = [(string) strtotime($end . ' UTC')];
    }
    /**
     * @param array $override Raw fields.
     * @return array Complete native form submission.
     */
    function exportInput(array $override = []): array
    {
        return array_replace(BookingExport::defaults(), ['from' => '2026-03-29', 'to' => '2026-03-29', '_wpnonce' => 'valid'], $override);
    }

    $GLOBALS['export_checks'] = 0;
    $GLOBALS['export_allowed'] = true;
    $GLOBALS['export_denied'] = [];
    $GLOBALS['export_posts'] = [
        10 => (object) ['ID' => 10, 'post_type' => 'room', 'post_status' => 'publish', 'post_title' => 'Room; "West"'],
        11 => (object) ['ID' => 11, 'post_type' => 'room', 'post_status' => 'publish', 'post_title' => 'Other'],
        20 => (object) ['ID' => 20, 'post_type' => 'seat', 'post_status' => 'publish', 'post_title' => 'Seat 1'],
        21 => (object) ['ID' => 21, 'post_type' => 'seat', 'post_status' => 'publish', 'post_title' => 'Seat 2'],
    ];
    $GLOBALS['export_meta'] = [20 => ['rrze-rsvp-seat-room' => ['10']], 21 => ['rrze-rsvp-seat-room' => ['11']]];
    $wpdb = new ExportDatabaseDouble();
    exportFixture(100, '2026-03-29 00:00:00', '2026-03-29 00:30:00');
    exportFixture(101, '2026-03-29 09:00:00', '2026-03-29 10:00:00', 'cancelled');
    exportFixture(102, '2026-03-29 23:59:59', null, 'booked', 21);
    exportFixture(103, '2026-03-30 00:00:00', null);
    exportFixture(104, '2026-03-28 23:59:59', null);
    exportFixture(105, '2026-03-29 12:00:00', null);
    $GLOBALS['export_meta'][105]['rrze-rsvp-booking-course-date'] = ['50'];
    exportFixture(106, '2026-03-29 12:00:00', null);
    $GLOBALS['export_posts'][106]->post_status = 'draft';
    exportFixture(107, '2026-03-29 12:00:00', null);
    $GLOBALS['export_posts'][107]->post_status = 'trash';
    exportFixture(108, '2026-03-29 12:00:00', null);
    $GLOBALS['export_denied'] = [108];
    BookingDepartment::$values[108] = new WP_Error('must_not_read');
    $service = new BookingExport();
    $document = $service->prepare(exportInput());
    exportCheck(!is_wp_error($document) && array_column($document['rows'], 0) === [100, 101, 102], 'Both date boundaries, course/draft/trash exclusion and object rights apply.');
    exportCheck($document['rows'][0][3] === '2026-03-29 00:00:00' && $document['rows'][0][5] === '30', 'Local wall-clock times and numeric minutes are not shifted by DST.');
    exportCheck($document['rows'][2][4] === '' && $document['rows'][2][5] === '', 'Unknown ends do not invent times or durations.');
    exportCheck(Functions::$decryptions === 0 && !in_array('First name', $document['headers'], true), 'Personal data is neither decrypted nor included by default.');
    foreach ($wpdb->reads as [$id, $keys]) exportCheck($id !== 108 && !in_array('rrze-rsvp-booking-guest-email', $keys, true), 'Unauthorized or unrequested personal metadata is never read.');
    exportCheck(!in_array(108, BookingDepartment::$reads, true), 'Unauthorized departments are not read.');
    exportCheck(BookingExport::defaults()['from'] === '2026-03-02', 'The initial period contains 28 local calendar days including today.');
    $selected = $service->prepare(exportInput(['room_id' => '10', 'status' => 'cancelled']));
    exportCheck(array_column($selected['rows'], 0) === [101], 'Room and status selections are respected.');
    exportCheck(str_contains($wpdb->queries[1][0], 'wp_fixture_posts') && !str_contains($wpdb->queries[1][0], 'cancelled'), 'SQL uses current-site tables and bound filter values.');

    foreach ([['from' => '2026-02-30'], ['to' => '2026-03-28'], ['from' => ['injected']], ['room_id' => '-1'],
        ['room_id' => '9999'], ['room_id' => '20'], ['status' => 'sql'], ['sort' => 'email'], ['include_personal' => ['1']]] as $invalid) {
        exportCheck(is_wp_error($service->prepare(exportInput($invalid))), 'Malformed filter is rejected before a download.');
    }
    $admin = new Admin();
    $GLOBALS['export_allowed'] = false;
    exportCheck($admin->submit(exportInput())->get_error_code() === 'booking_export_forbidden', 'Direct POST requires booking-management permission.');
    exportCheck($service->prepare(exportInput())->get_error_code() === 'booking_export_forbidden', 'Service also enforces permission independently.');
    $GLOBALS['export_allowed'] = true;
    exportCheck($admin->submit(exportInput(['_wpnonce' => 'wrong']))->get_error_code() === 'booking_export_nonce', 'Forged nonce cannot download.');
    exportCheck($admin->submit(exportInput(['from' => '2027-01-01', 'to' => '2027-01-02']))->get_error_code() === 'booking_export_empty', 'No matches produce feedback, not a misleading empty file.');

    $GLOBALS['export_posts'][11]->post_status = 'trash';
    RoomAccess::$lists[11] = new WP_Error('inactive_room');
    RoomAccess::$reads = [];
    $document = $service->prepare(exportInput());
    exportCheck(is_array($document) && count($document['rows']) === 3 && !in_array(11, RoomAccess::$reads, true),
        'Trashed rooms retain exportable bookings without reading inactive permission lists.');
    $GLOBALS['export_posts'][11]->post_status = 'publish';
    RoomAccess::$lists[11] = [];
    $GLOBALS['export_meta'][102]['rrze-rsvp-booking-seat'] = ['0'];
    $document = $service->prepare(exportInput());
    exportCheck(is_array($document) && $document['rows'][2][1] === '' && $document['rows'][2][2] === '',
        'Absent legacy seat references leave labels blank without losing bookings.');
    $GLOBALS['export_meta'][102]['rrze-rsvp-booking-seat'] = ['21'];

    BookingDepartment::$values[100] = ['id' => 'historic', 'name' => 'Former Physics'];
    RoomAccess::$lists[10] = new WP_Error('current_list_broken');
    RoomAccess::$reads = [];
    $document = $service->prepare(exportInput(['include_personal' => '1', 'sort' => 'department']));
    exportCheck(end($document['headers']) === 'Department' && $document['rows'][0][10] === 'Former Physics', 'Historical department determines the column and its exact name.');
    exportCheck(RoomAccess::$reads === [] && $document['rows'][1][10] === '', 'History avoids unnecessary current-list reads and leaves unassigned rows blank.');
    exportCheck($document['rows'][0][7] === 'Änne' && Functions::$decryptions === 9, 'Explicit personal option decrypts only permitted matching bookings.');
    $csv = CsvWriter::write($document);
    exportCheck(is_string($csv) && str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, "\r\n"), 'UTF-8 BOM and CRLF records are emitted.');
    $memory = fopen('php://memory', 'w+b');
    fwrite($memory, substr($csv, 3)); rewind($memory);
    $parsed = [];
    while (($line = fgetcsv($memory, null, ';', '"', '')) !== false) $parsed[] = $line;
    fclose($memory);
    exportCheck($parsed[1][1] === 'Room; "West"' && $parsed[1][8] === 'Test; "West"', 'CSV round-trips semicolons, quotes and umlauts.');
    $file = $admin->submit(exportInput());
    exportCheck(is_array($file) && $file['filename'] === 'rsvp-bookings-2026-03-29_2026-03-29.csv', 'Download boundary returns one complete file with a non-personal filename.');

    BookingDepartment::$values[100] = null;
    $document = $service->prepare(exportInput());
    exportCheck(is_wp_error($document), 'Unreadable required current room list is never silently treated as unassigned.');
    RoomAccess::$lists[10] = [['email' => 'current@example.test', 'department_id' => 'new']];
    $document = $service->prepare(exportInput());
    exportCheck(end($document['headers']) === 'Department' && $document['rows'][0][7] === '', 'Current assignments include the column without retroactively assigning historical bookings.');
    BookingDepartment::$values[100] = new WP_Error('snapshot_broken');
    exportCheck(is_wp_error($admin->submit(exportInput())), 'Broken historical data prevents all CSV output.');
    RoomAccess::$enabled = false;
    BookingDepartment::$reads = RoomAccess::$reads = [];
    $document = $service->prepare(exportInput());
    exportCheck(!is_wp_error($document) && !in_array('Department', $document['headers'], true)
        && BookingDepartment::$reads === [] && RoomAccess::$reads === [], 'Disabled permissions omit and do not read encrypted department data.');
    exportCheck(is_wp_error($service->prepare(exportInput(['sort' => 'department']))), 'A stale department sort requires correcting the disabled configuration.');
    $GLOBALS['export_meta'][100]['rrze-rsvp-booking-guest-email'] = ['broken'];
    exportCheck(!is_wp_error($service->prepare(exportInput())) && is_wp_error($service->prepare(exportInput(['include_personal' => '1']))), 'Personal decryption failure only blocks a requested personal export.');
    $GLOBALS['export_meta'][100]['rrze-rsvp-booking-start'][] = '999';
    exportCheck(is_wp_error($service->prepare(exportInput())), 'Duplicate required metadata aborts the export.');
    array_pop($GLOBALS['export_meta'][100]['rrze-rsvp-booking-start']);
    $wpdb->fail = true;
    exportCheck(is_wp_error($service->prepare(exportInput())), 'Database failures do not become empty reports.');
    $wpdb->fail = false;

    foreach (['=1+1', '+SUM(A1)', '-cmd', '@SUM(A1)', " \t=2", "\ntext", "\u{FEFF}=1"] as $value) {
        $csv = CsvWriter::write(['headers' => ['Value'], 'rows' => [[$value]]]);
        exportCheck(is_string($csv) && str_contains($csv, "'" . $value), 'Spreadsheet formula prefixes are retained safely as text.');
    }
    $csv = CsvWriter::write(['headers' => ['Value'], 'rows' => [["First\nSecond"]]]);
    exportCheck(is_string($csv) && str_contains($csv, '"First' . "\n" . 'Second"'), 'Embedded line breaks stay quoted within a field.');
    exportCheck(is_wp_error(CsvWriter::write(['headers' => ['Value'], 'rows' => [["\xC3"]]])), 'Invalid UTF-8 cannot produce a misleading file.');

    // Actual full-size boundary and cursor iteration, without a test-only production limit.
    $GLOBALS['export_posts'] = array_intersect_key($GLOBALS['export_posts'], array_flip([10, 20]));
    $GLOBALS['export_meta'] = [20 => ['rrze-rsvp-seat-room' => ['10']]];
    $GLOBALS['export_denied'] = [];
    $wpdb->reads = $wpdb->queries = [];
    for ($id = 100; $id < 10100; $id++) exportFixture($id, '2026-03-29 12:00:00', null);
    $document = $service->prepare(exportInput());
    exportCheck(!is_wp_error($document) && count($document['rows']) === 10000, 'Exactly 10,000 rows are exported across all batches.');
    exportFixture(10100, '2026-03-29 12:00:00', null);
    exportCheck($service->prepare(exportInput())->get_error_code() === 'booking_export_limit', 'The 10,001st authorized match aborts rather than truncating.');
    echo 'Booking export checks passed (' . $GLOBALS['export_checks'] . ").\n";
}
