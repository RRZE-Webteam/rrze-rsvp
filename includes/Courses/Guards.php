<?php

namespace RRZE\RSVP\Courses;

defined('ABSPATH') || exit;

/**
 * Prevent legacy WordPress editing paths from bypassing course invariants.
 */
final class Guards
{
    /**
     * Register protections for metadata, post updates and deletion.
     *
     * @return void
     */
    public function onLoaded(): void
    {
        add_filter('add_post_metadata', [$this, 'protectMeta'], 10, 5);
        add_filter('update_post_metadata', [$this, 'protectMeta'], 10, 5);
        add_filter('delete_post_metadata', [$this, 'protectMeta'], 10, 5);
        add_filter('update_post_metadata_by_mid', [$this, 'protectMetaById'], 10, 4);
        add_filter('delete_post_metadata_by_mid', [$this, 'protectMetaById'], 10, 2);
        add_filter('wp_insert_post_empty_content', [$this, 'protectPost'], 10, 2);
        add_filter('pre_trash_post', [$this, 'protectDeletion'], 10, 2);
        add_filter('pre_delete_post', [$this, 'protectDeletion'], 10, 2);
    }

    /**
     * Require the transaction service for protected metadata writes.
     *
     * @param mixed $check Existing metadata short-circuit value.
     * @param int $postId Object being changed (zero for a delete-all request).
     * @param string $key Metadata key.
     * @param mixed $value Proposed or deleted value.
     * @param mixed $extra Unique, previous value, or delete-all flag.
     * @return mixed False to reject the change, otherwise the original filter value.
     */
    public function protectMeta($check, $postId, $key, $value, $extra)
    {
        if (BookingService::isWriting()) {
            return $check;
        }

        $coreKeys = [
            Model::COURSE_SETTINGS_META,
            Model::OCCURRENCE_META,
            Model::BOOKING_DATE_META,
            Model::BOOKING_COURSE_META,
            Model::BOOKING_DATA_META,
        ];
        if (in_array($key, $coreKeys, true)) {
            return false;
        }

        $isBookingField = strpos($key, 'rrze-rsvp-booking-') === 0;
        $isCourseBooking = $postId > 0 && (new Repository())->isCourseBooking((int) $postId);
        if ($isBookingField && ($isCourseBooking || (!$postId && $extra))) {
            // Internal notes do not affect participant identity or capacity.
            return $key === 'rrze-rsvp-booking-internal-notes' ? $check : false;
        }

        return $check;
    }

    /**
     * Protect native custom-field actions that identify metadata by its row ID.
     *
     * Both the old and proposed keys must be checked: renaming a harmless field
     * into a booking relationship must not bypass the normal metadata filters.
     *
     * @param mixed $check Existing short-circuit result.
     * @param int $metaId Metadata row ID.
     * @param mixed $value Proposed value (unused for deletion).
     * @param string|false $key Optional replacement metadata key.
     * @return mixed False for protected or unreadable metadata, otherwise the existing result.
     */
    public function protectMetaById($check, $metaId, $value = null, $key = false)
    {
        if (BookingService::isWriting()) {
            return $check;
        }
        $meta = get_metadata_by_mid('post', $metaId);
        if (!$meta) {
            return false;
        }
        foreach (array_unique([$meta->meta_key, $key === false ? $meta->meta_key : $key]) as $candidate) {
            if ($this->protectMeta(null, (int) $meta->post_id, $candidate, $value, false) === false) {
                return false;
            }
        }
        return $check;
    }

    /**
     * Reject native edits to service-owned posts and occurrence relationships.
     *
     * WordPress turns a true result into a failed insert/update before writing.
     * Course title and description remain editable through the normal editor.
     *
     * @param bool $empty Existing empty-content decision.
     * @param array $post Data passed to wp_insert_post().
     * @return bool Whether WordPress must reject this write.
     */
    public function protectPost(bool $empty, array $post): bool
    {
        if (BookingService::isWriting()) {
            return $empty;
        }

        $id = (int) ($post['ID'] ?? 0);
        $type = $post['post_type'] ?? ($id ? get_post_type($id) : '');
        $repository = new Repository();
        if ($type === Model::DATE_TYPE || ($id && get_post_type($id) === Model::DATE_TYPE)) {
            return true;
        }
        if (($id && $repository->isCourseBooking($id)) || isset($post['meta_input'][Model::BOOKING_DATE_META])) {
            return true;
        }
        if ($id && get_post_type($id) === Model::COURSE_TYPE && $repository->hasBookings($id)) {
            if ($type !== Model::COURSE_TYPE || ($post['post_status'] ?? '') === 'trash') {
                return true;
            }
        }

        return $empty;
    }

    /**
     * Keep retained bookings and their parent records out of manual deletion.
     *
     * @param mixed $delete Existing deletion short-circuit value.
     * @param \WP_Post $post Post proposed for deletion or trash.
     * @return mixed False for a protected post, otherwise the previous value.
     */
    public function protectDeletion($delete, $post)
    {
        if (BookingService::isWriting()) {
            return $delete;
        }

        $repository = new Repository();
        if ($repository->isCourseBooking((int) $post->ID)) {
            return false;
        }
        if (in_array($post->post_type, [Model::COURSE_TYPE, Model::DATE_TYPE], true)) {
            // The service checks references under the same lock used by book().
            // Checking here and letting core delete later would leave a race.
            if ($delete !== null) {
                return $delete;
            }
            $service = new BookingService();
            $force = current_filter() === 'pre_delete_post';
            $result = $post->post_type === Model::COURSE_TYPE
                ? $service->deleteCourse((int) $post->ID, $force)
                : $service->deleteOccurrence((int) $post->ID, $force);
            return is_wp_error($result) ? false : $post;
        }
        if ($post->post_type === 'room' && $repository->hasActiveRoomBookings((int) $post->ID)) {
            return false;
        }

        return $delete;
    }
}
