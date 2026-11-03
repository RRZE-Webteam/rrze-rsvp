<?php
/** Standalone boundary tests for block resources, passive previews and instance routing. */
declare(strict_types=1);

namespace RRZE\RSVP {
    /** Supplies anonymous room availability and records its requested date window. */
    class Functions
    {
        public static array $availability = [];
        public static array $calls = [];

        /** @param mixed ...$args Reader arguments. @return array Anonymous seat IDs by date and time. */
        public static function getRoomAvailability(...$args): array
        {
            self::$calls[] = $args;
            return self::$availability;
        }
    }
}

namespace RRZE\RSVP\Courses {
    /** Course reader double with an injectable storage failure. */
    class Repository
    {
        public static array $dates = [];
        public static bool $fail = false;

        /** @param bool $strict Strict read mode. @return bool Previous mode. */
        public function setStrictReads(bool $strict): bool { return false; }

        /** @param int $id Course ID. @param bool $futureOnly Future reader flag. @return array Occurrence fixtures. */
        public function getOccurrences(int $id, bool $futureOnly = false): array
        {
            if (self::$fail) {
                throw new \RuntimeException('Database details must not escape the preview.');
            }
            return self::$dates;
        }
    }

    /** Read-only availability fixture; no booking or mail methods are supplied. */
    class BookingService
    {
        public static array $availability = [];
        public static array $calls = [];

        /** @param Repository $repository Reader collaborator. */
        public function __construct(Repository $repository) {}

        /** @param int $id Occurrence ID. @return array|\WP_Error Anonymous capacity or an injected reader error. */
        public function availability(int $id)
        {
            self::$calls[] = $id;
            return self::$availability[$id];
        }
    }

    /** A live frontend renderer must never be constructed by the passive preview. */
    class Frontend
    {
        /** @throws \RuntimeException If editor code attempts to use the live frontend. */
        public function __construct() { throw new \RuntimeException('Live frontend used by passive preview.'); }
    }
}

namespace {
    define('ABSPATH', dirname(__DIR__) . '/');

    /** Minimal post DTO with the fields needed at this boundary. */
    class WP_Post
    {
        public string $post_content = '';
        public string $post_password = '';

        /** @param int $ID ID. @param string $post_type Resource type. @param string $post_status Publication status. @param string $post_title Title. */
        public function __construct(public int $ID, public string $post_type, public string $post_status = 'publish', public string $post_title = 'Test offer') {}
    }

    /** WordPress error boundary value. */
    class WP_Error
    {
        /** @param string $code Error code. @param string $message Human-readable explanation. @param array $data Status metadata. */
        public function __construct(public string $code, public string $message, public array $data = []) {}
    }

    /** Incoming REST parameters without an authenticated browser session. */
    class WP_REST_Request
    {
        /** @param array $params Route and query parameters. */
        public function __construct(private array $params) {}
        /** @param string $key Parameter name. @return mixed Parameter value. */
        public function get_param(string $key) { return $this->params[$key] ?? null; }
    }

    /** Response recorder retaining the private cache policy. */
    class WP_REST_Response
    {
        /** @param array $data Payload. @param int $status HTTP status. @param array $headers Cache headers. */
        public function __construct(public array $data, public int $status = 200, public array $headers = []) {}
    }

    /** Records the bounded query contract; object-level filtering remains production code. */
    class WP_Query
    {
        public array $posts;
        public int $max_num_pages;

        /** @param array $args WordPress query parameters. */
        public function __construct(array $args)
        {
            $GLOBALS['block_test_query'] = $args;
            $this->posts = $GLOBALS['block_test_query_posts'];
            $this->max_num_pages = $GLOBALS['block_test_query_pages'];
        }
    }

    /** DOM-backed implementation of the tag-processor boundary, not its parsing internals. */
    class WP_HTML_Tag_Processor
    {
        private DOMDocument $document;
        private array $elements;
        private int $index = -1;

        /** @param string $html Fragment to inspect. */
        public function __construct(string $html)
        {
            $this->document = new DOMDocument();
            @$this->document->loadHTML('<html><body>' . $html . '</body></html>');
            $this->elements = iterator_to_array($this->document->getElementsByTagName('*'));
        }

