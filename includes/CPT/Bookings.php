<?php

/* ---------------------------------------------------------------------------
 * Custom Post Type Booking
 * ------------------------------------------------------------------------- */

namespace RRZE\RSVP\CPT;

defined('ABSPATH') || exit;

use RRZE\RSVP\Capabilities;
use RRZE\RSVP\Functions;
use RRZE\RSVP\Carbon;

class Bookings
{
    protected $sDate;
    protected $sRoom;


    public function __construct()
    {
        $this->sDate = 'rsvp_booking_date';
        $this->sRoom = 'rsvp_booking_room';
    }

    public function onLoaded()
    {
        add_action('init', [$this, 'booking_post_type']);
        add_action('add_meta_boxes', [$this, 'not_cmb_metabox']);
        //add_filter( 'manage_edit-booking_columns', [$this, 'booking_filter_posts_columns'] );
        add_filter('manage_booking_posts_columns', [$this, 'booking_columns']);
        add_action('manage_booking_posts_custom_column', [$this, 'booking_column'], 10, 2);
        add_filter('manage_edit-booking_sortable_columns', [$this, 'booking_sortable_columns']);
        add_action('wp_ajax_ShowTimeslots', [$this, 'ajaxShowTimeslots']);
        add_action('restrict_manage_posts', [$this, 'addFilters'], 10, 1);
        add_filter('parse_query', [$this, 'filterQuery'], 10);
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
            'hierarchical'              => false,
            'public'                    => false,
            'show_ui'                   => true,
            'show_in_menu'              => true,
            'show_in_admin_bar'         => true,
            'menu_position'             => 5,
            'menu_icon'                 => 'dashicons-calendar-alt',
            'can_export'                => false,
            'has_archive'               => false,
            'exclude_from_search'       => true,
            'publicly_queryable'        => false,
            'delete_with_user'          => false,
            'capability_type'           => Capabilities::getCptCapabilityType('booking'),
            'capabilities'              => (array) Capabilities::getCptCaps('booking'),
            'map_meta_cap'              => Capabilities::getCptMapMetaCap('booking')
        ];

