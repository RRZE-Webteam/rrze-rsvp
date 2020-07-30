<?php

/* ---------------------------------------------------------------------------
 * Custom Post Type Booking
 * ------------------------------------------------------------------------- */

namespace RRZE\RSVP\CPT;

class Bookings {

	protected $options;

	public function __construct($pluginFile, $settings) {
        $this->pluginFile = $pluginFile;
        $this->settings = $settings;
    }

    public function onLoaded() {
        require_once(plugin_dir_path($this->pluginFile) . 'vendor/cmb2/init.php');
        add_action('init', [$this, 'booking_post_type'], 0);
	add_action('cmb2_admin_init', [$this, 'booking_metaboxes']);
        //add_filter( 'manage_edit-booking_columns', [$this, 'booking_filter_posts_columns'] );
        add_filter( 'manage_booking_posts_columns', [$this, 'booking_columns'] );
        add_action( 'manage_booking_posts_custom_column', [$this, 'booking_column'], 10, 2);
        add_filter( 'manage_edit-booking_sortable_columns', [$this, 'booking_sortable_columns']);
    }

	// Register Custom Post Type
    public function booking_post_type() {
	$labels = array(
			'name'					=> _x( 'Bookings', 'Post type general name', 'rrze-rsvp' ),
			'singular_name'			=> _x( 'Booking', 'Post type singular name', 'rrze-rsvp' ),
			'menu_name'				=> _x( 'Bookings', 'Admin Menu text', 'rrze-rsvp' ),
			'name_admin_bar'		=> _x( 'Booking', 'Add New on Toolbar', 'rrze-rsvp' ),
			'add_new'				=> __( 'Add New', 'rrze-rsvp' ),
			'add_new_item'			=> __( 'Add New Booking', 'rrze-rsvp' ),
			'new_item'				=> __( 'New Booking', 'rrze-rsvp' ),
			'edit_item'				=> __( 'Edit Booking', 'rrze-rsvp' ),
			'view_item'				=> __( 'View Booking', 'rrze-rsvp' ),
			'all_items'				=> __( 'All Bookings', 'rrze-rsvp' ),
			'search_items'			=> __( 'Search Bookings', 'rrze-rsvp' ),
			'parent_item_colon'		=> __( 'Parent Bookings:', 'rrze-rsvp' ),
			'not_found'				=> __( 'No Bookings found.', 'rrze-rsvp' ),
			'not_found_in_trash'	=> __( 'No Bookings found in Trash.', 'rrze-rsvp' ),
			'featured_image'		=> _x( 'Booking Image', 'Overrides the “Featured Image” phrase for this post type. Added in 4.3', 'rrze-rsvp' ),
			'set_featured_image'	=> _x( 'Set Booking image', 'Overrides the “Set featured image” phrase for this post type. Added in 4.3', 'rrze-rsvp' ),
			'remove_featured_image'	=> _x( 'Remove Booking image', 'Overrides the “Remove featured image” phrase for this post type. Added in 4.3', 'rrze-rsvp' ),
			'use_featured_image'	=> _x( 'Use as Booking image', 'Overrides the “Use as featured image” phrase for this post type. Added in 4.3', 'rrze-rsvp' ),
			'archives'				=> _x( 'Booking archives', 'The post type archive label used in nav menus. Default “Post Archives”. Added in 4.4', 'rrze-rsvp' ),
			'insert_into_item'		=> _x( 'Insert into Booking', 'Overrides the “Insert into post”/”Insert into page” phrase (used when inserting media into a post). Added in 4.4', 'rrze-rsvp' ),
			'uploaded_to_this_item'	=> _x( 'Uploaded to this Booking', 'Overrides the “Uploaded to this post”/”Uploaded to this page” phrase (used when viewing media attached to a post). Added in 4.4', 'rrze-rsvp' ),
			'filter_items_list'		=> _x( 'Filter Bookings list', 'Screen reader text for the filter links heading on the post type listing screen. Default “Filter posts list”/”Filter pages list”. Added in 4.4', 'rrze-rsvp' ),
			'items_list_navigation'	=> _x( 'Bookings list navigation', 'Screen reader text for the pagination heading on the post type listing screen. Default “Posts list navigation”/”Pages list navigation”. Added in 4.4', 'rrze-rsvp' ),
			'items_list'			=> _x( 'Bookings list', 'Screen reader text for the items list heading on the post type listing screen. Default “Posts list”/”Pages list”. Added in 4.4', 'rrze-rsvp' ),
		);
		$args = array(
			'label' => __('Booking', 'rrze-rsvp'),
			'description' => __('Add and edit Booking informations', 'rrze-rsvp'),
			'labels' => $labels,
			'supports' => array('author', 'revisions'),
			'hierarchical' 				=> false,
			'public' 					=> false,
			'show_ui' 					=> true,
			'show_in_menu' 				=> true,
			'show_in_nav_menus' 		=> true,
			'show_in_admin_bar' 		=> true,
			'menu_position' 			=> 5,
			'menu_icon' 				=> 'dashicons-calendar-alt',
			'can_export' 				=> true,
			'has_archive' 				=> false,
			'exclude_from_search' 		=> true,
			'publicly_queryable' 		=> false,
			'capability_type' 			=> ['booking', 'bookings'],
			'map_meta_cap' => true,

		);
		register_post_type('booking', $args);
	}

