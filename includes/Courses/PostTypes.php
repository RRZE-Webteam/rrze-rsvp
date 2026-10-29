<?php

namespace RRZE\RSVP\Courses;

defined('ABSPATH') || exit;

/** WordPress content types for courses and their internally managed dates. */
final class PostTypes
{
    /** @return void Register both types without a public single-post route. */
    public static function register(): void
    {
        register_post_type(Model::COURSE_TYPE, [
            'labels' => [
                'name' => __('Courses', 'rrze-rsvp'),
                'singular_name' => __('Course', 'rrze-rsvp'),
                'menu_name' => __('Courses', 'rrze-rsvp'),
                'add_new_item' => __('Add course', 'rrze-rsvp'),
                'edit_item' => __('Edit course', 'rrze-rsvp'),
                'new_item' => __('New course', 'rrze-rsvp'),
                'search_items' => __('Search courses', 'rrze-rsvp'),
                'not_found' => __('No courses found.', 'rrze-rsvp'),
            ],
            'public' => false, 'show_ui' => true, 'show_in_menu' => false,
            'show_in_rest' => false, 'show_in_admin_bar' => false,
            'publicly_queryable' => false, 'exclude_from_search' => true,
            'supports' => ['title', 'editor', 'author'],
            'capability_type' => ['rsvp_course', 'rsvp_courses'], 'map_meta_cap' => true,
            'delete_with_user' => false, 'can_export' => true,
        ]);
        register_post_type(Model::DATE_TYPE, [
            'labels' => ['name' => __('Course dates', 'rrze-rsvp'), 'singular_name' => __('Course date', 'rrze-rsvp')],
            'public' => false, 'show_ui' => false, 'show_in_menu' => false,
            'show_in_rest' => false, 'show_in_admin_bar' => false,
            'publicly_queryable' => false, 'exclude_from_search' => true,
            'supports' => [], 'capability_type' => ['rsvp_course', 'rsvp_courses'],
            'map_meta_cap' => true, 'delete_with_user' => false, 'can_export' => false,
        ]);
    }
}
