<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

class CPT
{
    const CPT_NAME = 'rsvp_booking';

    const TAXONOMIE_NAME = 'rsvp_service';

    public function __construct()
    {
        //
    }

    public function onLoaded()
    {
        add_action('init', [$this, 'registerCPT']);
        add_action('init', [$this, 'registerTaxonomy']);       
    }

    public static function getPostTypeName()
    {
        return static::CPT_NAME;
    }

    public static function getTaxonomyName()
    {
        return static::TAXONOMIE_NAME;
    }

    public function registerCPT()
    {
        $labels = [
            'name'               => esc_html_x('Bookings', 'post type general name', 'rrze-rsvp'),
            'singular_name'      => esc_html_x('Booking', 'post type singular name', 'rrze-rsvp'),
            'menu_name'          => esc_html_x('Bookings', 'admin menu', 'rrze-rsvp'),
            'name_admin_bar'     => esc_html_x('Booking', 'add new on admin bar', 'rrze-rsvp'),
            'add_new'            => esc_html_x('Add New', 'notice', 'rrze-rsvp'),
            'add_new_item'       => esc_html__('Add New Booking', 'rrze-rsvp'),
            'new_item'           => esc_html__('New Booking', 'rrze-rsvp'),
            'edit_item'          => esc_html__('Edit Booking', 'rrze-rsvp'),
            'view_item'          => esc_html__('View Booking', 'rrze-rsvp'),
            'all_items'          => esc_html__('All Bookings', 'rrze-rsvp'),
            'search_items'       => esc_html__('Search Bookings', 'rrze-rsvp'),
            'parent_item_colon'  => esc_html__('Parent Bookings:', 'rrze-rsvp'),
            'not_found'          => esc_html__('No bookings found.', 'rrze-rsvp'),
            'not_found_in_trash' => esc_html__('No bookings found in Trash.', 'rrze-rsvp')
        ];

        $capability = 'manage_options';

        $args = [
            'labels'                => $labels,
            'description'           => esc_html__('Bookings', 'rrze-rsvp'),
            'public'                => false,
            'exclude_from_search'   => true,
            'publicly_queryable'    => false,
            'show_ui'               => true,
            'show_in_nav_menus'     => false,
            'show_in_menu'          => true,
            'show_in_admin_bar'     => false,
            'menu_position'         => null,
            'menu_icon'             => 'dashicons-calendar-alt',
            'capability_type'       => 'page',
            'capabilities'          => [
                'edit_post'          => $capability,
                'read_post'          => $capability,
                'delete_posts'       => $capability,
                'edit_posts'         => $capability,
                'edit_others_posts'  => $capability,
                'publish_posts'      => $capability,
                'read_private_posts' => $capability,
                'create_posts'       => $capability
            ],
            //'map_meta_cap'          => null,
            'hierarchical'          => false,
            'supports'              => ['title', 'editor'],
            //'register_meta_box_cb'  => [$this->metaBoxes, 'addMetaBoxes'],
            'taxonomies'            => [static::TAXONOMIE_NAME],
            'has_archive'           => false,
            'rewrite'               => false,
            //'permalink_epmask'    => EP_PERMALINK,
            'query_var'             => true,
            'can_export'            => true,
            'delete_with_user'      => null,
            'show_in_rest'          => false,
            //'rest_base'             => static::CPT_NAME,
            //'rest_controller_class' => 'WP_REST_Posts_Controller',
        ];

        register_post_type(static::CPT_NAME, $args);
    }

    public function registerTaxonomy()
    {
        $labels = [
            'name'                       => esc_html__('Services', 'rrze-rsvp'),
            'singular_name'              => esc_html__('Service', 'rrze-rsvp'),
            'search_items'               => esc_html__('Search Services', 'rrze-rsvp'),
            'popular_items'              => esc_html__('Popular Services', 'rrze-rsvp'),
            'all_items'                  => esc_html__('All Services', 'rrze-rsvp'),
            'parent_item'                => null,
            'parent_item_colon'          => null,
            'edit_item'                  => esc_html__('Edit Service', 'rrze-rsvp'),
            'view_item'                  => esc_html__('View Service', 'rrze-rsvp'),
            'update_item'                => esc_html__('Update Service', 'rrze-rsvp'),
            'add_new_item'               => esc_html__('Add New Service', 'rrze-rsvp'),
            'new_item_name'              => esc_html__('New Service Name', 'rrze-rsvp'),
            'separate_items_with_commas' => esc_html__('Separate services with commas', 'rrze-rsvp'),
            'add_or_remove_items'        => esc_html__('Add or remove services', 'rrze-rsvp'),
            'choose_from_most_used'      => esc_html__('Choose from the most used services', 'rrze-rsvp'),
            'not_found'                  => esc_html__('No services found.', 'rrze-rsvp'),
            'no_terms'                   => esc_html__('No services.', 'rrze-rsvp')
        ];

        $args = [
            'labels'                => $labels,
            //'description'           => '',
            //'public'                => true,
            //'publicly_queryable'    => true,
            'hierarchical'          => false,
            'show_ui'               => true,
            //'show_in_menu'          => true,
            //'show_in_nav_menus'     => true,
            //'show_in_rest'          => true,
            //'rest_base'             => static::TAXONOMIE_NAME,
            //'rest_controller_class' => 'WP_REST_Terms_Controller',
            //'show_tagcloud'         => true,
            //'show_in_quick_edit'    => true,
            'show_admin_column'     => true,
            //'meta_box_cb'           => false,
            //'meta_box_sanitize_cb'  => $meta_box_cb,
            'capabilities'          => ['manage_terms'],
            'rewrite'               => ['slug' => 'service'],
            'query_var'             => true,
            //'update_count_callback' => _update_generic_term_count()
        ];

        register_taxonomy(static::TAXONOMIE_NAME, static::CPT_NAME, $args);
    }
}