        /** @return bool Whether another HTML element exists. */
        public function next_tag(): bool { return ++$this->index < count($this->elements); }
        /** @param string $name Attribute name. @return string|null Present attribute value. */
        public function get_attribute(string $name): ?string
        {
            $node = $this->elements[$this->index];
            return $node->hasAttribute($name) ? $node->getAttribute($name) : null;
        }
        /** @param string $name Attribute name. @param string $value Replacement value. @return void */
        public function set_attribute(string $name, string $value): void { $this->elements[$this->index]->setAttribute($name, $value); }
        /** @return string Updated fragment without the test document wrapper. */
        public function get_updated_html(): string
        {
            $html = '';
            foreach ($this->document->getElementsByTagName('body')->item(0)->childNodes as $node) {
                $html .= $this->document->saveHTML($node);
            }
            return $html;
        }
    }

    /** @param bool $condition Required condition. @param string $message Failure context. @return void */
    function blockAssert(bool $condition, string $message): void
    {
        if (!$condition) { throw new RuntimeException($message); }
    }
    /** @param mixed $value Candidate result. @return bool Whether this is an error. */
    function is_wp_error($value): bool { return $value instanceof WP_Error; }
    /** @param string $text Source string. @param string $domain Translation domain. @return string Source string. */
    function __(string $text, string $domain): string { return $text; }
    /** @param mixed $value Attribute or text. @return string HTML-escaped value. */
    function esc_attr($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
    /** @param mixed $value Text. @return string HTML-escaped value. */
    function esc_html($value): string { return esc_attr($value); }
    /** @param string $text Source string. @param string $domain Domain. @return string Escaped source. */
    function esc_html__(string $text, string $domain): string { return esc_html($text); }
    /** @param mixed $value Integer-like value. @return int Absolute ID. */
    function absint($value): int { return abs((int) $value); }
    /** @param mixed $value JSON payload. @return string Encoded JSON. */
    function wp_json_encode($value): string { return json_encode($value, JSON_THROW_ON_ERROR); }
    /** @param int|WP_Post $id Resource reference. @return WP_Post|null Fixture post. */
    function get_post($id): ?WP_Post { return $id instanceof WP_Post ? $id : ($GLOBALS['block_test_posts'][$id] ?? null); }
    /** @param int $id Post ID. @param string $key Metadata key. @param bool $single Single-value flag. @return mixed Fixture metadata. */
    function get_post_meta(int $id, string $key, bool $single = false) { return $GLOBALS['block_test_meta'][$id][$key] ?? ''; }
    /** @param string $type Post type. @return object|null Type capability object. */
    function get_post_type_object(string $type): ?object
    {
        $caps = ['room' => 'edit_rooms', 'rsvp_course' => 'edit_rsvp_courses'];
        return isset($caps[$type]) ? (object) ['cap' => (object) ['edit_posts' => $caps[$type]]] : null;
    }
    /** @param string $cap Capability. @param int|null $id Optional object ID. @return bool Fixture authorization. */
    function current_user_can(string $cap, ?int $id = null): bool
    {
        return $cap === 'edit_post' ? in_array($id, $GLOBALS['block_test_editable'], true) : in_array($cap, $GLOBALS['block_test_caps'], true);
    }
    /** @param int $id Post ID. @param string $context URL context. @return string Local editor address. */
    function get_edit_post_link(int $id, string $context = ''): string { return 'https://example.test/wp-admin/post.php?post=' . $id . '&action=edit'; }
    /** @param mixed $text Input value. @return string Trimmed plain text. */
    function sanitize_text_field($text): string { return is_scalar($text) ? trim(strip_tags((string) $text)) : ''; }
    /** @param string $text HTML input. @return string Plain text. */
    function wp_strip_all_tags(string $text): string { return strip_tags($text); }
    /** @param string $text Content. @return string Content with fixture shortcode removed without execution. */
    function strip_shortcodes(string $text): string { return str_replace('[side_effect]', '', $text); }
    /** @param string $name Option name. @return string Fixture date/time format. */
    function get_option(string $name): string { return $name === 'date_format' ? 'Y-m-d' : 'H:i'; }
    /** @return DateTimeZone Site timezone. */
    function wp_timezone(): DateTimeZone { return new DateTimeZone('Europe/Berlin'); }
    /** @return DateTimeImmutable Stable date to exercise the month boundary and booking horizon. */
    function current_datetime(): DateTimeImmutable { return new DateTimeImmutable('2026-11-03 12:00:00', wp_timezone()); }
    /** @param string $format Display format. @param int $timestamp UTC timestamp. @return string Site-local date. */
    function wp_date(string $format, int $timestamp): string { return (new DateTimeImmutable('@' . $timestamp))->setTimezone(wp_timezone())->format($format); }
    /** @param string $namespace Namespace. @param string $route Path. @param array $args Route definition. @return void */
    function register_rest_route(string $namespace, string $route, array $args): void { $GLOBALS['block_test_routes'][$namespace . $route] = $args; }
    /** @param string $action Purpose and binding. @return string Deterministic, action-bound test nonce. */
    function wp_create_nonce(string $action): string { return hash_hmac('sha256', $action, 'test-secret'); }
    /** @param string $nonce Submitted token. @param string $action Required purpose. @return bool Whether the action matches. */
    function wp_verify_nonce(string $nonce, string $action): bool { return hash_equals(wp_create_nonce($action), $nonce); }
    /** @param int|WP_Post $post Source post. @return string Trusted canonical page URL. */
    function get_permalink($post): string { return 'https://example.test/page/' . ($post instanceof WP_Post ? $post->ID : $post) . '/'; }
    /** @param array $params Query parameters. @param string $url Local base URL. @return string URL with encoded parameters. */
    function add_query_arg(array $params, string $url): string { return $url . '?' . http_build_query($params); }
    /** @param string $content Serialized fixture identifier. @return array Parsed block boundary fixture. */
    function parse_blocks(string $content): array { return $GLOBALS['block_test_parsed'][$content] ?? []; }
    /** @return WP_Post|null Queried page fixture. */
    function get_queried_object(): ?WP_Post { return $GLOBALS['block_test_queried'] ?? null; }
    /** @return int Queried page ID. */
    function get_queried_object_id(): int { return get_queried_object()?->ID ?? 0; }
    /** @return int Current page ID. */
    function get_the_ID(): int { return get_queried_object_id(); }
    /** @return void Record early cache exclusion. */
    function nocache_headers(): void { $GLOBALS['block_test_nocache'] = true; }
    /** @param mixed ...$args Unexpected persistence parameters. @return never */
    function update_post_meta(...$args): never { throw new RuntimeException('Preview attempted a metadata write.'); }
    /** @param mixed ...$args Unexpected persistence parameters. @return never */
    function wp_insert_post(...$args): never { throw new RuntimeException('Preview attempted a post write.'); }
    /** @param mixed ...$args Unexpected mail parameters. @return never */
    function wp_mail(...$args): never { throw new RuntimeException('Preview attempted to send email.'); }
    /** @param mixed ...$args Unexpected shortcode parameters. @return never */
    function do_shortcode(...$args): never { throw new RuntimeException('Preview attempted to execute a shortcode.'); }

    require dirname(__DIR__) . '/includes/Courses/Model.php';
    require dirname(__DIR__) . '/includes/Blocks/Resources.php';
    require dirname(__DIR__) . '/includes/Blocks/Editor.php';
    require dirname(__DIR__) . '/includes/Blocks/Context.php';
    require dirname(__DIR__) . '/includes/Blocks/BookingBlock.php';

    use RRZE\RSVP\Blocks\{Resources, Editor, Context, BookingBlock};
    use RRZE\RSVP\Courses\{Repository, BookingService};

    $GLOBALS['block_test_posts'] = [
        10 => new WP_Post(10, 'page'), 11 => new WP_Post(11, 'page'),
        20 => new WP_Post(20, 'room'), 21 => new WP_Post(21, 'room', 'draft'),
        22 => new WP_Post(22, 'room'), 23 => new WP_Post(23, 'room'),
        24 => new WP_Post(24, 'room'), 25 => new WP_Post(25, 'room', 'trash'),
        26 => new WP_Post(26, 'room'), 30 => new WP_Post(30, 'rsvp_course'),
        31 => new WP_Post(31, 'rsvp_course', 'draft'), 32 => new WP_Post(32, 'rsvp_course', 'auto-draft'),
    ];
    $GLOBALS['block_test_posts'][26]->post_password = 'protected';
    $GLOBALS['block_test_posts'][20]->post_title = '<b>Room title</b>';
    $GLOBALS['block_test_posts'][20]->post_content = '<p>Passive description</p>[side_effect]';
    $GLOBALS['block_test_meta'] = [];
    foreach ([20 => 'reservation', 21 => 'reservation', 22 => 'consultation', 23 => 'no-check', 24 => 'check-only', 25 => 'reservation', 26 => 'reservation'] as $id => $mode) {
        $GLOBALS['block_test_meta'][$id] = ['rrze-rsvp-room-bookingmode' => $mode, 'rrze-rsvp-room-days-in-advance' => 2];
    }
    $GLOBALS['block_test_caps'] = [];
    $GLOBALS['block_test_editable'] = [20, 21, 22, 23, 26, 30, 31];
    $GLOBALS['block_test_query_posts'] = [get_post(20), get_post(21), get_post(24)];
    $GLOBALS['block_test_query_pages'] = 3;
    $editor = new Editor();

    blockAssert(!Resources::canEdit('seat') && !Resources::canEdit('course'), 'Selecting an offer requires its management capability.');
    $GLOBALS['block_test_caps'] = ['edit_rooms'];
    blockAssert(Resources::canEdit('seat') && Resources::canEdit('consultation') && !Resources::canEdit('course'), 'Room access must not grant course management.');
    blockAssert(!Resources::canEdit('anything'), 'Unknown modes never inherit room permissions.');
    $GLOBALS['block_test_caps'][] = 'edit_rsvp_courses';
    foreach ([[20, 'seat', true], [20, 'consultation', false], [22, 'consultation', true], [23, 'seat', true], [24, 'seat', false], [10, 'seat', false], [30, 'course', true], [30, 'seat', false], [21, 'seat', false], [25, 'seat', false], [26, 'seat', false], [999, 'seat', false]] as [$id, $mode, $expected]) {
        blockAssert(Resources::matches($id, $mode) === $expected, 'Published resource/type/mode boundary failed for ' . $id . ':' . $mode);
    }
    blockAssert(Resources::matches(21, 'seat', false) && Resources::matches(31, 'course', false), 'Authorized editors can preview drafts.');
    blockAssert(!Resources::matches(25, 'seat', false) && !Resources::matches(32, 'course', false), 'Trash and automatic drafts are never preview offers.');
    blockAssert(Resources::label(get_post(20))['title'] === 'Room title', 'Resource titles must not inject markup into the selector.');

    $editor->registerRoutes();
    foreach ($GLOBALS['block_test_routes'] as $route) {
        blockAssert($route['methods'] === 'GET' && is_callable($route['permission_callback']), 'Editor endpoints must be private reads.');
        blockAssert($route['args']['mode']['enum'] === ['course', 'consultation', 'seat'], 'Routes expose only the three supported booking modes.');
    }
    $GLOBALS['block_test_caps'] = [];
    blockAssert($editor->permission(new WP_REST_Request(['mode' => 'seat']))->data['status'] === 403, 'Unauthorized editor reads are rejected.');
    $GLOBALS['block_test_caps'] = ['edit_rooms', 'edit_rsvp_courses'];
    blockAssert($editor->permission(new WP_REST_Request(['mode' => 'seat'])) === true, 'Authorized room editor can use the reader.');
    $result = $editor->resources(new WP_REST_Request(['mode' => 'seat', 'search' => '<b>Room</b>', 'page' => 2]));
    blockAssert(array_column($result->data['items'], 'id') === [20, 21], 'Object-level rights filter published and draft search results.');
    blockAssert($result->data['hasMore'] === true && !isset($result->data['total']), 'Pagination must not expose inaccessible resource totals.');
    blockAssert($GLOBALS['block_test_query']['posts_per_page'] === 20 && $GLOBALS['block_test_query']['paged'] === 2, 'Resource search must remain bounded and pageable.');
    blockAssert($GLOBALS['block_test_query']['meta_query'][0]['value'] === ['reservation', 'no-check'], 'Seat searches exclude consultation and check-in-only rooms.');
    blockAssert($GLOBALS['block_test_query']['s'] === 'Room', 'Search text is sanitized before querying.');
    blockAssert($result->headers['Cache-Control'] === 'private, no-store, max-age=0', 'Editor responses are not publicly cacheable.');

    RRZE\RSVP\Functions::$availability = ['2026-11-02' => ['08:00-09:00' => [88]],
        '2026-11-04' => ['09:00-09:30' => [80, 81], '09:30-10:00' => []], '2026-11-07' => ['10:00-11:00' => [82]]];
    $room = $editor->preview(new WP_REST_Request(['mode' => 'seat', 'id' => 20]));
    blockAssert($room instanceof WP_REST_Response && $room->data['description'] === 'Passive description', 'Preview uses plain resource content without executing shortcodes.');
    blockAssert(array_column(array_filter($room->data['days'], static fn(array $day): bool => $day['available']), 'date') === ['2026-11-04'], 'Room preview respects today and the centrally configured booking horizon.');
    blockAssert($room->data['times'] === [['label' => '09:00 – 09:30', 'remaining' => 2]], 'Room preview reveals anonymous capacity, never seat IDs or personal data.');
    blockAssert(RRZE\RSVP\Functions::$calls[0] === [20, '2026-11-03', '2026-11-05', false], 'Preview reader requests only the necessary bounded date range.');
    blockAssert($editor->preview(new WP_REST_Request(['mode' => 'seat', 'id' => 21]))->data['unpublished'], 'Draft preview is explicitly marked as unpublished.');
    blockAssert($editor->preview(new WP_REST_Request(['mode' => 'seat', 'id' => 26]))->data['unpublished'], 'Password protection is visible as a publication restriction.');
    blockAssert($editor->preview(new WP_REST_Request(['mode' => 'seat', 'id' => 22]))->code === 'rsvp_resource_mismatch', 'Changing the central mode invalidates the old block selection.');
    $GLOBALS['block_test_editable'] = [20];
    blockAssert($editor->preview(new WP_REST_Request(['mode' => 'course', 'id' => 30]))->data['status'] === 403, 'Preview requires permission for the selected object.');
    $GLOBALS['block_test_editable'] = [20, 21, 22, 23, 26, 30, 31];

    for ($i = 0; $i < 7; $i++) {
        Repository::$dates[] = ['id' => 100 + $i, 'post_status' => 'publish', 'start' => time() + 3600 * ($i + 1), 'end' => time() + 3600 * ($i + 2), 'location' => 'Room A'];
        BookingService::$availability[100 + $i] = ['bookable' => true, 'free' => 4, 'guest_email' => 'private@example.test', 'token' => 'must-not-leak'];
    }
    Repository::$dates[] = ['id' => 198, 'post_status' => 'draft', 'start' => time() + 3600];
    Repository::$dates[] = ['id' => 199, 'post_status' => 'publish', 'start' => time() - 3600];
    $course = $editor->preview(new WP_REST_Request(['mode' => 'course', 'id' => 30]));
    blockAssert($course instanceof WP_REST_Response && count($course->data['dates']) === 5 && $course->data['moreDates'], 'Course preview is limited to five upcoming published dates.');
    blockAssert($course->data['dates'][0]['remaining'] === 4, 'Course preview must use the real service free-capacity field.');
    blockAssert(!str_contains(wp_json_encode($course->data), 'must-not-leak') && !str_contains(wp_json_encode($course->data), 'guest_email'), 'Preview allowlist must not expose future private service fields.');
    blockAssert(BookingService::$calls === [100, 101, 102, 103, 104], 'Only displayed course dates require capacity reads.');
    BookingService::$availability[100] = new WP_Error('storage_error', 'Reader unavailable', ['status' => 503]);
    blockAssert($editor->preview(new WP_REST_Request(['mode' => 'course', 'id' => 30]))->code === 'storage_error', 'Availability reader failures remain failures rather than misleading empty previews.');
    Repository::$fail = true;
    $failure = $editor->preview(new WP_REST_Request(['mode' => 'course', 'id' => 30]));
    blockAssert($failure->data['status'] === 503 && !str_contains($failure->message, 'Database details'), 'Unexpected reader failures return a safe retryable error.');
    Repository::$fail = false;

    $attributes = ['instanceId' => '12345678-1234-1234-1234-123456789abc', 'mode' => 'seat', 'resourceId' => 20];
    $first = Context::create($attributes, 10);
    $second = Context::create($attributes, 10);
    $otherPage = Context::create($attributes, 11);
    blockAssert($second->instanceKey === $first->instanceKey . '-2' && $second->prefix() !== $first->prefix(), 'Repeated synced-block instances receive distinct deterministic DOM keys.');
    blockAssert($otherPage->instanceKey === $first->instanceKey && $otherPage->prefix() !== $first->prefix(), 'Occurrence allocation is scoped to the source page.');
    blockAssert($first->matches(Context::fromRequest($first->fields())) && !$first->matches($second), 'Signed request context addresses exactly one instance.');
    blockAssert(Context::fromRequest(['room_id' => 20]) === null, 'Legacy requests remain separate from block routing.');
    foreach (['rsvp_source' => 11, 'rsvp_instance' => $second->instanceKey, 'rsvp_mode' => 'consultation', 'rsvp_resource' => 23, 'rsvp_context_nonce' => 'forged'] as $key => $value) {
        $input = array_replace($first->fields(), [$key => $value]);
        blockAssert(Context::fromRequest($input) instanceof WP_Error, 'Changing a signed context field must fail: ' . $key);
    }
    foreach (array_keys($first->fields()) as $key) {
        $input = array_replace($first->fields(), [$key => ['unexpected-array']]);
        blockAssert(Context::fromRequest($input) instanceof WP_Error, 'Array-shaped context fields must be rejected: ' . $key);
    }
    $GLOBALS['block_test_posts'][20]->post_status = 'draft';
    blockAssert(Context::fromRequest($first->fields()) instanceof WP_Error, 'A valid nonce cannot make a newly unpublished room bookable.');
    $GLOBALS['block_test_posts'][20]->post_status = 'publish';
    $GLOBALS['block_test_meta'][20]['rrze-rsvp-room-bookingmode'] = 'consultation';
    blockAssert(Context::fromRequest($first->fields()) instanceof WP_Error, 'A valid nonce cannot override the centrally changed room mode.');
    $GLOBALS['block_test_meta'][20]['rrze-rsvp-room-bookingmode'] = 'reservation';
    blockAssert($first->receiptAction(301) !== $second->receiptAction(301) && $first->receiptAction(301) !== $first->receiptAction(302), 'Receipt authorization binds both booking and instance.');
    $url = $first->url(['rsvp_source' => 999, 'rsvp_resource' => 999, 'redirect' => 'https://attacker.invalid']);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    blockAssert(parse_url($url, PHP_URL_HOST) === 'example.test' && $query['rsvp_source'] === '10' && $query['rsvp_resource'] === '20', 'Return URLs cannot replace the trusted source or resource binding.');
    blockAssert(parse_url($url, PHP_URL_FRAGMENT) === $first->prefix(), 'Return URL targets the originating block.');
    $hidden = new DOMDocument();
    @$hidden->loadHTML($first->hiddenFields());
    blockAssert((new DOMXPath($hidden))->query('//input[@type="hidden"]')->length === 5 && !str_contains($first->hiddenFields(), ' id='), 'Hidden context fields do not duplicate DOM IDs.');
    $fragment = '<label for="email">Email</label><input id="email" name="rsvp_email" aria-describedby="hint error"><p id="hint"></p><p id="error"></p><div id="panel" aria-labelledby="hint" aria-controls="email"></div>';
    $scoped = $first->scopeHtml($fragment);
    $doc = new DOMDocument();
    @$doc->loadHTML($scoped);
    $xpath = new DOMXPath($doc);
    blockAssert($xpath->query('//input[@name="rsvp_email"]')->length === 1, 'Namespacing never changes POST field names.');
    foreach ($xpath->query('//*[@id]') as $element) {
        blockAssert(str_starts_with($element->getAttribute('id'), $first->prefix() . '-'), 'All fragment IDs are scoped to the instance.');
    }
    foreach ($xpath->query('//*[@for or @aria-describedby or @aria-labelledby or @aria-controls]') as $element) {
        foreach (['for', 'aria-describedby', 'aria-labelledby', 'aria-controls'] as $attribute) {
            foreach (preg_split('/\s+/', trim($element->getAttribute($attribute)), -1, PREG_SPLIT_NO_EMPTY) as $id) {
                blockAssert($xpath->query('//*[@id="' . $id . '"]')->length === 1, 'Every accessibility reference resolves within the same instance.');
            }
        }
    }
    blockAssert($first->scopeHtml($scoped) === $scoped, 'Repeated scoping must not add a second prefix.');

    $booking = ['blockName' => 'rrze/rsvp', 'attrs' => [], 'innerBlocks' => []];
    $legacy = ['blockName' => 'rrze-rsvp/course-booking', 'attrs' => [], 'innerBlocks' => []];
    $group = ['blockName' => 'core/group', 'attrs' => [], 'innerBlocks' => [$booking]];
    $referenceA = ['blockName' => 'core/block', 'attrs' => ['ref' => 200], 'innerBlocks' => []];
    $referenceB = ['blockName' => 'core/block', 'attrs' => ['ref' => 201], 'innerBlocks' => []];
    $GLOBALS['block_test_posts'][200] = new WP_Post(200, 'wp_block');
    $GLOBALS['block_test_posts'][201] = new WP_Post(201, 'wp_block');
    $GLOBALS['block_test_posts'][200]->post_content = 'synced-a';
    $GLOBALS['block_test_posts'][201]->post_content = 'synced-b';
    $GLOBALS['block_test_parsed'] = ['direct' => [$booking], 'legacy' => [$legacy], 'nested' => [$group],
        'synced-page' => [$referenceA], 'synced-a' => [$referenceB], 'synced-b' => [$referenceA],
        'empty' => [['blockName' => null, 'attrs' => [], 'innerBlocks' => []]]];
    blockAssert(BookingBlock::containsBooking('direct') && BookingBlock::containsBooking('legacy') && BookingBlock::containsBooking('nested'), 'Cache exclusion detects new, legacy and nested booking blocks.');
    blockAssert(!BookingBlock::containsBooking('synced-page'), 'Cyclic reusable references terminate without a false positive.');
    $GLOBALS['block_test_parsed']['synced-b'][] = $group;
    blockAssert(BookingBlock::containsBooking('synced-page'), 'A booking block after a reusable reference cycle is still detected.');
    unset($GLOBALS['block_test_posts'][201]);
    blockAssert(!BookingBlock::containsBooking('synced-page'), 'Missing reusable references are handled safely.');
    $block = new BookingBlock();
    $GLOBALS['block_test_queried'] = get_post(10);
    $GLOBALS['block_test_queried']->post_content = 'nested';
    blockAssert($block->skipCache(), 'Pages containing nested live forms bypass caches.');
    $GLOBALS['block_test_queried']->post_content = 'empty';
    blockAssert(!$block->skipCache() && $block->skipCache(true), 'Unrelated pages preserve the previous cache decision.');
    blockAssert(count($block->categories([['slug' => 'rrze', 'title' => 'RRZE']])) === 1, 'The shared RRZE inserter category must not be duplicated.');
    blockAssert(BookingBlock::render(['mode' => 'seat', 'resourceId' => 21]) !== '', 'Unavailable selected offers return a neutral public notice.');
    blockAssert(BookingBlock::render(['mode' => '', 'resourceId' => 0]) === '', 'An unconfigured block outputs no live form.');

    echo "Block resource, permission, passive preview, signed context, ID and recursive cache tests passed.\n";
}
