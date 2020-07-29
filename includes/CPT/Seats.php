<?php

/* ---------------------------------------------------------------------------
 * Custom Post Type Seat
 * ------------------------------------------------------------------------- */

namespace RRZE\RSVP\CPT;

class Seats {

	protected $options;

	public function __construct($pluginFile, $settings) {
        $this->pluginFile = $pluginFile;
        $this->settings = $settings;
	}

    public function onLoaded() {
        require_once(plugin_dir_path($this->pluginFile) . 'vendor/cmb2/init.php');
        add_action('init', [$this, 'seats_post_type'], 0);
        add_action('init', [$this, 'seats_taxonomies']);
        add_action('cmb2_admin_init', [$this, 'seats_metaboxes']);
        //add_action('save_post', [$this, 'seats_metaboxes_save']);
    }

	// Register Custom Post Type
	public function seats_post_type() {
		$labels = array(
			'name'					=> _x( 'Seats', 'Post type general name', 'wiso-io' ),
			'singular_name'			=> _x( 'Seat', 'Post type singular name', 'wiso-io' ),
			'menu_name'				=> _x( 'Seats', 'Admin Menu text', 'wiso-io' ),
			'name_admin_bar'		=> _x( 'Seat', 'Add New on Toolbar', 'wiso-io' ),
			'add_new'				=> __( 'Add New', 'wiso-io' ),
			'add_new_item'			=> __( 'Add New Seat', 'wiso-io' ),
			'new_item'				=> __( 'New Seat', 'wiso-io' ),
			'edit_item'				=> __( 'Edit Seat', 'wiso-io' ),
			'view_item'				=> __( 'View Seat', 'wiso-io' ),
			'all_items'				=> __( 'All Seats', 'wiso-io' ),
			'search_items'			=> __( 'Search Seats', 'wiso-io' ),
			'parent_item_colon'		=> __( 'Parent Seats:', 'wiso-io' ),
			'not_found'				=> __( 'No Seats found.', 'wiso-io' ),
			'not_found_in_trash'	=> __( 'No Seats found in Trash.', 'wiso-io' ),
			'featured_image'		=> _x( 'Seat Image', 'Overrides the “Featured Image” phrase for this post type. Added in 4.3', 'wiso-io' ),
			'set_featured_image'	=> _x( 'Set seat image', 'Overrides the “Set featured image” phrase for this post type. Added in 4.3', 'wiso-io' ),
			'remove_featured_image'	=> _x( 'Remove seat image', 'Overrides the “Remove featured image” phrase for this post type. Added in 4.3', 'wiso-io' ),
			'use_featured_image'	=> _x( 'Use as Seat image', 'Overrides the “Use as featured image” phrase for this post type. Added in 4.3', 'wiso-io' ),
			'archives'				=> _x( 'Seat archives', 'The post type archive label used in nav menus. Default “Post Archives”. Added in 4.4', 'wiso-io' ),
			'insert_into_item'		=> _x( 'Insert into Seat', 'Overrides the “Insert into post”/”Insert into page” phrase (used when inserting media into a post). Added in 4.4', 'wiso-io' ),
			'uploaded_to_this_item'	=> _x( 'Uploaded to this Seat', 'Overrides the “Uploaded to this post”/”Uploaded to this page” phrase (used when viewing media attached to a post). Added in 4.4', 'wiso-io' ),
			'filter_items_list'		=> _x( 'Filter Seats list', 'Screen reader text for the filter links heading on the post type listing screen. Default “Filter posts list”/”Filter pages list”. Added in 4.4', 'wiso-io' ),
			'items_list_navigation'	=> _x( 'Seats list navigation', 'Screen reader text for the pagination heading on the post type listing screen. Default “Posts list navigation”/”Pages list navigation”. Added in 4.4', 'wiso-io' ),
			'items_list'			=> _x( 'Seats list', 'Screen reader text for the items list heading on the post type listing screen. Default “Posts list”/”Pages list”. Added in 4.4', 'wiso-io' ),
		);
		$args = array(
			'label' => __('Seat', 'wiso-io'),
			'description' => __('Add and edit seat informations', 'wiso-io'),
			'labels' => $labels,
			'supports'                  => ['title', 'author', 'revisions'],
			'hierarchical' 				=> false,
			'public' 					=> false,
			'show_ui' 					=> true,
			'show_in_menu' 				=> true,
			'show_in_nav_menus' 		=> true,
			'show_in_admin_bar' 		=> true,
			'menu_position' 			=> 5,
			'menu_icon' 				=> 'dashicons-location',
			'can_export' 				=> true,
			'has_archive' 				=> false,
			'exclude_from_search' 		=> true,
			'publicly_queryable' 		=> false,
			'capability_type' 			=> ['seat', 'seats'],
			'map_meta_cap' => true,

		);
		register_post_type('seat', $args);
	}

	public function seats_taxonomies() {
		$labels_equipment = array(
			'name'				=> _x('Equipment', 'taxonomy general name'),
			'singular_name'		=> _x('Equipment', 'taxonomy singular name'),
		);
		$args_equipment = array(
			'labels' => $labels_equipment,
			'hierarchical' => true,
			'rewrite' => false,
		);
		register_taxonomy('rrze-rsvp-equipment', 'seat', $args_equipment);
	}

	public function seats_metaboxes() {
        $cmb = new_cmb2_box( array(
            'id'            => 'rrze-rsvp-seat-details-meta',
            'title'         => __( 'Details', 'rrze-rsvp' ),
            'object_types'  => array( 'seat', ), // Post type
            'context'       => 'normal',
            'priority'      => 'high',
            'show_names'    => true, // Show field names on the left
            // 'cmb_styles' => false, // false to disable the CMB stylesheet
            // 'closed'     => true, // Keep the metabox closed by default
        ) );

        $cmb->add_field( array(
            'name'             => __('Location', 'rrze-rsvp'),
            //'desc'             => 'Select an option',
            'id'               => 'rrze-rsvp-seat-location',
            'type'             => 'select',
            'show_option_none' => '&mdash; ' . __('Please select', 'rrze-rsvp') . ' &mdash;',
            'default'          => 'custom',
            'options_cb'       => [$this, 'post_select_options'],
        ) );
	}

	public function seats_metaboxes_save($post_id) {}

    public function post_select_options( $field ) {
        $locations = get_posts([
            'post_type' => 'room',
            'post_statue' => 'publish',
            'nopaging' => true,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        $options = [];
        foreach ($locations as $location) {
            $options[$location->ID] = $location->post_title;
        }
        return $options;
    }
}
