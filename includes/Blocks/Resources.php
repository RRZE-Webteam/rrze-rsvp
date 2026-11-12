<?php

namespace RRZE\RSVP\Blocks;

use RRZE\RSVP\Courses\Model;
use WP_Post;

defined('ABSPATH') || exit;

/** Shared resource rules for editor readers and public block rendering. */
final class Resources
{
    public const MODES = ['course', 'consultation', 'seat'];

    /** @param string $mode Booking variation. @return string Post type for the variation. */
    public static function postType(string $mode): string
    {
        return $mode === 'course' ? Model::COURSE_TYPE : 'room';
    }

    /** @param string $mode Booking variation. @return string[] Allowed room booking modes. */
    public static function roomModes(string $mode): array
    {
        return $mode === 'consultation' ? ['consultation'] : ['reservation', 'no-check'];
    }

    /**
     * Check the resource type and current booking mode without changing its settings.
     *
     * @param int $id Resource ID.
     * @param string $mode Requested block variation.
     * @param bool $published Whether public visibility is required.
     * @return bool Whether the resource can be used in this variation.
     */
    public static function matches(int $id, string $mode, bool $published = true): bool
    {
        $post = get_post($id);
        return in_array($mode, self::MODES, true) && $post instanceof WP_Post
            && $post->post_type === self::postType($mode)
            && (!$published || Model::isPublicOffer($post->post_status, $post->post_password))
            && !in_array($post->post_status, ['trash', 'auto-draft'], true)
            && ($mode === 'course' || in_array(get_post_meta($id, 'rrze-rsvp-room-bookingmode', true), self::roomModes($mode), true));
    }

    /** @param string $mode Booking variation. @return bool Whether this user may select its resources. */
    public static function canEdit(string $mode): bool
    {
        $type = get_post_type_object(self::postType($mode));
        return in_array($mode, self::MODES, true) && $type && current_user_can($type->cap->edit_posts);
    }

    /**
     * Expose only the information required to select an editable resource.
     *
     * @param WP_Post $post Authorized resource.
     * @return array<string, mixed> Public labels and an authorized edit link.
     */
    public static function label(WP_Post $post): array
    {
        return ['id' => (int) $post->ID, 'title' => wp_strip_all_tags($post->post_title),
            'status' => $post->post_status, 'editUrl' => (string) get_edit_post_link($post->ID, 'raw')];
    }
}