	public function booking_taxonomies() {

	}

	public function booking_metaboxes() {
	    $cmb = new_cmb2_box( array(
		'id'            => 'rrze-rsvp-booking-details',
		'title'         => __( 'Details', 'rrze-rsvp' ),
		'object_types'  => array( 'booking', ), // Post type
		'context'       => 'normal',
		'priority'      => 'high',
		'show_names'    => true, // Show field names on the left
		// 'cmb_styles' => false, // false to disable the CMB stylesheet
		// 'closed'     => true, // Keep the metabox closed by default
	    ) );

	    $cmb->add_field( array(
		'name'             => __('Date', 'rrze-rsvp'),
		'id'               => 'rrze-rsvp-booking-date',
		'type' => 'text_date_timestamp',
		 'date_format' => 'd.m.Y',
	    ) );
	    $cmb->add_field( array(
		'name' => __( 'Start time', 'rrze-rsvp' ),
		'id' => 'rrze-rsvp-booking-starttime',
		'type' => 'text_time',
		'time_format' => 'H:i',
	    ) );

	    $cmb->add_field( array(
		'name' => __( 'End time', 'rrze-rsvp' ),
		'id' => 'rrze-rsvp-booking-endtime',
		'type' => 'text_time',
		'time_format' => 'H:i',
	    ) );

	    $cmb->add_field( array(
		'name'             => __('Status', 'rrze-rsvp'),
		'id'               => 'rrze-rsvp-booking-status',
		'type'             => 'select',
		'show_option_none' => '&mdash; ' . __('Please select', 'rrze-rsvp') . ' &mdash;',
		'default'          => 'custom',
		'options'          => array(
		    'booked' => __( 'Booked', 'rrze-rsvp' ),
		    'confirmed'   => __( 'Confirmed', 'rrze-rsvp' ),
		    'cancelled'     => __( 'Cancelled', 'rrze-rsvp' ),
		),
	    ) );

	    $cmb->add_field( array(
		'name'             => __('Seat', 'rrze-rsvp'),
		'id'               => 'rrze-rsvp-booking-seat',
		'type'             => 'select',
		'show_option_none' => '&mdash; ' . __('Please select', 'rrze-rsvp') . ' &mdash;',
		'default'          => 'custom',
		'options_cb'       => [$this, 'post_select_options'],
	    ) );

	    $cmb->add_field( array(
		'name'    => __('Last name', 'rrze-rsvp'),
		'id'      => 'rrze-rsvp-booking-guest-lastname',
		'type'    => 'text',
	    ) );

	    $cmb->add_field( array(
		'name'    => __('First name', 'rrze-rsvp'),
		'id'      => 'rrze-rsvp-booking-guest-firstname',
		'type'    => 'text',
	    ) );

	    $cmb->add_field( array(
		'name'    => __('Email', 'rrze-rsvp'),
		'id'      => 'rrze-rsvp-booking-guest-email',
		'type'    => 'text_email',
	    ) );

	    $cmb->add_field( array(
		'name'    => __('Phone', 'rrze-rsvp'),
		'id'      => 'rrze-rsvp-booking-guest-phone',
		'type'    => 'text_medium',
		'attributes' => array(
		    'type' => 'tel',
		),
	    ) );

	    $cmb->add_field( array(
		'name' => __('Notes', 'rrze-rsvp'),
		'id' => 'rrze-rsvp-booking-notes',
		'type' => 'textarea'
	    ) );
	}