        register_post_type('booking', $args);
    }

    public function booking_taxonomies()
    {
    }

    public function not_cmb_metabox()
    {
        add_meta_box('rrze-rsvp-room-shortcode-helper', esc_html__('Shortcode', 'rrze-rsvp'), [$this, 'not_cmb_metabox_callback'], 'room', 'side', 'high');
    }

    public function not_cmb_metabox_callback()
    {
        printf(
            __('%sTo add a booking form for this room, add the following shortcode to a page:%s'
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
            '</p>'
        );
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
                $start = new Carbon(date('Y-m-d H:i:s', $booking['start']), wp_timezone());
                if ($booking['status'] == 'cancelled' && $start->endOfDay()->gt(new Carbon('now'))) {
                    $cancelledButton = '<button class="button button-secondary" disabled>' . _x('Cancelled', 'Booking', 'rrze-rsvp') . '</button>';
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
                        default:
                            $button = '';
                    }
                    if (!in_array($booking['status'], ['checked-in', 'checked-out'])) {
                        $button = sprintf(
                            '<a href="edit.php?post_type=%1$s&action=delete&id=%2$d&_wpnonce=%3$s" class="delete">%4$s</a>',
                            'booking',
                            $booking['id'],
                            $_wpnonce,
                            __('Delete', 'rrze-rsvp')
                        );
                    }
                }
                echo $button . $bookingDate;
            } else {
                $cancelButton = sprintf(
                    '<a href="edit.php?post_type=%1$s&action=cancel&id=%2$d&_wpnonce=%3$s" class="button button-secondary" data-id="%2$d">%4$s</a>',
                    'booking',
                    $booking['id'],
                    $_wpnonce,
                    _x('Cancel', 'Booking', 'rrze-rsvp')
                );
                if ($booking['status'] == 'confirmed') {
                    $button = $cancelButton . "<button class='button button-primary' disabled>" . __('Confirmed', 'rrze-rsvp') . "</button>";
                } elseif ($booking['status'] == 'checked-in') {
                    $button = "<button class='button button-secondary' disabled>" . __('Checked-In', 'rrze-rsvp') . "</button>";
                } elseif ($booking['status'] == 'checked-out') {
                    $button = "<button class='button button-secondary' disabled>" . __('Checked-Out', 'rrze-rsvp') . "</button>";
                } else {
                    $button = $cancelButton . sprintf(
                        '<a href="edit.php?post_type=%1$s&action=confirm&id=%2$d&_wpnonce=%3$s" class="button button-primary" data-id="%2$d">%4$s</a>',
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

    public function ajaxShowTimeslots()
    {
        $output = '';
        $seat = ((isset($_POST['seat']) && $_POST['seat'] > 0) ? (int)$_POST['seat'] : '');
        $date_raw = (isset($_POST['date']) ? sanitize_text_field($_POST['date']) : false);
        if (strpos($date_raw, '.') !== false) {
            $date_parts = explode('.', $date_raw);
            $date = $date_parts[2] . '-' . $date_parts[1] . '-' . $date_parts[0];
        }
        $availability = Functions::getSeatAvailability($seat, $date, $date);
        $output .= '<div class="select_timeslot_container" style="display:inline-block;padding-left: 10px;">';
        if (isset($availability[$date])) {
            $output .= '<select class="select_timeslot">'
                . '<option value="">' . __('Select timeslot', 'rrze-rsvp') . '</option>';

            foreach ($availability[$date] as $timeslot) {
                $time_parts = explode('-', $timeslot);
                $output .= '<option value="' . $time_parts[0] . '" data-end="' . $time_parts[1] . '">' . $timeslot . '</option>';
            }
            $output .= '</select>';
            //            wp_send_json($availability[$date]);
        } else {
            $output .= __('No timeslots available for this seat/day.', 'rrze-rsvp');
        }
        $output .= '</div>';
        echo $output;
        wp_die();
    }


    public function addFilters($post_type)
    {
        if ($post_type != 'booking') {
            return;
        }

        $sAllDates = __('Show all dates', 'rrze-rsvp');
        $sAllRoomes = __('Show all rooms', 'rrze-rsvp');
        $sSelectedDate = (string) filter_input(INPUT_GET, $this->sDate, FILTER_VALIDATE_INT);
        $sSelectedRoom = (string) filter_input(INPUT_GET, $this->sRoom, FILTER_SANITIZE_STRING);

        // 1. get all booking IDs
        $aBookingIds = get_posts([
            'post_type' => 'booking',
            'nopaging' => true,
            'fields' => 'ids'
        ]);

        $aBookingDates = [];
        $aBookingRooms = [];

        foreach ($aBookingIds as $bookingId) {
            // 2. get unique dates
            $bookingDate = get_post_meta($bookingId, 'rrze-rsvp-booking-start', true);
            $aBookingDates[$bookingDate] = Functions::dateFormat($bookingDate);
            // 3. get unique rooms via seat
            $seatId = get_post_meta($bookingId, 'rrze-rsvp-booking-seat', true);
            $roomId = get_post_meta($seatId, 'rrze-rsvp-seat-room', true);
            $aBookingRooms[$roomId] = get_the_title($roomId);
        }

        if ($aBookingDates) {
            Functions::sortArrayKeepKeys($aBookingDates);
            echo Functions::getSelectHTML($this->sDate, $sAllDates, $aBookingDates, $sSelectedDate);
        }

        if ($aBookingRooms) {
            Functions::sortArrayKeepKeys($aBookingRooms);
            echo Functions::getSelectHTML($this->sRoom, $sAllRoomes, $aBookingRooms, $sSelectedRoom);
        }
    }

    public function filterQuery($query)
    {
        if (!(is_admin() and $query->is_main_query())) {
            return $query;
        }

        // don't modify query_vars because it's not our post_type
        if (!($query->query['post_type'] === 'booking')) {
            return $query;
        }

        $filterDate = filter_input(INPUT_GET, $this->sDate, FILTER_VALIDATE_INT);
        $roomId = filter_input(INPUT_GET, $this->sRoom, FILTER_VALIDATE_INT);

        // don't modify query_vars because only default values are given (= "show all ...")
        if (!($filterDate || $roomId)) {
            return $query;
        }

        $meta_query = [];
        if ($roomId) {
            // get 1 seatId for given room
            $sSeatIds = get_posts([
                'post_type' => 'seat',
                'meta_key' => 'rrze-rsvp-seat-room',
                'meta_value' => $roomId,
                'numberposts' => 1,
                'fields' => 'ids'
            ]);

            if (isset($sSeatIds[0])) {
                $meta_query[] = array(
                    'key' => 'rrze-rsvp-booking-seat',
                    'value' => $sSeatIds[0]
                );
            }
        }

        if ($filterDate) {
            $meta_query[] = array(
                'key' => 'rrze-rsvp-booking-start',
                'value' => $filterDate
            );
        }

        if ($meta_query) {
            $meta_query['relation'] = 'AND';
            $query->query_vars['meta_query'] = $meta_query;
        }

        return $query;
    }
}
