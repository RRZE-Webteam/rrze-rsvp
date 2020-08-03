<?php

/* ---------------------------------------------------------------------------
 * Custom Post Type Seat
 * ------------------------------------------------------------------------- */

namespace RRZE\RSVP\CPT;

defined('ABSPATH') || exit;

use RRZE\RSVP\Capabilities;

class Seats
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
		add_action('init', [$this, 'seats_post_type'], 0);
		add_action('init', [$this, 'seats_taxonomies']);
		add_action('cmb2_admin_init', [$this, 'seats_metaboxes']);
	}

	// Register Custom Post Type
	public function seats_post_type()
	{
		$labels = [
			'name'					=> _x('Seats', 'Post type general name', 'rrze-rsvp'),
			'singular_name'			=> _x('Seat', 'Post type singular name', 'rrze-rsvp'),
			'menu_name'				=> _x('Seats', 'Admin Menu text', 'rrze-rsvp'),
			'name_admin_bar'		=> _x('Seat', 'Add New on Toolbar', 'rrze-rsvp'),
			'add_new'				=> __('Add New', 'rrze-rsvp'),
			'add_new_item'			=> __('Add New Seat', 'rrze-rsvp'),
			'new_item'				=> __('New Seat', 'rrze-rsvp'),
			'edit_item'				=> __('Edit Seat', 'rrze-rsvp'),
			'view_item'				=> __('View Seat', 'rrze-rsvp'),
			'all_items'				=> __('All Seats', 'rrze-rsvp'),
			'search_items'			=> __('Search Seats', 'rrze-rsvp'),
			'parent_item_colon'		=> __('Parent Seats:', 'rrze-rsvp'),
			'not_found'				=> __('No Seats found.', 'rrze-rsvp'),
			'not_found_in_trash'	=> __('No Seats found in Trash.', 'rrze-rsvp'),
			'featured_image'		=> _x('Seat Image', 'Overrides the “Featured Image” phrase for this post type. Added in 4.3', 'rrze-rsvp'),
			'set_featured_image'	=> _x('Set seat image', 'Overrides the “Set featured image” phrase for this post type. Added in 4.3', 'rrze-rsvp'),
			'remove_featured_image'	=> _x('Remove seat image', 'Overrides the “Remove featured image” phrase for this post type. Added in 4.3', 'rrze-rsvp'),
			'use_featured_image'	=> _x('Use as Seat image', 'Overrides the “Use as featured image” phrase for this post type. Added in 4.3', 'rrze-rsvp'),
			'archives'				=> _x('Seat archives', 'The post type archive label used in nav menus. Default “Post Archives”. Added in 4.4', 'rrze-rsvp'),
			'insert_into_item'		=> _x('Insert into Seat', 'Overrides the “Insert into post”/”Insert into page” phrase (used when inserting media into a post). Added in 4.4', 'rrze-rsvp'),
			'uploaded_to_this_item'	=> _x('Uploaded to this Seat', 'Overrides the “Uploaded to this post”/”Uploaded to this page” phrase (used when viewing media attached to a post). Added in 4.4', 'rrze-rsvp'),
			'filter_items_list'		=> _x('Filter Seats list', 'Screen reader text for the filter links heading on the post type listing screen. Default “Filter posts list”/”Filter pages list”. Added in 4.4', 'rrze-rsvp'),
			'items_list_navigation'	=> _x('Seats list navigation', 'Screen reader text for the pagination heading on the post type listing screen. Default “Posts list navigation”/”Pages list navigation”. Added in 4.4', 'rrze-rsvp'),
			'items_list'			=> _x('Seats list', 'Screen reader text for the items list heading on the post type listing screen. Default “Posts list”/”Pages list”. Added in 4.4', 'rrze-rsvp'),
		];

		$args = [
			'label' => __('Seat', 'rrze-rsvp'),
			'description' => __('Add and edit seat informations', 'rrze-rsvp'),
			'labels' => $labels,
			'supports'                  => ['title', 'author', 'revisions'],
			'hierarchical' 				=> false,
			'public' 					=> true,
			'show_ui' 					=> true,
			'show_in_menu' 				=> false,
			'show_in_nav_menus' 		=> false,
			'show_in_admin_bar' 		=> true,
			//'menu_position' 			=> 6,
			'menu_icon' 				=> 'dashicons-location',
			'can_export' 				=> true,
			'has_archive' 				=> false,
			'exclude_from_search' 		=> true,
			'publicly_queryable' 		=> true,
			'capability_type' 			=> Capabilities::getCptCapabilityType('seat'),
			'capabilities'              => (array) Capabilities::getCptCaps('seat'),
			'map_meta_cap'              => Capabilities::getCptMapMetaCap('booking')
		];

		register_post_type('seat', $args);
	}

	public function seats_taxonomies()
	{
		$labels_equipment = array(
			'name'				=> _x('Equipment', 'taxonomy general name'),
			'singular_name'		=> _x('Equipment', 'taxonomy singular name'),
		);
		$args_equipment = array(
			'labels' => $labels_equipment,
			'hierarchical' => true,
			'rewrite' => 'rrze-rsvp-equipment',
			'capabilities' => [
				'manage_terms'  => 'edit_seats',
				'edit_terms'    => 'edit_seats',
				'delete_terms'  => 'edit_seats',
				'assign_terms'  => 'edit_seats'
			]
		);
		register_taxonomy('rrze-rsvp-equipment', 'seat', $args_equipment);
	}

	public function seats_metaboxes()
	{
		$cmb = new_cmb2_box(array(
			'id'            => 'rrze-rsvp-seat-details-meta',
			'title'         => __('Details', 'rrze-rsvp'),
			'object_types'  => array('seat',), // Post type
			'context'       => 'normal',
			'priority'      => 'high',
			'show_names'    => true, // Show field names on the left
			// 'cmb_styles' => false, // false to disable the CMB stylesheet
			// 'closed'     => true, // Keep the metabox closed by default
		));





		$cmb->add_field(array(
			'name'             => __('Room', 'rrze-rsvp'),
			//'desc'             => 'Select an option',
			'id'               => 'rrze-rsvp-seat-room',
			'type'             => 'select',
			'show_option_none' => '&mdash; ' . __('Please select', 'rrze-rsvp') . ' &mdash;',
			'default'          => 'custom',
			'options_cb'       => [$this, 'post_select_options'],
		));
	}

	public function seats_metaboxes_save($post_id)
	{
	}

	public function post_select_options($field)
	{
		$rooms = get_posts([
			'post_type' => 'room',
			'post_statue' => 'publish',
			'nopaging' => true,
			'orderby' => 'title',
			'order' => 'ASC',
		]);
		$options = [];
		foreach ($rooms as $room) {
			$options[$room->ID] = $room->post_title;
		}
		return $options;
	}
}
