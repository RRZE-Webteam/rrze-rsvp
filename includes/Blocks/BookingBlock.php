<?php

namespace RRZE\RSVP\Blocks;

use RRZE\RSVP\Shortcodes\Bookings;
use RRZE\RSVP\Courses\Frontend;
use function RRZE\RSVP\plugin;

defined('ABSPATH') || exit;

/** Register the unified block and delegate public booking flows to existing adapters. */
final class BookingBlock
{
    private static ?Bookings $rooms = null;

    /** @param Bookings $rooms The already registered room adapter. @return void Register block hooks once. */
    public function onLoaded(Bookings $rooms): void
    {
        self::$rooms = $rooms;
        add_action('init', [$this, 'register'], 30);
        add_action('rest_api_init', [new Editor(), 'registerRoutes']);
        add_filter('block_categories_all', [$this, 'categories']);
        add_action('template_redirect', [$this, 'preventCache'], 0);
        add_action('wp_enqueue_scripts', [$this, 'enqueueStyles'], 20);
        add_filter('rrzecache_skip_cache', [$this, 'skipCache'], 30);
    }

    /** @return void Register metadata, generated dependencies and JavaScript translations. */
    public function register(): void
    {
        register_block_type(plugin()->getPath('build/blocks/rsvp'));
        wp_set_script_translations('rrze-rsvp-editor-script', 'rrze-rsvp', plugin()->getPath('languages'));
    }

    /** @param array $categories Existing editor categories. @return array Categories with one shared RRZE entry. */
    public function categories(array $categories): array
    {
        if (!in_array('rrze', array_column($categories, 'slug'), true)) {
            $categories[] = ['slug' => 'rrze', 'title' => __('RRZE', 'rrze-rsvp')];
        }
        return $categories;
    }

    /**
     * Render one bound offer without allowing request parameters to replace the resource.
     *
     * @param array<string, mixed> $attributes Validated block attributes.
     * @return string Public booking markup or a non-disclosing unavailable notice.
     */
    public static function render(array $attributes): string
    {
        // Editor previews use the private, read-only endpoint, never live forms.
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return '';
        }
        $id = absint($attributes['resourceId'] ?? 0);
        $mode = (string) ($attributes['mode'] ?? '');
        if (!$id || !in_array($mode, Resources::MODES, true)) {
            return '';
        }
        if (!Resources::matches($id, $mode)) {
            return '<p class="rrze-rsvp-unavailable">' . esc_html__('This offer is currently unavailable.', 'rrze-rsvp') . '</p>';
        }
        self::noCache();
        $context = Context::create($attributes, (int) (get_queried_object_id() ?: get_the_ID()));
        $content = $mode === 'course' ? (new Frontend())->render(['courseId' => $id])
            : (self::$rooms ? self::$rooms->renderBlock($context) : '');
        return '<div ' . get_block_wrapper_attributes(['id' => $context->prefix()]) . '>' . $content . '</div>';
    }

    /**
     * Detect booking blocks recursively, including cyclic-safe synced-block references.
     *
     * @param string $content Serialized blocks.
     * @param array<int, bool> $visited Already visited reusable post IDs.
     * @return bool Whether content includes a new or legacy booking block.
     */
    public static function containsBooking(string $content, array $visited = []): bool
    {
        foreach (parse_blocks($content) as $block) {
            if (self::blockContainsBooking($block, $visited)) {
                return true;
            }
        }
        return false;
    }

    /** @param array $block Parsed block. @param array $visited Synced references. @return bool Booking presence. */
    private static function blockContainsBooking(array $block, array $visited): bool
    {
        if (in_array($block['blockName'], ['rrze/rsvp', 'rrze-rsvp/course-booking'], true)) {
            return true;
        }
        if ($block['blockName'] === 'core/block' && !empty($block['attrs']['ref'])) {
            $id = (int) $block['attrs']['ref'];
            if (!isset($visited[$id])) {
                $visited[$id] = true;
                $post = get_post($id);
                if ($post && self::containsBooking($post->post_content, $visited)) {
                    return true;
                }
            }
        }
        foreach ($block['innerBlocks'] ?? [] as $inner) {
            if (self::blockContainsBooking($inner, $visited)) {
                return true;
            }
        }
        return false;
    }

    /** @param bool $skip Previous cache decision. @return bool Whether this page contains live forms. */
    public function skipCache($skip = false): bool
    {
        $post = get_queried_object();
        return (bool) $skip || ($post instanceof \WP_Post && self::containsBooking($post->post_content));
    }

    /** @return void Queue booking styles before wp_head, including nested and synced block content. */
    public function enqueueStyles(): void
    {
        if ($this->skipCache()) {
            wp_enqueue_style('rrze-rsvp-shortcode');
            wp_enqueue_style('rrze-rsvp-course-frontend');
            wp_enqueue_style('wp-block-buttons');
            wp_enqueue_style('wp-block-button');
        }
    }

    /** @return void Exclude booking pages before the theme starts output. */
    public function preventCache(): void
    {
        if ($this->skipCache()) {
            self::noCache();
        }
    }

    /** @return void Prevent caches from retaining identity, nonces or availability. */
    private static function noCache(): void
    {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (!headers_sent()) {
            nocache_headers();
        }
    }
}
