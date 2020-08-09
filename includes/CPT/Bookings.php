<?php

/* ---------------------------------------------------------------------------
 * Custom Post Type Booking
 * ------------------------------------------------------------------------- */

namespace RRZE\RSVP\CPT;

defined('ABSPATH') || exit;

use RRZE\RSVP\Capabilities;
use RRZE\RSVP\Functions;
use Carbon\Carbon;

class Bookings
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
        add_action('init', [$this, 'booking_post_type'], 0);
        add_action('cmb2_admin_init', [$this, 'booking_metaboxes']);
        add_action( 'add_meta_boxes', [$this, 'not_cmb_metabox'] );
        //add_filter( 'manage_edit-booking_columns', [$this, 'booking_filter_posts_columns'] );
        add_filter('manage_booking_posts_columns', [$this, 'booking_columns']);
        add_action('manage_booking_posts_custom_column', [$this, 'booking_column'], 10, 2);
        add_filter('manage_edit-booking_sortable_columns', [$this, 'booking_sortable_columns']);
    }

    // Register Custom Post Type
    public function booking_post_type()
    {
        $labels = [
            'name'                    => _x('Bookings', 'Post type general name', 'rrze-rsvp'),
            'singular_name'            => _x('Booking', 'Post type singular name', 'rrze-rsvp'),
            'menu_name'                => _x('Bookings', 'Admin Menu text', 'rrze-rsvp'),
            'name_admin_bar'        => _x('Booking', 'Add New on Toolbar', 'rrze-rsvp'),
            'add_new'                => __('Add New', 'rrze-rsvp'),
            'add_new_item'            => __('Add New Booking', 'rrze-rsvp'),
            'new_item'                => __('New Booking', 'rrze-rsvp'),
            'edit_item'                => __('Edit Booking', 'rrze-rsvp'),
            'view_item'                => __('View Booking', 'rrze-rsvp'),
            'all_items'                => __('All Bookings', 'rrze-rsvp'),
            'search_items'            => __('Search Bookings', 'rrze-rsvp'),
            'not_found'                => __('No Bookings found.', 'rrze-rsvp'),
            'not_found_in_trash'    => __('No Bookings found in Trash.', 'rrze-rsvp'),
            'archives'                => _x('Booking archives', 'The post type archive label used in nav menus. Default “Post Archives”. Added in 4.4', 'rrze-rsvp'),
            'filter_items_list'        => _x('Filter Bookings list', 'Screen reader text for the filter links heading on the post type listing screen. Default “Filter posts list”/”Filter pages list”. Added in 4.4', 'rrze-rsvp'),
            'items_list_navigation'    => _x('Bookings list navigation', 'Screen reader text for the pagination heading on the post type listing screen. Default “Posts list navigation”/”Pages list navigation”. Added in 4.4', 'rrze-rsvp'),
            'items_list'            => _x('Bookings list', 'Screen reader text for the items list heading on the post type listing screen. Default “Posts list”/”Pages list”. Added in 4.4', 'rrze-rsvp'),
        ];

        $args = [
            'label'                     => __('Booking', 'rrze-rsvp'),
            'description'               => __('Add and edit Booking informations', 'rrze-rsvp'),
            'labels'                    => $labels,
            'supports'                  => ['author', 'revisions'],
            'hierarchical'                 => false,
            'public'                     => false,
            'show_ui'                     => true,
            'show_in_menu'                 => true,
            'show_in_nav_menus'         => true,
            'show_in_admin_bar'         => true,
            'menu_position'             => 5,
            'menu_icon'                 => 'dashicons-calendar-alt',
            'can_export'                 => false,
            'has_archive'                 => false,
            'exclude_from_search'         => true,
            'publicly_queryable'         => false,
            'capability_type'             => Capabilities::getCptCapabilityType('booking'),
            'capabilities'              => (array) Capabilities::getCptCaps('booking'),
            'map_meta_cap'              => Capabilities::getCptMapMetaCap('booking')
        ];

        register_post_type('booking', $args);
    }

    public function booking_taxonomies()
    {
    }

    public function booking_metaboxes()
    {
        $cmb = new_cmb2_box(array(
            'id'            => 'rrze-rsvp-booking-details',
            'title'         => __('Details', 'rrze-rsvp'),
            'object_types'  => array('booking',), // Post type
            'context'       => 'normal',
            'priority'      => 'high',
            'show_names'    => true, // Show field names on the left
            // 'cmb_styles' => false, // false to disable the CMB stylesheet
            // 'closed'     => true, // Keep the metabox closed by default
        ));

        $cmb->add_field(array(
            'name'             => __('Start', 'rrze-rsvp'),
            'id'               => 'rrze-rsvp-booking-start',
            //'type' => 'text_date_timestamp',
            'type' => 'text_datetime_timestamp',
            'date_format' => 'd.m.Y',
            'time_format' => 'H:i',
            'attributes' => array(
                'data-timepicker' => json_encode(
                    array(
                        'timeFormat' => 'HH:mm',
                        'stepMinute' => 10,
                    )
                ),
            )
        ));

        $cmb->add_field(array(
            'name'             => __('End', 'rrze-rsvp'),
            'id'               => 'rrze-rsvp-booking-end',
            //'type' => 'text_date_timestamp',
            'type' => 'text_datetime_timestamp',
            'date_format' => 'd.m.Y',
            'time_format' => 'H:i',
            'attributes' => array(
                'data-timepicker' => json_encode(
                    array(
                        'timeFormat' => 'HH:mm',
                        'stepMinute' => 10,
                    )
                ),
            )
        ));

        $cmb->add_field(array(
            'name'             => __('Status', 'rrze-rsvp'),
            'id'               => 'rrze-rsvp-booking-status',
            'type'             => 'select',
            'show_option_none' => '&mdash; ' . __('Please select', 'rrze-rsvp') . ' &mdash;',
            'default'          => 'custom',
            'options'          => array(
                'booked' => __('Booked', 'rrze-rsvp'),
                'confirmed'   => __('Confirmed', 'rrze-rsvp'),
                'cancelled'     => _x('Cancelled', 'Booking', 'rrze-rsvp'),
                'checked-in'     => __('Checked In', 'rrze-rsvp'),
                'checked-out'     => __('Checked Out', 'rrze-rsvp'),
            ),
        ));

        $cmb->add_field(array(
            'name'             => __('Seat', 'rrze-rsvp'),
            'id'               => 'rrze-rsvp-booking-seat',
            'type'             => 'select',
            'show_option_none' => '&mdash; ' . __('Please select', 'rrze-rsvp') . ' &mdash;',
            'default'          => 'custom',
            'options_cb'       => [$this, 'post_select_options'],
        ));

        $cmb->add_field(array(
            'name'    => __('Last name', 'rrze-rsvp'),
            'id'      => 'rrze-rsvp-booking-guest-lastname',
            'type'    => 'text',
        ));

        $cmb->add_field(array(
            'name'    => __('First name', 'rrze-rsvp'),
            'id'      => 'rrze-rsvp-booking-guest-firstname',
            'type'    => 'text',
        ));

        $cmb->add_field(array(
            'name'    => __('Email', 'rrze-rsvp'),
            'id'      => 'rrze-rsvp-booking-guest-email',
            'type'    => 'text_email',
        ));

        $cmb->add_field(array(
            'name'    => __('Phone', 'rrze-rsvp'),
            'id'      => 'rrze-rsvp-booking-guest-phone',
            'type'    => 'text_medium',
            'attributes' => array(
                'type' => 'tel',
            ),
        ));

        $cmb->add_field(array(
            'name' => __('Notes', 'rrze-rsvp'),
            'id' => 'rrze-rsvp-booking-notes',
            'type' => 'textarea',
            'desc' => __("This textarea contains the 'Additional Information' field content if this option is activated in the room settings. It can also be used for notes for internal use.", 'rrze-rsvp'),
        ));
    }

    public function not_cmb_metabox()
    {
        add_meta_box( 'rrze-rsvp-room-shortcode-helper', esc_html__( 'Shortcode', 'rrze-rsvp' ), [$this, 'not_cmb_metabox_callback'], 'room', 'side', 'high' );
    }

    public function not_cmb_metabox_callback() {
        printf(__('%sTo add a booking form for this room, add the following shortcode to a page:%s'
         . '[rsvp-booking room="%s" sso="true"]%s'
         . 'Skip %ssso="true"%s to deactivate SSO authentication.%s'
         . 'Add %sdays="20"%s to overwrite the number of days you can book a seat in advance.%s', 'rrze-rsvp'),
            '<p class="description">',
            '</p><p><code>',
            get_the_ID(),
            '</code></p><p>',
            '<code>',
            '</code>',
            '</p><p>',
            '<code>',
            '</code>',
            '</p>');
    }

    public function post_select_options($field)
    {
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
            $options[$seat->ID] = $room_title . ' – ' . $seat->post_title;
        }
        return $options;
    }

    /*
	 * Custom Admin Columns
	 * Source: https://www.smashingmagazine.com/2017/12/customizing-admin-columns-wordpress/
	 */

    function booking_filter_posts_columns($columns)
    {
        $columns['bookingdate'] = __('Date', 'rrze-rsvp');
        $columns['time'] = __('Time', 'rrze-rsvp');
        $columns['room'] = __('Room', 'rrze-rsvp');
        $columns['seat'] = __('Seat', 'rrze-rsvp');
        $columns['name'] = __('Name', 'rrze-rsvp');
        $columns['email'] = __('Email', 'rrze-rsvp');
        $columns['status'] = __('Status', 'rrze-rsvp');
        return $columns;
    }

    function booking_columns($columns)
    {
        $columns = array(
            'cb' => $columns['cb'],
            'bookingdate' => __('Date', 'rrze-rsvp'),
            'time' => __('Time', 'rrze-rsvp'),
            'room' => __('Room', 'rrze-rsvp'),
            'seat' => __('Seat', 'rrze-rsvp'),
            'name' => __('Name', 'rrze-rsvp'),
            'email' => __('Email', 'rrze-rsvp')
        );

        if (current_user_can('read_customer_phone')) {
            $columns['phone'] = __('Phone', 'rrze-rsvp');
        }
        $columns['status'] = __('Status', 'rrze-rsvp');
        return $columns;
    }

    function booking_column($column, $post_id)
    {
        $booking = Functions::getBooking($post_id);
        $date = date_i18n(get_option('date_format'), $booking['start']);
        $time = date_i18n(get_option('time_format'), $booking['start']) . ' - ' . date_i18n(get_option('time_format'), $booking['end']);

        if ('bookingdate' === $column) {
            echo $date;
        }
        if ('time' === $column) {
            echo $time;
        }
        if ('room' === $column) {
            echo get_the_title($booking['room']);
        }
        if ('seat' === $column) {
            echo get_the_title($booking['seat']);
        }
        if ('name' === $column) {
            echo $booking['guest_firstname'] . ' ' . $booking['guest_lastname'];
        }
        if ('email' === $column) {
            echo $booking['guest_email'];
        }
        if ('phone' === $column) {
            echo $booking['guest_phone'];
        }
        if ('status' === $column) {
            $status = $booking['status'];
            $start = $booking['start'];
            $end = $booking['end'];
            $now = current_time('timestamp');
            $bookingDate = '<span class="booking_date">' . __('Booked on', 'rrze-rsvp') . ' ' . $booking['booking_date'] . '</span>';
            $archive = ($status == 'cancelled') || ($end < $now);
            $_wpnonce = wp_create_nonce('status');

            if ($archive) {
                $start = new Carbon(date('Y-m-d H:i:s', $booking['start']));
                if ($booking['status'] == 'cancelled' && $start->endOfDay()->gt(new Carbon('now'))) {
                    $cancelledButton = '<button class="button rrzs-rsvp-cancel" disabled>' . _x('Cancelled', 'Booking', 'rrze-rsvp') . '</button>';
                    $restoreButton = sprintf(
                        '<a href="edit.php?post_type=%1$s&action=restore&id=%2$d&_wpnonce=%3$s" class="button">%4$s</a>',
                        'booking',
                        $booking['id'],
                        $_wpnonce,
                        __('Restore', 'rrze-rsvp')
                    );
                    $button = $cancelledButton . $restoreButton;
                } else {
                    switch ($booking['status']) {
                        case 'cancelled':
                            $button = _x('Cancelled', 'Booking', 'rrze-rsvp');
                            break;
                        case 'booked':
                            $button = __('Booked', 'rrze-rsvp');
                            break;
                        case 'confirmed':
                            $button = __('Confirmed', 'rrze-rsvp');
                            break;
                        case 'checked-in':
                            $button = __('Checked-In', 'rrze-rsvp');
                            break;
                        case 'checked-out':
                            $button = __('Checked-Out', 'rrze-rsvp');
                            break;                                                         
                    }
                    if (! in_array($booking['status'], ['checked-in', 'checked-out'])) {
                        $button = sprintf(
                            '<a href="edit.php?post_type=%1$s&action=delete&id=%2$d&_wpnonce=%3$s" class="delete">%4$s</a>',
                            'booking',
                            $booking['id'],
                            $_wpnonce,
                            __('Delete', 'rrze-rsvp')
                        );
                    } else {
                        $button = '';
                    }
                }
                echo $button . $bookingDate;
            } else {
                $cancelButton = sprintf(
                    '<a href="edit.php?post_type=%1$s&action=cancel&id=%2$d&_wpnonce=%3$s" class="button rrze-rsvp-cancel" data-id="%2$d">%4$s</a>',
                    'booking',
                    $booking['id'],
                    $_wpnonce,
                    _x('Cancel', 'Booking', 'rrze-rsvp')
                );
                if ($booking['status'] == 'confirmed') {
                    $button = $cancelButton . "<button class='button button-primary rrze-rsvp-confirmed' disabled>" . __('Confirmed', 'rrze-rsvp') . "</button>";
                } elseif ($booking['status'] == 'checked-in') {
                    $button = "<button class='button button-primary rrze-rsvp-checkin' disabled>" . __('Checked-In', 'rrze-rsvp') . "</button>";
                } elseif ($booking['status'] == 'checked-out') {
                    $button = "<button class='button button-primary rrze-rsvp-confirmed' disabled>" . __('Checked-Out', 'rrze-rsvp') . "</button>";
                } else {
                    $button = $cancelButton . sprintf(
                        '<a href="edit.php?post_type=%1$s&action=confirm&id=%2$d&_wpnonce=%3$s" class="button button-primary rrze-rsvp-confirm" data-id="%2$d">%4$s</a>',
                        'booking',
                        $booking['id'],
                        $_wpnonce,
                        __('Confirm', 'rrze-rsvp')
                    );
                }
                echo $button . $bookingDate;
            }
        }
    }

    function booking_sortable_columns($columns)
    {
        $columns = array(
            'bookingdate' => __('Date', 'rrze-rsvp'),
            'time' => __('Time', 'rrze-rsvp'),
            'room' => __('Room', 'rrze-rsvp'),
            'seat' => __('Seat', 'rrze-rsvp'),
            'name' => __('Name', 'rrze-rsvp'),
        );
        return $columns;
    }
}
