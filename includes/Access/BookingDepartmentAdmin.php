<?php

declare(strict_types=1);

namespace RRZE\RSVP\Access;

use RRZE\RSVP\Functions;
use WP_Post;

defined('ABSPATH') || exit;

/** Displays an immutable department snapshot only to authorized booking editors. */
final class BookingDepartmentAdmin
{
    /** @return void Register a read-only metabox without exposing data through public APIs. */
    public function onLoaded(): void
    {
        add_action('add_meta_boxes_booking', [$this, 'register']);
    }

    /**
     * Add the snapshot display for an existing room booking that this user may edit.
     *
     * @param WP_Post $post Booking being edited.
     * @return void
     */
    public function register(WP_Post $post): void
    {
        if (!$this->canRead($post)) {
            return;
        }
        add_meta_box('rrze-rsvp-booking-department', __('Department at booking', 'rrze-rsvp'),
            [$this, 'render'], 'booking', 'side', 'default');
    }

    /**
     * Render the frozen name, absence, or a clear storage error with no editable fields.
     *
     * @param WP_Post $post Booking whose historical snapshot is displayed.
     * @return void
     */
    public function render(WP_Post $post): void
    {
        if (!$this->canRead($post)) {
            return;
        }
        $department = BookingDepartment::read((int) $post->ID);
        if (is_wp_error($department)) {
            echo '<p role="alert">' . esc_html($department->get_error_message()) . '</p>';
        } elseif ($department === null) {
            echo '<p>' . esc_html__('No department was recorded when this booking was created.', 'rrze-rsvp') . '</p>';
        } else {
            echo '<p>' . esc_html($department['name']) . '</p>';
            echo '<p class="description">' . esc_html__('This is the department at the time of booking. Later changes to booking permissions or department names do not change this record.', 'rrze-rsvp') . '</p>';
        }
    }

    /**
     * Check access again when rendering, independent of metabox registration.
     *
     * @param WP_Post $post Requested booking.
     * @return bool Whether this editor may see a persisted room booking.
     */
    private function canRead(WP_Post $post): bool
    {
        return $post->ID > 0 && $post->post_type === 'booking' && $post->post_status !== 'auto-draft'
            && current_user_can('edit_post', $post->ID) && !Functions::isCourseBooking((int) $post->ID);
    }
}
