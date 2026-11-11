<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

/** WordPress Tools screen for reviewing and creating seats in a room. */
class Tools
{
    private const PAGE = 'rrze-rsvp-tools';
    private array $rooms = [];
    private array $terms = [];
    private array $settings = SeatGenerator::DEFAULTS;
    private array $errors = [];
    private ?array $plan = null;
    private ?array $receipt = null;

    /**
     * Register the tool with the WordPress admin menu.
     *
     * @return void
     */
    public function onLoaded(): void
    {
        add_action('admin_menu', [$this, 'add_admin_menu']);
    }

    /**
     * Restrict processing and assets to this specific management screen.
     *
     * @return void
     */
    public function add_admin_menu(): void
    {
        $hook = add_management_page(
            _x('Create Seats', 'admin page title', 'rrze-rsvp'),
            _x('RSVP Create Seats', 'admin menu entry title', 'rrze-rsvp'),
            'manage_options', self::PAGE, [$this, 'admin_page']
        );
        if ($hook) {
            add_action("load-$hook", [$this, 'load']);
        }
    }

    /**
     * Read choices and process POST actions before WordPress outputs its header.
     * A receipt redirect makes reloading after publication a read-only action.
     *
     * @return void
     */
    public function load(): void
    {
        if (!current_user_can('manage_options') || !current_user_can('publish_seats')) {
            wp_die(esc_html__('You are not allowed to create seats.', 'rrze-rsvp'), '', ['response' => 403]);
        }
        $assets = 'build/seat-generator';
        wp_enqueue_style('rrze-rsvp-seat-generator', plugins_url("$assets.css", plugin()->getFile()), [], (string) filemtime(dirname(__DIR__) . "/$assets.css"));
        wp_enqueue_script('rrze-rsvp-seat-generator', plugins_url("$assets.js", plugin()->getFile()), [], (string) filemtime(dirname(__DIR__) . "/$assets.js"), true);

        foreach (get_posts(['post_type' => 'room', 'post_status' => 'publish', 'posts_per_page' => -1,
            'orderby' => 'title', 'order' => 'ASC']) as $room) {
            if (current_user_can('edit_post', $room->ID)) {
                $this->rooms[$room->ID] = $room->post_title;
            }
        }
        $terms = get_terms(['taxonomy' => 'rrze-rsvp-equipment', 'hide_empty' => false]);
        if (!is_wp_error($terms) && current_user_can('edit_seats')) {
            $this->terms = wp_list_pluck($terms, 'name', 'term_id');
        }

        $receiptKey = 'rsvp_seats_' . get_current_user_id() . '_';
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $token = $_GET['receipt'] ?? '';
            if (is_string($token) && wp_is_uuid($token)) {
                $receipt = get_transient($receiptKey . $token);
                $this->receipt = is_array($receipt) ? $receipt : null;
            }
            return;
        }

        check_admin_referer('rsvp-seat-generator');
        $post = wp_unslash($_POST);
        $this->settings = SeatGenerator::settings($post);
        if (($post['step'] ?? '') === 'edit') {
            return;
        }
        $this->errors = SeatGenerator::errors($this->settings, $this->rooms, $this->terms);
        if ($this->errors) {
            return;
        }
        if (($post['step'] ?? '') === 'create') {
            $result = SeatGenerator::create($this->settings, is_string($post['review'] ?? null) ? $post['review'] : '');
            if (is_wp_error($result)) {
                $this->errors[] = $result->get_error_message();
            } else {
                $token = wp_generate_uuid4();
                set_transient($receiptKey . $token, $result + ['room' => $this->settings['room_id']], 10 * MINUTE_IN_SECONDS);
                wp_safe_redirect(add_query_arg('receipt', $token, self::url()), 303);
                exit;
            }
        }
        $this->plan = SeatGenerator::plan($this->settings);
    }

    /**
     * Return the canonical form target without results or submitted settings.
     *
     * @return string Local admin URL.
     */
    private static function url(): string
    {
        return admin_url('tools.php?page=' . self::PAGE);
    }

    /**
     * Display the configuration, read-only preview or completed batch.
     *
     * @return void
     */
    public function admin_page(): void
    {
        $s = $this->settings;
        $plan = $this->plan;
        $receipt = $this->receipt;
        require __DIR__ . '/templates/admin/seat-generator.php';
    }
}
