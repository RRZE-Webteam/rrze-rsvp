<?php

/* ---------------------------------------------------------------------------
 * Custom Post Type Seat
 * ------------------------------------------------------------------------- */

namespace RRZE\RSVP\CPT;

defined('ABSPATH') || exit;

use RRZE\RSVP\Capabilities;
use RRZE\RSVP\Functions;

class Seats
{

	protected $options;

	public function __construct()
	{
		//
	}

	/**
	 * Register the seat post type and its administration hooks.
	 *
	 * @return void
	 */
	public function onLoaded()
	{
		add_action('init', [$this, 'seats_post_type']);
		add_action('init', [$this, 'seats_taxonomies']);
		add_action('rrze-rsvp-equipment_add_form_fields', [$this, 'equipmentOptions']);
		add_action('after-rrze-rsvp-equipment-table', [$this, 'equipmentGuide']);
		add_filter('manage_edit-rrze-rsvp-equipment_columns', [$this, 'equipmentColumns']);
		add_filter('default_hidden_columns', [$this, 'equipmentHiddenColumns'], 10, 2);
		add_filter('term_updated_messages', [$this, 'equipmentMessages']);

		add_filter('manage_seat_posts_columns', [$this, 'columns']);
		add_action('manage_seat_posts_custom_column', [$this, 'customColumn'], 10, 2);
		add_filter('manage_edit-seat_sortable_columns', [$this, 'sortableColumns']);

		add_action('restrict_manage_posts', [$this, 'applyFilters']);
		add_filter('parse_query', [$this, 'filterQuery']);
		add_filter('months_dropdown_results', [$this, 'removeMonthsDropdown'], 10, 2);
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
			'supports'                  => ['title', 'author'],
			'hierarchical' 				=> false,
			'public' 					=> true,
			'show_ui' 					=> true,
			'show_in_menu' 				=> false,
			'show_in_nav_menus' 		=> false,
			'show_in_admin_bar' 		=> true,
			'can_export' 				=> true,
			'has_archive' 				=> false,
			'exclude_from_search' 		=> true,
			'publicly_queryable' 		=> true,
			'delete_with_user'          => false,
			'show_in_rest'              => false,
			'capability_type' 			=> Capabilities::getCptCapabilityType('seat'),
			'capabilities'              => (array) Capabilities::getCptCaps('seat'),
			'map_meta_cap'              => Capabilities::getCptMapMetaCap('booking')
		];

