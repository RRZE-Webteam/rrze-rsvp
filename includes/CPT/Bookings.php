<?php

/* ---------------------------------------------------------------------------
 * Custom Post Type Booking
 * ------------------------------------------------------------------------- */

namespace RRZE\RSVP\CPT;

defined('ABSPATH') || exit;

use RRZE\RSVP\Capabilities;
use RRZE\RSVP\Functions;
use function RRZE\RSVP\Config\isAllowedSearchForGuest;
// use RRZE\RSVP\Carbon;

class Bookings {
    protected $sDate;
    protected $sTimeslot;
    protected $sRoom;
    protected $filterRoomIDs;
    protected $filterDate;
    protected $filterStart;
    protected $filterEnd;
    protected $sSearch;


    public function __construct() {
        $this->sDate = 'rsvp_booking_date';
        $this->sTimeslot = 'rsvp_booking_timeslot';
        $this->sRoom = 'rsvp_booking_room';
    }

    public function onLoaded() {
        add_action('init', [$this, 'booking_post_type']);
        // add_post_type_support( 'booking', 'page-attributes' );

        add_filter('months_dropdown_results', [$this, 'removeMonthsDropdown'], 10, 2);
        add_filter('manage_booking_posts_columns', [$this, 'addBookingColumns']);
        add_action('manage_booking_posts_custom_column', [$this, 'getBookingValue'], 10, 2);
        add_filter('manage_edit-booking_sortable_columns', [$this, 'addBookingSortableColumns']);
        add_action('restrict_manage_posts', [$this, 'addFilters'], 10, 1);
        add_action('wp_ajax_ShowTimeslots', [$this, 'ajaxShowTimeslots']);

        add_filter('parse_query', [$this, 'filterBookings'], 10);
        add_action('pre_get_posts', [$this, 'searchBookings']);
    }

    


