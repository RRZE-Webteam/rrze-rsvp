<?php

/* ---------------------------------------------------------------------------
 * Custom Post Type Room
 * ------------------------------------------------------------------------- */

namespace RRZE\RSVP\CPT;

defined('ABSPATH') || exit;

use RRZE\RSVP\Capabilities;

class Rooms
{

    protected $options;

    public function __construct($pluginFile, $settings)
    {
        $this->pluginFile = $pluginFile;
        $this->settings = $settings;
    }

    public function onLoaded()
    {
        require_once(plugin_dir_path($this->pluginFile) . 'vendor/cmb2/init.php');
        add_action('init', [$this, 'room_post_type'], 0);
        add_action('cmb2_admin_init', [$this, 'room_metaboxes']);
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
            'parent_item_colon'        => __('Parent Rooms:', 'rrze-rsvp'),
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
            'supports' => ['title', 'editor', 'revisions', 'author', 'excerpt', 'thumbnail'],
            'hierarchical'                 => false,
            'public'                     => true,
            'show_ui'                     => true,
            'show_in_menu'                 => false,
            'show_in_nav_menus'         => false,
            'show_in_admin_bar'         => true,
            //'menu_position'             => 5,
            'menu_icon'                 => 'dashicons-building',
            'can_export'                 => true,
            'has_archive'                 => false,
            'exclude_from_search'         => true,
            'publicly_queryable'         => true,
            'capability_type'             => Capabilities::getCptCapabilityType('room'),
            'capabilities'              => (array) Capabilities::getCptCaps('room'),
            'map_meta_cap'              => Capabilities::getCptMapMetaCap('booking')
        ];

        register_post_type('room', $args);
    }

    public function room_metaboxes()
    {
        $cmb_timeslots = new_cmb2_box(array(
            'id'            => 'rrze-rsvp-room-timeslots_meta',
            'title'         => __('Timeslots', 'rrze-rsvp'),
            'object_types'  => array('room',), // Post type
            'context'       => 'normal',
            'priority'      => 'high',
            'show_names'    => true, // Show field names on the left
            // 'cmb_styles' => false, // false to disable the CMB stylesheet
            // 'closed'     => true, // Keep the metabox closed by default
        ));

        $group_field_id = $cmb_timeslots->add_field(array(
            'id'          => 'rrze-rsvp-room-timeslots',
            'type'        => 'group',
            'description' => __('Define bookable time slots.', 'rrze-rsvp'),
            'options'     => array(
                'group_title'       => __('Time slot {#}', 'rrze-rsvp'), // since version 1.1.4, {#} gets replaced by row number
                'add_button'        => __('Add Another Entry', 'cmb2'),
                'remove_button'     => __('Remove Entry', 'cmb2'),
                'sortable'          => false,
                // 'closed'         => true, // true to have the groups closed by default
                // 'remove_confirm' => esc_html__( 'Are you sure you want to remove?', 'cmb2' ), // Performs confirmation before removing group.
            ),
        ));

        $cmb_timeslots->add_group_field($group_field_id, array(
            'name'    => __('Week day', 'rrze-rsvp'),
            //'desc'    => 'field description (optional)',
            'id'      => 'rrze-rsvp-room-weekday',
            'type'    => 'multicheck',
            'options' => array(
                1 => __('Monday', 'rrze-rsvp'),
                2 => __('Tuesday', 'rrze-rsvp'),
                3 => __('Wednesday', 'rrze-rsvp'),
                4 => __('Thursday', 'rrze-rsvp'),
                5 => __('Friday', 'rrze-rsvp'),
                6 => __('Saturday', 'rrze-rsvp'),
                7 => __('Sunday', 'rrze-rsvp')
            ),
        ));

        $cmb_timeslots->add_group_field($group_field_id,  array(
            'name' => __('Start time', 'rrze-rsvp'),
            'id' => 'rrze-rsvp-room-starttime',
            'type' => 'text_time',
            'time_format' => 'H:i',
        ));

        $cmb_timeslots->add_group_field($group_field_id,  array(
            'name' => __('End time', 'rrze-rsvp'),
            'id' => 'rrze-rsvp-room-endtime',
            'type' => 'text_time',
            'time_format' => 'H:i',
        ));

        $cmb_general = new_cmb2_box(array(
            'id'            => 'rrze_rsvp_general-meta',
            'title'         => __('Details', 'rrze-rsvp'),
            'object_types'  => array('room',), // Post type
            'context'       => 'normal',
            'priority'      => 'high',
            'show_names'    => true, // Show field names on the left
            // 'cmb_styles' => false, // false to disable the CMB stylesheet
            // 'closed'     => true, // Keep the metabox closed by default
        ));

        $cmb_general->add_field(array(
            'name'    => __('Street', 'rrze-rsvp'),
            'id'      => 'rrze-rsvp-room-street',
            'type'    => 'text',
        ));

        $cmb_general->add_field(array(
            'name'    => __('ZIP', 'rrze-rsvp'),
            'id'      => 'rrze-rsvp-room-zip',
            'type'    => 'text',
        ));

        $cmb_general->add_field(array(
            'name'    => __('City', 'rrze-rsvp'),
            'id'      => 'rrze-rsvp-room-city',
            'type'    => 'text',
        ));

        $cmb_general->add_field(array(
            'name' => __('Available days in advance', 'rrze-rsvp'),
            'desc' => __('Number of days for which bookings are available in advance.', 'rrze-rsvp'),
            'id'   => 'rrze-rsvp-room-days-in-advance',
            'type' => 'text',
            'attributes' => array(
                'type' => 'number',
                'pattern' => '\d*',
            ),
            'sanitization_cb' => 'intval',
        ));

        $cmb_general->add_field(array(
            'name' => __('Automatic confirmation', 'rrze-rsvp'),
            'desc' => __('If the automatic confirmation is not activated, the booking must be confirmed manually.', 'rrze-rsvp'),
            'id'   => 'rrze-rsvp-room-auto-confirmation',
            'type' => 'checkbox',
        ));

        $cmb_general->add_field(array(
            'name'    => __('Floor plan', 'rrze-rsvp'),
            'desc'    => 'Upload an image.',
            'id'      => 'rrze-rsvp-room-floorplan',
            'type'    => 'file',
            // Optional:
            'options' => array(
                'url' => false, // Hide the text input for the url
            ),
            //            'text'    => array(
            //                'add_upload_file_text' => 'Add File' // Change upload button text. Default: "Add or Upload File"
            //            ),
            // query_args are passed to wp.media's library query.
            'query_args' => array(
                //'type' => 'application/pdf', // Make library only display PDFs.
                // Or only allow gif, jpg, or png images
                'type' => array(
                    'image/gif',
                    'image/jpeg',
                    'image/png',
                ),
            ),
            'preview_size' => 'large', // Image size to use when previewing in the admin.
        ));
    }
}
