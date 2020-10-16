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
    protected $sTimeslot;
    protected $sRoom;
    protected $filterRoomIDs;
    protected $filterDate;
    protected $filterStart;
    protected $filterEnd;
    protected $sSearch;


    public function __construct()
    {
        $this->sDate = 'rsvp_booking_date';
        $this->sTimeslot = 'rsvp_booking_timeslot';
        $this->sRoom = 'rsvp_booking_room';
    }

    public function onLoaded()
    {
        add_action('init', [$this, 'booking_post_type']);
        //add_filter( 'manage_edit-booking_columns', [$this, 'booking_filter_posts_columns'] );
        add_filter('manage_booking_posts_columns', [$this, 'booking_columns']);
        add_action('manage_booking_posts_custom_column', [$this, 'booking_column'], 10, 2);
        add_filter('manage_edit-booking_sortable_columns', [$this, 'booking_sortable_columns']);
        add_action('wp_ajax_ShowTimeslots', [$this, 'ajaxShowTimeslots']);
        add_action('restrict_manage_posts', [$this, 'addFilters'], 10, 1);


        add_filter( 'query_vars', [$this, 'registerQueryVarsBookingSearch'] );


        // add_filter('parse_query', [$this, 'filterQuery'], 10);
        add_action('pre_get_posts', [$this, 'searchBookings']);

        //add_filter('views_edit-booking', [$this, 'bookingViews']);
        // BK TEST
        // add_filter('pre_get_posts', [$this, 'me_search_query']);
    }

    


    // Register Custom Post Type
    public function booking_post_type()
    {
        $labels = [
            'name'                      => _x('Bookings', 'Post type general name', 'rrze-rsvp'),
            'singular_name'             => _x('Booking', 'Post type singular name', 'rrze-rsvp'),
            'menu_name'                 => _x('Bookings', 'Admin Menu text', 'rrze-rsvp'),
            'name_admin_bar'            => _x('Booking', 'Add New on Toolbar', 'rrze-rsvp'),
            'add_new'                   => __('Add New', 'rrze-rsvp'),
            'add_new_item'              => __('Add New Booking', 'rrze-rsvp'),
            'new_item'                  => __('New Booking', 'rrze-rsvp'),
            'edit_item'                 => __('Edit Booking', 'rrze-rsvp'),
            'view_item'                 => __('View Booking', 'rrze-rsvp'),
            'all_items'                 => __('All Bookings', 'rrze-rsvp'),
            'search_items'              => __('Search Bookings', 'rrze-rsvp'),
            'not_found'                 => __('No Bookings found.', 'rrze-rsvp'),
            'not_found_in_trash'        => __('No Bookings found in Trash.', 'rrze-rsvp'),
            'archives'                  => _x('Booking archives', 'The post type archive label used in nav menus. Default “Post Archives”. Added in 4.4', 'rrze-rsvp'),
            'filter_items_list'         => _x('Filter Bookings list', 'Screen reader text for the filter links heading on the post type listing screen. Default “Filter posts list”/”Filter pages list”. Added in 4.4', 'rrze-rsvp'),
            'items_list_navigation'     => _x('Bookings list navigation', 'Screen reader text for the pagination heading on the post type listing screen. Default “Posts list navigation”/”Pages list navigation”. Added in 4.4', 'rrze-rsvp'),
            'items_list'                => _x('Bookings list', 'Screen reader text for the items list heading on the post type listing screen. Default “Posts list”/”Pages list”. Added in 4.4', 'rrze-rsvp'),
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
            'menu_position'             => 18,
            'menu_icon'                 => 'dashicons-calendar-alt',
            'can_export'                => false,
            'has_archive'               => false,
            'exclude_from_search'       => true,
            'publicly_queryable'        => false,
            'delete_with_user'          => false,
            'show_in_rest'              => false,
            'capability_type'           => Capabilities::getCptCapabilityType('booking'),
            'capabilities'              => (array) Capabilities::getCptCaps('booking'),
            'map_meta_cap'              => Capabilities::getCptMapMetaCap('booking')
        ];

        register_post_type('booking', $args);
    }

    public function booking_taxonomies()
    {
    }

    /*
	 * Custom Admin Columns
	 * Source: https://www.smashingmagazine.com/2017/12/customizing-admin-columns-wordpress/
	 */

    function booking_filter_posts_columns($columns)
    {
        $columns['bookingdate'] = __('Date', 'rrze-rsvp');
        $columns['bookingstart'] = __('Time', 'rrze-rsvp');
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
            'bookingstart' => __('Time', 'rrze-rsvp'),
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
        $post = get_post($post_id);
        $booking = Functions::getBooking($post_id);
        $bookingDate = date_i18n(get_option('date_format'), $booking['start']);
        $bookingStart = date_i18n(get_option('time_format'), $booking['start']) . ' - ' . date_i18n(get_option('time_format'), $booking['end']);

        switch ($column) {
            case 'bookingdate':
                echo $bookingDate;
                break;
            case 'bookingstart':
                echo $bookingStart;
                break;
            case 'room':
                echo $booking['room_name'];
                break;
            case 'seat':
                echo $booking['seat_name'];
                break;
            case 'name':
                echo $booking['guest_firstname'] . ' ' . $booking['guest_lastname'];
                break;
            case 'email':
                echo $booking['guest_email'];
                break;
            case 'phone':
                echo $booking['guest_phone'];
                break;
            case 'status':
                $status = $booking['status'];
                $end = $booking['end'];
                $now = current_time('timestamp');
                $bookingDate = '<span class="booking_date">' . __('Booked on', 'rrze-rsvp') . ' ' . $booking['booking_date'] . '</span>';
                $archive = ($end < $now);
                $_wpnonce = wp_create_nonce('status');
                $publish = ($post->post_status == 'publish');

                if ($publish && $archive) {
                    switch ($status) {
                        case 'cancelled':
                            $button = '<span class="delete">' . _x('Cancelled', 'Booking', 'rrze-rsvp') . '</span>';
                            break;
                        case 'booked':
                            $button = '<span class="delete">' . _x('Booked', 'Booking', 'rrze-rsvp') . '</span>';
                            break;
                        case 'confirmed':
                            $button = '<span class="delete">' . _x('Confirmed', 'Booking', 'rrze-rsvp') . '</span>';
                            break;
                        case 'checked-in':
                            $button = _x('Checked-In', 'Booking', 'rrze-rsvp');
                            break;
                        case 'checked-out':
                            $button = _x('Checked-Out', 'Booking', 'rrze-rsvp');
                            break;
                        default:
                            $button = '';
                    }
                    echo $button, $bookingDate;
                } elseif ($publish) {
                    if ($status == 'cancelled') {
                        $cancelledButton = '<button class="button button-secondary" disabled>' . _x('Cancelled', 'Booking', 'rrze-rsvp') . '</button>';
                        $restoreButton = sprintf(
                            '<a href="edit.php?post_type=%1$s&action=restore&id=%2$d&_wpnonce=%3$s" class="button">%4$s</a>',
                            'booking',
                            $booking['id'],
                            $_wpnonce,
                            _x('Restore', 'Booking', 'rrze-rsvp')
                        );
                        $button = $cancelledButton . $restoreButton;
                    } else {
                        $cancelButton = sprintf(
                            '<a href="edit.php?post_type=%1$s&action=cancel&id=%2$d&_wpnonce=%3$s" class="button button-secondary" data-id="%2$d">%4$s</a>',
                            'booking',
                            $booking['id'],
                            $_wpnonce,
                            _x('Cancel', 'Booking', 'rrze-rsvp')
                        );
                        $checkoutButton = sprintf(
                            '<a href="edit.php?post_type=%1$s&action=checkout&id=%2$d&_wpnonce=%3$s" class="button">%4$s</a>',
                            'booking',
                            $booking['id'],
                            $_wpnonce,
                            _x('Check-Out', 'Booking', 'rrze-rsvp')
                        );                    
                        if ($status == 'confirmed') {
                            $button = $cancelButton . '<button class="button button-primary" disabled>' . _x('Confirmed', 'Booking', 'rrze-rsvp') . '</button>';
                        } elseif ($status == 'checked-in') {
                            $button = '<button class="button button-primary" disabled>' . _x('Checked-In', 'Booking', 'rrze-rsvp') . '</button>' . $checkoutButton;
                        } elseif ($status == 'checked-out') {
                            $button = _x('Checked-Out', 'Booking', 'rrze-rsvp');
                        } else {
                            $button = $cancelButton . sprintf(
                                '<a href="edit.php?post_type=%1$s&action=confirm&id=%2$d&_wpnonce=%3$s" class="button button-primary" data-id="%2$d">%4$s</a>',
                                'booking',
                                $booking['id'],
                                $_wpnonce,
                                _x('Confirm', 'Booking', 'rrze-rsvp')
                            );
                        }                        
                    }                    
                    echo $button, $bookingDate;
                } else {
                    echo '&mdash;';
                }
                break;
            default:
                //
        }
    }

    function booking_sortable_columns($columns)
    {
        $columns = array(
            'bookingdate' => __('Date', 'rrze-rsvp'),
            'bookingstart' => __('Time', 'rrze-rsvp'),
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
        $sAllTimeslots = __('Show all time slots', 'rrze-rsvp');
        $sAllRoomes = __('Show all rooms', 'rrze-rsvp');
        $sSelectedDate = (string) filter_input(INPUT_GET, $this->sDate, FILTER_VALIDATE_INT);
        $sSelectedTimeslot = (string) filter_input(INPUT_GET, $this->sTimeslot, FILTER_SANITIZE_STRING);
        $sSelectedRoom = (string) filter_input(INPUT_GET, $this->sRoom, FILTER_VALIDATE_INT);

        // 1. get all booking IDs
        $aBookingIds = get_posts([
            'post_type' => 'booking',
            'nopaging' => true,
            'fields' => 'ids'
        ]);

        $aBookingDates = [];
        $aBookingTimeslots = [];
        $aBookingRooms = [];

        foreach ($aBookingIds as $bookingId) {
            // 2. get unique dates
            $bookingStart = get_post_meta($bookingId, 'rrze-rsvp-booking-start', true);
            $aBookingDates[$bookingStart] = Functions::dateFormat($bookingStart);

            $bookingEnd = get_post_meta($bookingId, 'rrze-rsvp-booking-end', true);
            $bookingTimeslot = sprintf('%05s', Functions::timeFormat($bookingStart)) . ' - ' . sprintf('%05s', Functions::timeFormat($bookingEnd));
            $aBookingTimeslots[$bookingTimeslot] = $bookingTimeslot;
            // 3. get unique rooms via seat
            $seatId = get_post_meta($bookingId, 'rrze-rsvp-booking-seat', true);
            $roomId = get_post_meta($seatId, 'rrze-rsvp-seat-room', true);
            $aBookingRooms[$roomId] = get_the_title($roomId);
        }

        if ($aBookingDates) {
            Functions::sortArrayKeepKeys($aBookingDates);
            echo Functions::getSelectHTML($this->sDate, $sAllDates, $aBookingDates, $sSelectedDate);
        }

        if ($aBookingTimeslots) {
            Functions::sortArrayKeepKeys($aBookingTimeslots);
            echo Functions::getSelectHTML($this->sTimeslot, $sAllTimeslots, $aBookingTimeslots, $sSelectedTimeslot);
        }

        if ($aBookingRooms) {
            Functions::sortArrayKeepKeys($aBookingRooms);
            echo Functions::getSelectHTML($this->sRoom, $sAllRoomes, $aBookingRooms, $sSelectedRoom);
        }
    }



    public function registerQueryVarsBookingSearch( $vars ) {
        $vars[] = 'room';
        $vars[] = 'date';
        $vars[] = 'time';
        return $vars;
    }
 
    private function getPostIDsByTitleSubstring( $sSearch ){
        global $wpdb;
        $aPostIDs = $wpdb->get_results( "SELECT ID FROM $wpdb->posts WHERE post_type IN ('room', 'seat') AND post_title LIKE '%" . $sSearch . "%'", OBJECT_K );
        return array_keys($aPostIDs);
    }


    private function setFilterParams( string $sSearch = '' ){
        if ($sSearch){
            $aRoomIDs = $this->getPostIDsByTitleSubstring($sSearch);

            // find matches to phone, email, firstname, lastname
        }
        $aRoomIDs[] = filter_input(INPUT_GET, $this->sRoom, FILTER_VALIDATE_INT);
        $this->filterRoomIDs = $aRoomIDs;
        $this->filterDate = filter_input(INPUT_GET, $this->sDate, FILTER_VALIDATE_INT);
        $filterTime = filter_input(INPUT_GET, $this->sTimeslot, FILTER_SANITIZE_STRING);
        if ($filterTime){
            $parts = explode(" - ", $filterTime);
            $this->filterStart = strtotime($parts[0]);
            $this->filterEnd = strtotime($parts[0]);
        }
    }


    public function getMetaQueryGuest( string $sSearch ){
        $meta_query = [];
        $encryptedSearch = Functions::crypt($sSearch, 'encrypt');

        $encryptedFields = [
            'rrze-rsvp-booking-guest-firstname',
            'rrze-rsvp-booking-guest-lastname',
            'rrze-rsvp-booking-guest-email',
            'rrze-rsvp-booking-guest-phone',
        ];

        foreach($encryptedFields as $field){
            $meta_query[] = [
                'key'     => $field,
                'value'   => $encryptedSearch,
                'compare' => 'LIKE',    
            ];
        }

        if ( count($meta_query) > 1 ) {
            $meta_query['relation'] = 'OR';
        }

        return $meta_query;
    }

    // meta_query combines search for firstname, lastname, phone, email, room's title, seat's title, booking start, booking end, booking date
    private function getMetaQuery(){

        $meta_query = [];

        // the rooms that match to entered keyword (found in room's title or seat's title)
        if ($this->filterRoomIDs){
            $meta_query[] = array(
                'key' => 'rrze-rsvp-seat-room',
                'value' => $this->filterRoomIDs,
                'compare' => 'IN',
            );
        }

        // the dropdowns to filter by:
        if ($this->filterDate) {
            $meta_query[] = array(
                'key' => 'rrze-rsvp-booking-start',
                'value' => $this->filterDate,
            );
        }

        // we've got to look for time !
        // if ($this->filterStart) {
        //     $meta_query[] = array(
        //         'key' => 'rrze-rsvp-booking-start',
        //         'value' => $this->filterStart,
        //         'type' => 'NUMERIC'
        //         // 'type' => 'TIME'
        //     );
        // }

        // if ($this->filterEnd) {
        //     $meta_query[] = array(
        //         'key' => 'rrze-rsvp-booking-end',
        //         'value' => $this->filterEnd,
        //         'type' => 'NUMERIC'
        //         // 'type' => 'TIME'
        //     );
        // }

        if ( count($meta_query) > 1 ){
            $meta_query['relation'] = 'AND';
        }

        $meta_query_guest = $this->getMetaQueryGuest();
        if ($meta_query_guest){
            $meta_query = array(
                'relation' => 'AND',
                $meta_query, 
                $meta_query_guest,
            );
        }

        return $meta_query;
    }

    public function searchBookings($query) {
        // if (is_admin() || !$query->is_main_query() || $query->query['post_type'] != 'booking') {
        if (!$query->is_main_query() || $query->query['post_type'] != 'booking') {
            return;
        } 

        $this->sSearch = $query->query_vars['s'];
        $this->setFilterParams();
        $meta_query = $this->getMetaQuery();
        
        if ($meta_query){
            $query->set('meta_query', array($meta_query));
        }

        $query->set('s', ''); 
        echo '<pre>';
        var_dump($query);
        exit;

    }

    public function bookingViews($views)
    {
        if (isset($views['all'])) unset($views['all']);
        if (isset($views['mine'])) unset($views['mine']);
        if (isset($views['publish'])) unset($views['publish']);
        return $views;
    }
}