    // Register Custom Post Type
    public function booking_post_type() {
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
            'supports'                  => ['author'], // , 'revisions'
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


    public function addBookingColumns($columns) {
        $columns = array();
        $columns['cb'] = true;
        $columns['bookingdate'] = __('Date', 'rrze-rsvp');
        $columns['bookingstart'] = __('Time', 'rrze-rsvp');
        $columns['room'] = __('Room', 'rrze-rsvp');
        $columns['seat'] = __('Seat', 'rrze-rsvp');
        $columns['name'] = __('Name', 'rrze-rsvp');
        $columns['email'] = __('Email', 'rrze-rsvp');
        if (current_user_can('read_customer_phone')) {
            $columns['phone'] = __('Phone', 'rrze-rsvp');
        }
        $columns['status'] = __('Status', 'rrze-rsvp');
        return $columns;
    }

    public function addBookingSortableColumns($columns) {
        $columns['bookingdate'] = 'bookingdate';
        $columns['bookingstart'] = 'bookingstart';
        $columns['room'] = 'room';
        $columns['seat'] = 'seat';
        $columns['name'] = 'name';
        $columns['email'] = 'email';
        if (current_user_can('read_customer_phone')) {
            $columns['phone'] = 'phone';
        }
        $columns['status'] = 'status';
        return $columns;
    }

    function getBookingValue($column, $post_id) {
        // $post = get_post($post_id);
        $booking = Functions::getBooking($post_id);

        switch ($column) {
            case 'bookingdate':
                $bookingDate = date_i18n(get_option('date_format'), $booking['start']);
                echo $bookingDate;
                break;
            case 'bookingstart':
                $bookingStart = date_i18n(get_option('time_format'), $booking['start']) . ' - ' . date_i18n(get_option('time_format'), $booking['end']);
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
                $publish = ($booking['post_status'] == 'publish');
                $bookingMode = get_post_meta($booking['room'], 'rrze-rsvp-room-bookingmode', true);

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
                    $_wpnonce = wp_create_nonce('status');

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
                        $checkInButton = sprintf(
                            '<a href="edit.php?post_type=%1$s&action=checkin&id=%2$d&_wpnonce=%3$s" class="button">%4$s</a>',
                            'booking',
                            $booking['id'],
                            $_wpnonce,
                            _x('Check-In', 'Booking', 'rrze-rsvp')
                        );
                        $checkoutButton = sprintf(
                            '<a href="edit.php?post_type=%1$s&action=checkout&id=%2$d&_wpnonce=%3$s" class="button">%4$s</a>',
                            'booking',
                            $booking['id'],
                            $_wpnonce,
                            _x('Check-Out', 'Booking', 'rrze-rsvp')
                        );
                        $forceToConfirm = Functions::getBoolValueFromAtt(get_post_meta($booking['room'], 'rrze-rsvp-room-force-to-confirm', true));
                        if ($bookingMode == 'check-only') {
                            switch ($status) {
                                case 'checked-in':
                                    $button = '<button class="button button-primary" disabled>' . _x('Checked-In', 'Booking', 'rrze-rsvp') . '</button>' . $checkoutButton;
                                    break;
                                case 'checked-out':
                                    $button = '<button class="button button-primary" disabled>' . _x('Checked-Out', 'Booking', 'rrze-rsvp') . '</button>' . $checkInButton;
                                    break;
                                case 'booked':
                                default:
                                    $button =  $checkInButton;
                                    break;
                            }
                        } elseif ($status == 'booked' && $forceToConfirm) {
                            $button = _x('Waiting for customer confirmation', 'Booking', 'rrze-rsvp') . $cancelButton;
                        } elseif ($status == 'confirmed') {
                            $button = $cancelButton . $checkInButton;
                        } elseif ($status == 'checked-in') {
                            $button = '<button class="button button-primary" disabled>' . _x('Checked-In', 'Booking', 'rrze-rsvp') . '</button>' . $checkoutButton;
                        } elseif ($status == 'checked-out') {
                            $button = '<button class="button button-primary" disabled>' . _x('Checked-Out', 'Booking', 'rrze-rsvp') . '</button>' . $checkInButton;
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
        }
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
        $sSelectedDate = (string) filter_input(INPUT_GET, $this->sDate, FILTER_SANITIZE_STRING);
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
            $aBookingDates[date("Y-m-d", $bookingStart)] = Functions::dateFormat((int)$bookingStart);

            $bookingEnd = get_post_meta($bookingId, 'rrze-rsvp-booking-end', true);
            $bookingTimeslot = sprintf('%05s', Functions::timeFormat((int)$bookingStart)) . ' - ' . sprintf('%05s', Functions::timeFormat((int)$bookingEnd));
            $aBookingTimeslots[$bookingTimeslot] = $bookingTimeslot;
            // 3. get unique rooms via seat
            $seatId = get_post_meta($bookingId, 'rrze-rsvp-booking-seat', true);
            $roomId = get_post_meta($seatId, 'rrze-rsvp-seat-room', true);
            $aBookingRooms[$roomId] = get_the_title($roomId);
        }

        if ($aBookingDates) {
            ksort($aBookingDates);
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

   
    private function getBookingIDsBySeatRoomTitle( $sSearch ){
        global $wpdb;
        $aBookingIDs = [];
        $aIDs = $wpdb->get_results("SELECT ID FROM $wpdb->posts p, $wpdb->postmeta pm WHERE p.post_type = 'booking' AND p.post_status = 'publish' AND p.id = pm.post_id AND pm.meta_key = 'rrze-rsvp-booking-seat' AND pm.meta_value IN (
                SELECT ID FROM $wpdb->posts p, $wpdb->postmeta pm WHERE p.post_type = 'seat' AND p.post_status = 'publish' AND p.id = pm.post_id AND (p.post_title LIKE '%" . $sSearch . "%' OR (pm.meta_key = 'rrze-rsvp-seat-room' AND pm.meta_value IN 
                (SELECT ID FROM $wpdb->posts WHERE post_type = 'room' AND post_status = 'publish' AND post_title LIKE '%" . $sSearch . "%'))))", ARRAY_A );

        foreach($aIDs as $aID){
            $aBookingIDs[] = $aID['ID'];
        }

        return $aBookingIDs;
    }

    private function setFilterParams(){
        $this->filterRoomIDs = filter_input(INPUT_GET, $this->sRoom, FILTER_VALIDATE_INT);
        $this->filterDate = filter_input(INPUT_GET, $this->sDate, FILTER_SANITIZE_STRING);
        $filterTime = filter_input(INPUT_GET, $this->sTimeslot, FILTER_SANITIZE_STRING);
        if ($filterTime){
            $parts = explode(" - ", $filterTime);
            $this->filterStart = $parts[0];
            $this->filterEnd = $parts[1];
        }
    }


    public function getBookingByGuest( $sSearch ){
        $meta_query = [];

        $sSearchWords = explode(' ', $sSearch);

        foreach($sSearchWords as $sSearch){
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
        }

        if ( count($meta_query) > 1 ) {
            $meta_query['relation'] = 'OR';
        }

        $args = array(
            'fields' => 'ids',
            'post_type'         => 'booking',
            'post_status'       => 'publish',
            'nopaging'          => true,                
            'meta_query' => array($meta_query),
        );

        return get_posts($args);
    }

    private function getBookingIDsByFilter(){
        global $wpdb;
        $ret = [];
        $wpdb->query("SET time_zone = '+00:00'");

        $sql = "SELECT ID FROM $wpdb->posts WHERE post_type = 'booking' AND post_status = 'publish'";

        if ($this->filterRoomIDs) {
            $sql .= " AND ID IN (SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'rrze-rsvp-booking-seat' AND meta_value IN (SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'rrze-rsvp-seat-room' AND meta_value = $this->filterRoomIDs))";
        }

        if ($this->filterDate) {
            $sql .= " AND ID IN (SELECT post_id FROM $wpdb->postmeta WHERE (meta_key = 'rrze-rsvp-booking-start' OR meta_key = 'rrze-rsvp-booking-end') AND DATE_FORMAT(FROM_UNIXTIME(meta_value), '%Y-%m-%d') = '$this->filterDate')";
        }

        if ($this->filterStart){
            $sql .= " AND ID IN (SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'rrze-rsvp-booking-start' AND DATE_FORMAT(FROM_UNIXTIME(meta_value), '%H:%i') = '$this->filterStart')";
        }

        if ($this->filterEnd){
            $sql .= " AND ID IN (SELECT post_id FROM $wpdb->postmeta WHERE meta_key = 'rrze-rsvp-booking-end' AND DATE_FORMAT(FROM_UNIXTIME(meta_value), '%H:%i') = '$this->filterEnd')";
        }

        $aPostIDs = $wpdb->get_results($sql, ARRAY_N);
        foreach($aPostIDs as $postID){
            $ret[] = $postID[0];
        }
        return $ret;
    }


    public function filterBookings($query){
        if (!(is_admin() && $query->is_main_query())) {
            return $query;
        }

        // don't modify query_vars because it's not our post_type
        if (!($query->query['post_type'] === 'booking')) {
            return $query;
        }

        $this->setFilterParams();

        // don't modify query_vars because only default values are given (= "show all ...")
        if (!$this->filterDate && !$this->filterRoomIDs && !$this->filterStart && !$this->filterEnd) {
            return $query;
        }

        $meta_query = $query->get('meta_query', array());

        $aBookingIDs = $this->getBookingIDsByFilter();
            
        $aBookingIDs = ($aBookingIDs ? $aBookingIDs : [-1]);
        $query->set('post__in', $aBookingIDs);

        if ($meta_query) {
            $meta_query['relation'] = 'AND';
            $query->query_vars['meta_query'] = $meta_query;
        }

        return $query;
    }



    public function searchBookings($query) {
        if (!$query->is_main_query() 
            || !(isset($query->query_vars['post_type']) && $query->query_vars['post_type'] == 'booking')) {
            return;
        }

        $aBookingIDs = [];

        $this->sSearch = $query->query_vars['s'];
        if ($this->sSearch){
            if(isAllowedSearchForGuest()){
                $aBookingIDs = array_merge($this->getBookingIDsBySeatRoomTitle($this->sSearch), $this->getBookingByGuest($this->sSearch));
            }else{
                $aBookingIDs = $this->getBookingIDsBySeatRoomTitle($this->sSearch);
            }

            if ($aBookingIDs){
                $filteredBookingIDs = $query->get('post__in');
                if ($filteredBookingIDs){
                    $aBookingIDs = array_intersect($filteredBookingIDs, $aBookingIDs);
                }

                if (!$aBookingIDs){
                    $query->set('post__in', [0]);
                }else{
                    $query->set('post__in', $aBookingIDs);
                }
            }else{
                $query->set('post__in', [0]);
            }
            $query->set('s', '');
        }

        $orderby = $query->get('orderby');

        switch ($orderby){
            case 'bookingdate':
                $query->set('meta_key', 'rrze-rsvp-booking-start');
                $query->set('orderby', 'meta_value_num');
            break;
            // case 'email':
            //     $query->set('meta_key', 'rrze-rsvp-booking-guest-email');
            //     $query->set('orderby', 'meta_value');
            // break;
            // case 'room':
                // $query->set('meta_key', 'rrze-rsvp-booking-seat');
                // $query->set('orderby', get_the_title(get_post_meta('meta_value', 'rrze-rsvp-seat-seat', true)));
                // $query->set('orderby', 'room');
            // break;
            // case 'seat':
            //     $query->set('meta_key', 'rrze-rsvp-booking-seat');
            //     $query->set('orderby', 'meta_value_num');
            // break;
            // case 'status':
            //     $query->set('meta_key', 'rrze-rsvp-booking-status');
            //     $query->set('orderby', get_the_title('meta_value'));
            // break;
             
        }
    }

    public function removeMonthsDropdown($months, $postType){
        if ($postType == 'booking') {
            $months = [];
        }
        return $months;
    }

}
