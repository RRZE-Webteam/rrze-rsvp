<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

class CPT
{
    const CPT_BOOKING_NAME = 'rrze_rsvp_booking';

    const CPT_EXCEPTION_NAME = 'rrze_rsvp_exception';

    const CPT_SEAT_NAME = 'rrze_rsvp_seat';

    const TAXONOMY_SERVICE_NAME = 'rrze_rsvp_service';

    const TAXONOMY_SEAT_EQUIPMENT_NAME = 'rrze_rsvp_seat_equipment';    

    public function __construct()
    {
        //
    }

    public function onLoaded()
    {
        add_action('init', [$this, 'registerCPT']);
        add_action('init', [$this, 'registerTaxonomy']);
    }

    public static function getBookingName()
    {
        return static::CPT_BOOKING_NAME;
    }

    public static function getExceptionName()
    {
        return static::CPT_EXCEPTION_NAME;
    }

    public static function getSeatName()
    {
        return static::CPT_SEAT_NAME;
    }

    public static function getTaxonomySeatEquipmentName()
    {
        return static::TAXONOMY_SEAT_EQUIPMENT_NAME;
    }    

    public static function getTaxonomyServiceName()
    {
        return static::TAXONOMY_SERVICE_NAME;
    }

    /**
     * Register all custom post types for booking posts
     */
    public function registerCPT()
    {
        $args = [
            'public'                => false,
            'exclude_from_search'   => true,
            'publicly_queryable'    => false,
            'show_ui'               => false,
            'show_in_nav_menus'     => false,
            'show_in_menu'          => false,
            'show_in_admin_bar'     => false,
            'menu_position'         => null,
            'menu_icon'             => 'dashicons-calendar-alt',
            'capability_type'       => 'page',
            //'map_meta_cap'          => false,
            'hierarchical'          => false,
            'supports'              => ['title'],
            //'register_meta_box_cb'  => [$this->metaBoxes, 'addMetaBoxes'],
            'taxonomies'            => [static::TAXONOMY_SERVICE_NAME],
            'has_archive'           => false,
            'rewrite'               => false,
            //'permalink_epmask'    => EP_PERMALINK,
            'query_var'             => false,
            'can_export'            => true,
            'delete_with_user'      => null,
            'show_in_rest'          => false,
            //'rest_base'             => static::CPT_BOOKING_NAME,
            //'rest_controller_class' => 'WP_REST_Posts_Controller',
        ];

        register_post_type(static::CPT_BOOKING_NAME, $args);
        register_post_type(static::CPT_EXCEPTION_NAME, $args);   
        register_post_type(static::CPT_SEAT_NAME, $args); 
    }

    public function registerTaxonomy()
    {
        $args = [
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

        register_taxonomy(
            static::TAXONOMY_SERVICE_NAME, 
            [
                static::CPT_BOOKING_NAME, 
                static::CPT_EXCEPTION_NAME,
                static::CPT_SEAT_NAME
            ], 
            $args
        );

        register_taxonomy(
            static::TAXONOMY_SEAT_EQUIPMENT_NAME, 
            [
                static::CPT_SEAT_NAME
            ], 
            $args
        );        
    }
}
