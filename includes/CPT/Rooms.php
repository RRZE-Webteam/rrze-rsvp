<?php

/* ---------------------------------------------------------------------------
 * Custom Post Type Room
 * ------------------------------------------------------------------------- */

namespace RRZE\RSVP\CPT;

defined('ABSPATH') || exit;

use RRZE\RSVP\Capabilities;

class Rooms
{
    public function __construct()
    {
        //
    }

    public function onLoaded()
    {
        add_action('init', [$this, 'room_post_type']);

        add_filter('manage_room_posts_columns', [$this, 'columns']);
        add_action('manage_room_posts_custom_column', [$this, 'customColumn'], 10, 2);
        add_filter('manage_edit-room_sortable_columns', [$this, 'sortableColumns']);

        add_filter('months_dropdown_results', [$this, 'removeMonthsDropdown'], 10, 2);
    }

    // Register Custom Post Type
    public function room_post_type()
    {
        $labels = [
            'name'                    => _x('Rooms', 'Post type general name', 'rrze-rsvp'),
            'singular_name'            => _x('Room', 'Post type singular name', 'rrze-rsvp'),
            'menu_name'                => _x('Rooms', 'Admin Menu text', 'rrze-rsvp'),
            'name_admin_bar'        => _x('Room', 'Add New on Toolbar', 'rrze-rsvp'),
            'add_new'                => __('Add New', 'rrze-rsvp'),
            'add_new_item'            => __('Add New Room', 'rrze-rsvp'),
            'new_item'                => __('New Room', 'rrze-rsvp'),
            'edit_item'                => __('Edit Room', 'rrze-rsvp'),
            'view_item'                => __('View Room', 'rrze-rsvp'),
            'all_items'                => __('All Rooms', 'rrze-rsvp'),
            'search_items'            => __('Search Rooms', 'rrze-rsvp'),
            'not_found'                => __('No Rooms found.', 'rrze-rsvp'),
            'not_found_in_trash'    => __('No Rooms found in Trash.', 'rrze-rsvp'),
            'featured_image'        => _x('Room Image', 'Overrides the “Featured Image” phrase for this post type. Added in 4.3', 'rrze-rsvp'),
            'set_featured_image'    => _x('Set room image', 'Overrides the “Set featured image” phrase for this post type. Added in 4.3', 'rrze-rsvp'),
            'remove_featured_image'    => _x('Remove room image', 'Overrides the “Remove featured image” phrase for this post type. Added in 4.3', 'rrze-rsvp'),
            'use_featured_image'    => _x('Use as Room image', 'Overrides the “Use as featured image” phrase for this post type. Added in 4.3', 'rrze-rsvp'),
            'archives'                => _x('Room archives', 'The post type archive label used in nav menus. Default “Post Archives”. Added in 4.4', 'rrze-rsvp'),
            'insert_into_item'        => _x('Insert into Room', 'Overrides the “Insert into post”/”Insert into page” phrase (used when inserting media into a post). Added in 4.4', 'rrze-rsvp'),
            'uploaded_to_this_item'    => _x('Uploaded to this Room', 'Overrides the “Uploaded to this post”/”Uploaded to this page” phrase (used when viewing media attached to a post). Added in 4.4', 'rrze-rsvp'),
            'filter_items_list'        => _x('Filter Rooms list', 'Screen reader text for the filter links heading on the post type listing screen. Default “Filter posts list”/”Filter pages list”. Added in 4.4', 'rrze-rsvp'),
            'items_list_navigation'    => _x('Rooms list navigation', 'Screen reader text for the pagination heading on the post type listing screen. Default “Posts list navigation”/”Pages list navigation”. Added in 4.4', 'rrze-rsvp'),
            'items_list'            => _x('Rooms list', 'Screen reader text for the items list heading on the post type listing screen. Default “Posts list”/”Pages list”. Added in 4.4', 'rrze-rsvp'),
        ];

        $args = [
            'label' => __('Room', 'rrze-rsvp'),
            'description' => __('Add and edit room informations', 'rrze-rsvp'),
            'labels' => $labels,
            'supports' => ['title', 'editor', 'revisions', 'author', 'thumbnail'],
            'hierarchical'                 => false,
            'public'                     => true,
            'show_ui'                     => true,
            'show_in_menu'                 => false,
            'show_in_nav_menus'         => false,
            'show_in_admin_bar'         => true,
            //'menu_position'             => 5,
            'menu_icon'                 => 'dashicons-building',
            'can_export'                 => true,
            'has_archive'                 => 'room',
            'exclude_from_search'         => true,
            'publicly_queryable'         => true,
            'capability_type'             => Capabilities::getCptCapabilityType('room'),
            'capabilities'              => (array) Capabilities::getCptCaps('room'),
            'map_meta_cap'              => Capabilities::getCptMapMetaCap('booking'),
            'rewrite' => [
                'slug' => 'room',
                'with_front' => false
            ],
        ];

        register_post_type('room', $args);
    }

    public function columns($columns)
    {
        $columns = array(
            'cb' => $columns['cb'],
            'title' => __('Room', 'rrze-rsvp')
        );
        return $columns;
    }

    public function customColumn($column, $postId)
    {
        if ('title' === $column) {
            echo get_the_title($postId);
        }
    }

    public function sortableColumns($columns)
    {
        $columns = array(
            'title' => __('Room', 'rrze-rsvp')
        );
        return $columns;
    }

    public function removeMonthsDropdown($months, $postType)
    {
        if ($postType == 'room') {
            $months = [];
        }
        return $months;
    }
}