		register_post_type('seat', $args);
	}

	/**
	 * Register reusable equipment features and the labels used in the seat workflow.
	 *
	 * @return void
	 */
	public function seats_taxonomies()
	{
		$labels_equipment = array(
			'name'				=> _x('Equipment', 'taxonomy general name', 'rrze-rsvp'),
			'singular_name'		=> _x('Equipment', 'taxonomy singular name', 'rrze-rsvp'),
			'add_new_item' => __('Add feature', 'rrze-rsvp'),
			'new_item_name' => __('New feature name', 'rrze-rsvp'),
			'edit_item' => __('Edit feature', 'rrze-rsvp'),
			'update_item' => __('Update feature', 'rrze-rsvp'),
			'view_item' => __('View feature', 'rrze-rsvp'),
			'search_items' => __('Search features', 'rrze-rsvp'),
			'all_items' => __('All features', 'rrze-rsvp'),
			'parent_item' => __('Parent feature', 'rrze-rsvp'),
			'parent_item_colon' => __('Parent feature:', 'rrze-rsvp'),
			'items_list' => __('Existing features', 'rrze-rsvp'),
			'items_list_navigation' => __('Equipment list navigation', 'rrze-rsvp'),
			'not_found' => __('No equipment features found.', 'rrze-rsvp'),
			'no_terms' => __('No equipment features', 'rrze-rsvp'),
			'back_to_items' => __('Back to equipment', 'rrze-rsvp'),
			'name_field_description' => __('This name appears when someone selects the seat, for example “Digital camera” or “Fluorescence filter”.', 'rrze-rsvp'),
			'slug_field_description' => __('Leave this blank to generate the URL slug from the name.', 'rrze-rsvp'),
			'parent_field_description' => __('To group related features, select a broader feature, such as “Optics”.', 'rrze-rsvp'),
			'desc_field_description' => __('You can describe the feature here. The booking form only shows its name.', 'rrze-rsvp'),
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

	/**
	 * Provide a disclosure for optional core fields; leave them visible without JavaScript.
	 *
	 * @return void
	 */
	public function equipmentOptions(): void
	{
		echo '<details class="rsvp-equipment-options" hidden><summary>'
			. esc_html__('More options (optional)', 'rrze-rsvp') . '</summary></details>';
	}

	/**
	 * Explain the difference between a bookable seat and its equipment and link to seat management.
	 *
	 * @return void
	 */
	public function equipmentGuide(): void
	{
		?>
		<div class="rsvp-equipment-guide">
			<h3><?php esc_html_e('Seat or equipment feature?', 'rrze-rsvp'); ?></h3>
			<p><?php esc_html_e('If a microscope can be booked individually, create it as a seat. Its digital camera is an equipment feature. You can assign the same feature to several seats.', 'rrze-rsvp'); ?></p>
			<p><?php esc_html_e('To assign a feature, open the seat, select the feature under Equipment and save the seat.', 'rrze-rsvp'); ?></p>
			<a href="<?php echo esc_url(admin_url('edit.php?post_type=seat')); ?>"><?php esc_html_e('Go to seats', 'rrze-rsvp'); ?></a>
		</div>
		<?php
	}

	/**
	 * Label the equipment name and the core link to seats using the feature.
	 *
	 * @param array $columns Core term table columns keyed by column ID.
	 * @return array Columns with equipment-specific headings.
	 */
	public function equipmentColumns(array $columns): array
	{
		$columns['name'] = __('Feature', 'rrze-rsvp');
		$columns['posts'] = _x('Seats', 'Post type general name', 'rrze-rsvp');
		return $columns;
	}

	/**
	 * Keep optional taxonomy metadata available via Screen Options on the equipment list.
	 *
	 * @param array      $hidden Columns hidden by default.
	 * @param \WP_Screen $screen Current WordPress admin screen.
	 * @return array Default hidden columns, before any saved user preferences are applied.
	 */
	public function equipmentHiddenColumns(array $hidden, \WP_Screen $screen): array
	{
		return $screen->id === 'edit-rrze-rsvp-equipment'
			? array_unique(array_merge($hidden, ['description', 'slug']))
			: $hidden;
	}

	/**
	 * Tell editors where to use a newly created feature, including after an AJAX add.
	 *
	 * @param array $messages Core term messages keyed by taxonomy and operation.
	 * @return array Term messages with a next step after adding equipment.
	 */
	public function equipmentMessages(array $messages): array
	{
		$messages['rrze-rsvp-equipment'] ??= $messages['_item'];
		$messages['rrze-rsvp-equipment'][1] = __('Feature added. You can now select it when editing a seat.', 'rrze-rsvp');
		return $messages;
	}

	public function columns($columns)
	{
		$columns = array(
			'cb' => $columns['cb'],
			'title' => __('Seat', 'rrze-rsvp'),
			'room' => __('Room', 'rrze-rsvp')
		);
		return $columns;
	}

	public function customColumn($column, $post_id)
	{
		$roomId = get_post_meta($post_id, 'rrze-rsvp-seat-room', true);
		if ('title' === $column) {
			echo get_the_title($post_id);
		}
		if ('room' === $column) {
			echo get_the_title($roomId);
		}
	}

	public function sortableColumns($columns)
	{
		$columns = array(
			'title' => 'title',
			'room' => 'room'
		);
		return $columns;
	}

	public function applyFilters($postType)
	{
		if ($postType != 'seat') {
			return;
		}

		$allRooms = __('Show all rooms', 'rrze-rsvp');
		$selectedRoom = sanitize_text_field($_GET['rrze-rsvp-seat-room'] ?? '');

		$seatIds = get_posts([
			'post_type' => 'seat',
			'nopaging' => true,
			'fields' => 'ids'
		]);

		$seatRooms = [];

		foreach ($seatIds as $seatId) {
			$roomId = get_post_meta($seatId, 'rrze-rsvp-seat-room', true);
			$seatRooms[$roomId] = get_the_title($roomId);
		}

		if ($seatRooms) {
			Functions::sortArrayKeepKeys($seatRooms);
			echo Functions::getSelectHTML('rrze-rsvp-seat-room', $allRooms, $seatRooms, $selectedRoom);
		}
	}

	public function filterQuery($query)
	{
		if (!(is_admin() and $query->is_main_query())) {
			return $query;
		}

		if (!($query->query['post_type'] == 'seat')) {
			return $query;
		}

		$roomId = filter_input(INPUT_GET, 'rrze-rsvp-seat-room', FILTER_VALIDATE_INT);

		if (!$roomId) {
			return $query;
		}

		$meta_query = [];
		$seatId = get_posts([
			'post_type' => 'seat',
			'meta_key' => 'rrze-rsvp-seat-room',
			'meta_value' => $roomId,
			'numberposts' => 1,
			'fields' => 'ids'
		]);

		if (isset($seatId[0])) {
			$meta_query[] = array(
				'key' => 'rrze-rsvp-seat-room',
				'value' => $roomId
			);
		}

		if ($meta_query) {
			$meta_query['relation'] = 'AND';
			$query->query_vars['meta_query'] = $meta_query;
		}

		return $query;
	}

	public function removeMonthsDropdown($months, $postType)
	{
		if ($postType == 'seat') {
			$months = [];
		}
		return $months;
	}
}