    public function post_select_options( $field ) {
        $seats = get_posts([
            'post_type' => 'seat',
            'post_statue' => 'publish',
            'nopaging' => true,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        $options = [];
        foreach ($seats as $seat) {
            $room = get_post_meta($seat->ID, 'rrze-rsvp-seat-room', true);
            $room_title = get_the_title($room);
            $options[$seat->ID] = $room_title . ' – '. $seat->post_title;
        }
        return $options;
    }

    /*
	 * Custom Admin Columns
	 * Source: https://www.smashingmagazine.com/2017/12/customizing-admin-columns-wordpress/
	 */

    function booking_filter_posts_columns( $columns ) {
        $columns['bookingdate'] = __( 'Date', 'rrze-rsvp' );
        $columns['time'] = __( 'Time', 'rrze-rsvp' );
        $columns['room'] = __( 'Room', 'rrze-rsvp' );
        $columns['seat'] = __( 'Seat', 'rrze-rsvp' );
        $columns['name'] = __( 'Name', 'rrze-rsvp' );
        $columns['email'] = __('Email', 'rrze-rsvp');
        $columns['phone'] = __('Phone', 'rrze-rsvp');
        $columns['status'] = __('Status', 'rrze-rsvp');
        return $columns;
    }

    function booking_columns($columns) {
        $columns = array(
            'cb' => $columns['cb'],
            'bookingdate' => __( 'Date', 'rrze-rsvp' ),
            'time' => __( 'Time', 'rrze-rsvp' ),
            'room' => __( 'Room', 'rrze-rsvp' ),
            'seat' => __( 'Seat', 'rrze-rsvp' ),
            'name' => __( 'Name', 'rrze-rsvp' ),
            'email' => __('Email', 'rrze-rsvp'),
            'phone' => __('Phone', 'rrze-rsvp'),
            'status' => __('Status', 'rrze-rsvp'),
        );
        return $columns;
    }

    function booking_column($column, $post_id) {
        $seat = get_post_meta($post_id, 'rrze-rsvp-booking-seat', true) ;
        $room = get_post_meta($seat, 'rrze-rsvp-seat-room', true) ;
        if ('bookingdate' === $column) {
            echo get_post_meta($post_id, 'rrze-rsvp-booking-date', true) ;
        }
        if ( 'time' === $column ) {
            $start = get_post_meta($post_id, 'rrze-rsvp-booking-starttime', true) ;
            $end = get_post_meta($post_id, 'rrze-rsvp-booking-endtime', true) ;
            echo $start . ' - ' . $end;
        }
        if ( 'room' === $column ) {
            echo get_the_title($room);
        }
        if ( 'seat' === $column ) {
            echo get_the_title($seat);
        }
        if ('name' === $column) {
            echo get_post_meta($post_id, 'rrze-rsvp-booking-guest-firstname', true) . ' ' . get_post_meta($post_id, 'rrze-rsvp-booking-guest-lastname', true);
        }
        if ('email' === $column) {
            echo get_post_meta($post_id, 'rrze-rsvp-booking-guest-email', true) ;
        }
        if ('phone' === $column) {
            echo get_post_meta($post_id, 'rrze-rsvp-booking-guest-phone', true) ;
        }
        if ('status' === $column) {
            $status = get_post_meta($post_id, 'rrze-rsvp-booking-status', true) ;
            echo $status . '<br />';
            //if ($status == 'booked') {
                echo '<button class="button button-primary button-small">'.__('Confirm','rrze-rsvp').'</button> <button class="button button-secondary button-small">'.__('Cancel','rrze-rsvp').'</button>';
            //}
        }
    }

    function booking_sortable_columns($columns) {
        $columns = array(
            'bookingdate' => __( 'Date', 'rrze-rsvp' ),
            'time' => __( 'Time', 'rrze-rsvp' ),
            'room' => __( 'Room', 'rrze-rsvp' ),
            'seat' => __( 'Seat', 'rrze-rsvp' ),
            'name' => __( 'Name', 'rrze-rsvp' ),
        );
        return $columns;
    }
}
