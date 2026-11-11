<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

/** Build and publish exactly the room seats reviewed in the admin tool. */
final class SeatGenerator
{
    public const LIMIT = 500;
    public const DEFAULTS = [
        'room_id' => '', 'number_rows' => '1', 'number_seats' => '4',
        'schema_rows' => 'num', 'schema_seats' => 'num', 'start_number' => '1',
        'seat_start' => '1', 'prefix' => '', 'equipment' => [],
    ];

    /**
     * Read unslashed form values without coercing invalid counts into valid ones.
     *
     * @param array $input Submitted fields.
     * @return array Normalized settings suitable for redisplay and validation.
     */
    public static function settings(array $input): array
    {
        $settings = self::DEFAULTS;
        foreach ($settings as $key => $default) {
            if ($key === 'equipment') {
                $settings[$key] = array_values(array_unique(array_map('intval',
                    array_filter((array) ($input[$key] ?? []), 'is_scalar'))));
            } elseif (array_key_exists($key, $input)) {
                $settings[$key] = is_scalar($input[$key]) ? sanitize_text_field((string) $input[$key]) : '';
            }
        }
        return $settings;
    }

    /**
     * Check room, equipment and the work limit before generating any names.
     *
     * @param array $s Form settings.
     * @param array $rooms Selectable rooms keyed by ID.
     * @param array $terms Available equipment keyed by ID.
     * @return string[] Localized, actionable errors.
     */
    public static function errors(array $s, array $rooms, array $terms): array
    {
        $errors = [];
        if (!isset($rooms[$s['room_id']])) {
            $errors[] = __('Select a published room.', 'rrze-rsvp');
        }
        $rows = filter_var($s['number_rows'], FILTER_VALIDATE_INT);
        $seats = filter_var($s['number_seats'], FILTER_VALIDATE_INT);
        if ($rows < 1 || $seats < 1 || $rows > self::LIMIT || $seats > intdiv(self::LIMIT, $rows)) {
            /* translators: %d: maximum seats per batch. */
            $errors[] = sprintf(__('Enter at least one row and one seat, with no more than %d seats in total.', 'rrze-rsvp'), self::LIMIT);
        }
        if (!in_array($s['schema_rows'], ['num', 'a-z'], true) || !in_array($s['schema_seats'], ['num', 'a-z'], true)) {
            $errors[] = __('Choose numbers or letters for the numbering.', 'rrze-rsvp');
        }
        foreach (['start_number' => $rows > 1 && $s['schema_rows'] === 'num', 'seat_start' => $s['schema_seats'] === 'num'] as $key => $used) {
            if ($used && !filter_var($s[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999999]])) {
                $errors[] = __('Enter a starting number between 1 and 999999.', 'rrze-rsvp');
                break;
            }
        }
        if (array_diff($s['equipment'], array_keys($terms))) {
            $errors[] = __('The selected equipment is no longer available. Check your selection.', 'rrze-rsvp');
        }
        return $errors;
    }

    /**
     * Format a one-based position as a number or spreadsheet-style letters.
     *
     * @param int $position Positive index (27 becomes AA).
     * @param string $schema Numbering choice: num or a-z.
     * @return string Label component.
     */
    private static function label(int $position, string $schema): string
    {
        if ($schema === 'num') {
            return (string) $position;
        }
        $label = '';
        while ($position > 0) {
            --$position;
            $label = chr(65 + $position % 26) . $label;
            $position = intdiv($position, 26);
        }
        return $label;
    }

    /**
     * Compare stored titles without leading whitespace or differences in case.
     *
     * @param string $title Raw post title.
     * @return string Room-local comparison key.
     */
    private static function key(string $title): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower(trim($title), 'UTF-8') : strtolower(trim($title));
    }

    /**
     * Compare proposed names with existing seats (including drafts) in this room.
     *
     * @param array $s Validated settings.
     * @return array{new:string[],existing:string[]} Ordered names by outcome.
     */
    public static function plan(array $s): array
    {
        $existing = [];
        foreach (get_posts([
            'post_type' => 'seat', 'post_status' => ['publish', 'private', 'draft', 'pending', 'future'],
            'meta_key' => 'rrze-rsvp-seat-room', 'meta_value' => (int) $s['room_id'],
            'posts_per_page' => -1, 'update_post_term_cache' => false, 'update_post_meta_cache' => false,
        ]) as $seat) {
            $existing[self::key($seat->post_title)] = true;
        }
        $plan = ['new' => [], 'existing' => []];
        $rowLabel = _x('Row', 'part of the generated seat name', 'rrze-rsvp');
        $seatLabel = _x('Seat', 'part of the generated seat name', 'rrze-rsvp');
        for ($r = 0; $r < (int) $s['number_rows']; ++$r) {
            $row = self::label($r + ($s['schema_rows'] === 'num' ? (int) $s['start_number'] : 1), $s['schema_rows']);
            for ($i = 0; $i < (int) $s['number_seats']; ++$i) {
                $name = self::label($i + ($s['schema_seats'] === 'num' ? (int) $s['seat_start'] : 1), $s['schema_seats']);
                if ((int) $s['number_rows'] > 1) {
                    $name = $s['schema_rows'] === $s['schema_seats'] ? "$rowLabel $row - $seatLabel $name" : $row . $name;
                }
                $name = trim($s['prefix'] . ' ' . $name);
                $plan[isset($existing[self::key($name)]) ? 'existing' : 'new'][] = $name;
            }
        }
        return $plan;
    }

    /**
     * Bind the confirmation nonce to both the settings and the reviewed names.
     *
     * @param array $settings Validated settings.
     * @param array $plan Current room comparison.
     * @return string WordPress nonce action.
     */
    public static function reviewAction(array $settings, array $plan): string
    {
        return 'rsvp-create-seats-' . hash('sha256', wp_json_encode([$settings, $plan]));
    }

    /**
     * Recheck the reviewed list under a room lock and publish only missing seats.
     *
     * @param array $s Validated settings for an authorized administrator.
     * @param string $nonce Confirmation from the review step.
     * @return array|\WP_Error Result counts, or a request to review again.
     */
    public static function create(array $s, string $nonce): array|\WP_Error
    {
        global $wpdb;
        $lock = 'rsvp-seats-' . md5($wpdb->prefix . ':' . $s['room_id']);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)', $lock)) !== '1') {
            return new \WP_Error('busy', __('Another batch is being created for this room. Please try again.', 'rrze-rsvp'));
        }
        try {
            $plan = self::plan($s);
            if (!wp_verify_nonce($nonce, self::reviewAction($s, $plan))) {
                return new \WP_Error('review', __('Please check the current preview again before creating seats.', 'rrze-rsvp'));
            }
            $result = ['created' => 0, 'skipped' => count($plan['existing']), 'failed' => 0, 'equipment_failed' => 0];
            foreach ($plan['new'] as $name) {
                $id = wp_insert_post(wp_slash(['post_type' => 'seat', 'post_status' => 'publish', 'post_title' => $name,
                    'meta_input' => ['rrze-rsvp-seat-room' => (int) $s['room_id']]]), true);
                if (!$id || is_wp_error($id)) {
                    ++$result['failed'];
                    continue;
                }
                ++$result['created'];
                if ($s['equipment'] && is_wp_error(wp_set_object_terms($id, $s['equipment'], 'rrze-rsvp-equipment'))) {
                    ++$result['equipment_failed'];
                }
            }
            return $result;
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }
}
