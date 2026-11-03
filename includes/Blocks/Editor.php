<?php

namespace RRZE\RSVP\Blocks;

use RRZE\RSVP\Courses\{Repository, BookingService};
use RRZE\RSVP\Functions;
use WP_Error;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

/** Private, read-only data for the RSVP editor; never executes booking renderers. */
final class Editor
{
    /** @return void Register bounded resource search and passive preview endpoints. */
    public function registerRoutes(): void
    {
        $mode = ['type' => 'string', 'enum' => Resources::MODES, 'required' => true];
        register_rest_route('rrze-rsvp/v1', '/editor/resources', [
            'methods' => 'GET', 'callback' => [$this, 'resources'], 'permission_callback' => [$this, 'permission'],
            'args' => ['mode' => $mode, 'search' => ['type' => 'string', 'default' => '', 'maxLength' => 200],
                'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1]],
        ]);
        register_rest_route('rrze-rsvp/v1', '/editor/preview', [
            'methods' => 'GET', 'callback' => [$this, 'preview'], 'permission_callback' => [$this, 'permission'],
            'args' => ['mode' => $mode, 'id' => ['type' => 'integer', 'minimum' => 1, 'required' => true]],
        ]);
    }

    /**
     * Enforce the existing resource-management capabilities, without broadening REST access.
     *
     * @param WP_REST_Request $request Incoming private read.
     * @return true|WP_Error Authorization decision.
     */
    public function permission(WP_REST_Request $request)
    {
        return Resources::canEdit((string) $request->get_param('mode')) ? true
            : new WP_Error('rsvp_editor_forbidden', __('You do not have permission to select these offers.', 'rrze-rsvp'), ['status' => 403]);
    }

    /**
     * Search one bounded page; object-level permission checks also cover private resources.
     *
     * @param WP_REST_Request $request Mode, search text and page.
     * @return WP_REST_Response Authorized labels, with an opaque next-page indicator.
     */
    public function resources(WP_REST_Request $request): WP_REST_Response
    {
        $mode = (string) $request->get_param('mode');
        $args = ['post_type' => Resources::postType($mode), 'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
            'search_columns' => ['post_title'], 'posts_per_page' => 20, 'paged' => max(1, (int) $request->get_param('page')),
            's' => sanitize_text_field($request->get_param('search') ?? ''), 'orderby' => ['title' => 'ASC', 'ID' => 'ASC']];
        if ($mode !== 'course') {
            $args['meta_query'] = [['key' => 'rrze-rsvp-room-bookingmode', 'value' => Resources::roomModes($mode), 'compare' => 'IN']];
        }
        $query = new WP_Query($args);
        $items = [];
        foreach ($query->posts as $post) {
            if (current_user_can('edit_post', $post->ID)) {
                $items[] = Resources::label($post);
            }
        }
        return $this->response(['items' => $items, 'hasMore' => $args['paged'] < $query->max_num_pages]);
    }

    /**
     * Read a frontend-shaped preview without identity, forms, tokens, writes or email.
     *
     * @param WP_REST_Request $request Variation and resource ID.
     * @return WP_REST_Response|WP_Error Safe preview or an actionable error.
     */
    public function preview(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        $mode = (string) $request->get_param('mode');
        if (!current_user_can('edit_post', $id)) {
            return new WP_Error('rsvp_resource_forbidden', __('This offer is unavailable or you cannot edit it.', 'rrze-rsvp'), ['status' => 403]);
        }
        if (!Resources::matches($id, $mode, false)) {
            return new WP_Error('rsvp_resource_mismatch', __('This offer no longer matches the selected booking type. Please select another offer.', 'rrze-rsvp'), ['status' => 404]);
        }
        $post = get_post($id);
        $data = Resources::label($post);
        $data['mode'] = $mode;
        $data['description'] = wp_strip_all_tags(strip_shortcodes($post->post_content));
        $data['unpublished'] = $post->post_status !== 'publish' || $post->post_password !== '';
        try {
            $details = $mode === 'course' ? $this->course($id) : $this->room($id);
            return is_wp_error($details) ? $details : $this->response(array_merge($data, $details));
        } catch (\Throwable $error) {
            return new WP_Error('rsvp_preview_failed', __('The preview could not be loaded. Please try again.', 'rrze-rsvp'), ['status' => 503]);
        }
    }

    /**
     * Read the next five published course dates and their anonymous capacity counts.
     *
     * @param int $id Course ID.
     * @return array<string, mixed>|WP_Error Course preview data or a reader failure.
     */
    private function course(int $id)
    {
        $repository = new Repository();
        $repository->setStrictReads(true);
        $service = new BookingService($repository);
        $dates = [];
        foreach ($repository->getOccurrences($id, true) as $date) {
            if ($date['post_status'] !== 'publish' || $date['start'] <= time()) {
                continue;
            }
            $dates[] = $date;
        }
        $rows = [];
        foreach (array_slice($dates, 0, 5) as $date) {
            $availability = $service->availability($date['id']);
            if (is_wp_error($availability)) {
                return $availability;
            }
            $rows[] = ['id' => $date['id'], 'label' => wp_date(get_option('date_format') . ' · ' . get_option('time_format'), $date['start'])
                . ' – ' . wp_date(get_option('time_format'), $date['end']), 'location' => $date['location'],
                'available' => $availability['bookable'], 'remaining' => $availability['free'] ?? 0];
        }
        return ['dates' => $rows, 'moreDates' => count($dates) > 5];
    }

    /**
     * Read this month's bookable days and the times of its first available day.
     *
     * @param int $id Room ID.
     * @return array<string, mixed> Calendar cells and anonymous time counts.
     */
    private function room(int $id): array
    {
        $today = current_datetime();
        $first = $today->modify('first day of this month');
        $last = $today->modify('last day of this month');
        $horizon = max(0, (int) get_post_meta($id, 'rrze-rsvp-room-days-in-advance', true));
        $end = min($last->format('Y-m-d'), $today->modify('+' . $horizon . ' days')->format('Y-m-d'));
        $availability = Functions::getRoomAvailability($id, $today->format('Y-m-d'), $end, false);
        $days = [];
        $times = [];
        $selectedDate = '';
        for ($day = 1; $day <= (int) $last->format('j'); $day++) {
            $date = $first->setDate((int) $first->format('Y'), (int) $first->format('m'), $day);
            $key = $date->format('Y-m-d');
            $slots = $key >= $today->format('Y-m-d') && $key <= $end ? array_filter($availability[$key] ?? []) : [];
            $days[] = ['day' => $day, 'date' => $key, 'available' => (bool) $slots];
            if ($slots && $selectedDate === '') {
                $selectedDate = wp_date('l, j. F', $date->getTimestamp());
                foreach ($slots as $interval => $seats) {
                    $times[] = ['label' => str_replace('-', ' – ', $interval), 'remaining' => count($seats)];
                }
            }
        }
        $weekdays = [];
        $monday = new \DateTimeImmutable('2024-01-01', wp_timezone());
        for ($i = 0; $i < 7; $i++) {
            $weekdays[] = wp_date('D', $monday->modify('+' . $i . ' days')->getTimestamp());
        }
        return ['month' => wp_date('F Y', $first->getTimestamp()), 'offset' => (int) $first->format('N') - 1,
            'weekdays' => $weekdays, 'days' => $days, 'selectedDate' => $selectedDate, 'times' => $times,
            'location' => trim(implode(' ', array_filter([get_post_meta($id, 'rrze-rsvp-room-street', true),
                get_post_meta($id, 'rrze-rsvp-room-zip', true), get_post_meta($id, 'rrze-rsvp-room-city', true)])))];
    }

    /** @param array<string, mixed> $data Private payload. @return WP_REST_Response Uncacheable response. */
    private function response(array $data): WP_REST_Response
    {
        return new WP_REST_Response($data, 200, ['Cache-Control' => 'private, no-store, max-age=0']);
    }
}
