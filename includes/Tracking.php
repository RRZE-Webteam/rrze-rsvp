<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use RRZE\RSVP\Settings;

class Tracking {
    const DB_TABLE = 'rrze_rsvp_tracking';
    const DB_VERSION = '1.4.1';
    const DB_VERSION_OPTION_NAME = 'rrze_rsvp_tracking_db_version';

    protected $settings;
    protected $dbVersion;
    protected $dbOptionName;
    protected $contact_tracking_note;


    public function __construct() {
        $this->dbVersion = static::DB_VERSION;
        $this->dbOptionName = static::DB_VERSION_OPTION_NAME;
        $this->settings = new Settings(plugin()->getFile());
        $this->contact_tracking_note = $this->settings->getOption('general', 'contact_tracking_note');
    }


    public function onLoaded() {
        // use cases defined in https://github.com/RRZE-Webteam/rrze-rsvp/issues/110
        if (is_multisite()){
            if (! function_exists('is_plugin_active_for_network')) {
                include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
            }
            if (is_plugin_active_for_network( 'rrze-rsvp-network/rrze-rsvp-network.php' )){
                // use case C "Multisite: mit rrze-rsvp-network":
                // Admin darf CSV NICHT erstellen
                // SuperAdmin erstellt CSV über Menüpunkt in Network-Dashboard
                add_action( 'admin_menu', [$this, 'add_tracking_menu_info'] );
            }else{
                // use case A "Multisite: ohne rrze-rsvp-network":
                // Admin darf CSV erstellen
                // use case D "Multisite: rrze-rsvp-network wird DEAKTIVIERT":
                // Admin darf CSV (wieder) erstellen
                add_action( 'admin_menu', [$this, 'add_tracking_menu'] );
            }
            $this->createTable('network');
        }else{
            // use cases E "Singlesite":
            // Admin darf CVS erstellen
            add_action( 'admin_menu', [$this, 'add_tracking_menu'] );
            $this->createTable('local');
        }
        add_action( 'rrze-rsvp-tracking', [$this, 'doTracking'], 10, 2 );
        add_action( 'wp_ajax_csv_pull', [$this, 'tracking_csv_pull'] );
    }
    

    private static function logError(string $method){
        // uses plugin rrze-log
        global $wpdb;
        do_action('rrze.log.error', 'rrze-rsvp ' . $method . '() returns false $wpdb->last_result= ' . json_encode($wpdb->last_result) . '| $wpdb->last_query= ' . json_encode($wpdb->last_query . '| $wpdb->last_error= ' . json_encode($wpdb->last_error)));
    }

 
    private function isUpdate() {
        // returns true if tracking table does not exist or DB_VERSION has changed 
        $ret = false;
        if (get_site_option($this->dbOptionName, '') != $this->dbVersion) {
            update_site_option($this->dbOptionName, $this->dbVersion);
            do_action('rrze.log.info', 'rrze-rsvp: Tracking DB Update v' . $this->dbVersion);
            $ret = true;
        }
        return $ret;

    }


    public function add_tracking_menu() {
        $menu_id = add_management_page(
            _x( 'Contact tracking', 'admin page title', 'rrze-rsvp' ),
            _x( 'RSVP Contact tracking', 'admin menu entry title', 'rrze-rsvp' ),
            'manage_options',
            'rrze-rsvp-tracking',
            [$this, 'admin_page_tracking_form']
        );
    }


    public function add_tracking_menu_info() {
        $menu_id = add_management_page(
            _x( 'Contact tracking', 'admin page title', 'rrze-rsvp' ),
            _x( 'RSVP Contact tracking', 'admin menu entry title', 'rrze-rsvp' ),
            'manage_options',
            'rrze-rsvp-tracking',
            [$this, 'admin_page_tracking_info']
        );
    }


    public function admin_page_tracking_form() {
        $searchdate = '';
        $delta = 0;
        $guest_email = '';
        $hash_guest_email = '';
        $guest_phone = '';
        $hash_guest_phone = '';
        $aGuests = [];

        echo '<div class="wrap">';
        echo '<h1>' . esc_html_x( 'Contact tracking', 'admin page title', 'rrze-rsvp' ) . '</h1>';

        if ( isset( $_GET['submit']) ) {
            $searchdate = filter_input(INPUT_GET, 'searchdate', FILTER_SANITIZE_STRING); // filter stimmt nicht
            $delta = filter_input(INPUT_GET, 'delta', FILTER_VALIDATE_INT, ['min_range' => 0]);
            $guest_email = filter_input(INPUT_GET, 'guest_email', FILTER_VALIDATE_EMAIL);
            $hash_guest_email = ($guest_email ? Functions::crypt($guest_email, 'encrypt') : '');
            $guest_phone = filter_input(INPUT_GET, 'guest_phone', FILTER_SANITIZE_STRING);
            $guest_phone = preg_replace('/[^0-9]/', '', $guest_phone);
            $hash_guest_phone = ($guest_phone ? Functions::crypt($guest_phone, 'encrypt') : '');

            $aGuests = Tracking::getUsersInRoomAtDate($searchdate, $delta, $hash_guest_email, $hash_guest_phone);

            if ($aGuests){
                $ajax_url = admin_url('admin-ajax.php?action=csv_pull') . '&page=rrze-rsvp-tracking&searchdate=' . urlencode($searchdate) . '&delta=' . urlencode($delta) . '&hash_guest_email=' . urlencode($hash_guest_email) . '&hash_guest_phone=' . urlencode($hash_guest_phone);
                echo '<div class="notice notice-success is-dismissible">'
                    . '<h2>' . __('Guests found!', 'rrze-rsvp') . '</h2>'
                    . "<a href='$ajax_url'>" . __('Download CSV', 'rrze-rsvp') . '</a>'
                    . '</div>';
            }else{
                echo '<div class="notice notice-success is-dismissible">'
                    . '<h2>' . __('No guest found.', 'rrze-rsvp') . '</h2>'
                    . '</div>';
            }
        }


        echo '<form id="rsvp-search-tracking" method="get">';
        echo '<input type="hidden" name="page" value="rrze-rsvp-tracking">';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr>'
            . '<th scope="row"><label for="searchdate">' . __('Search date', 'rrze-rsvp') . '</label></th>'
            . '<td><input type="text" id="searchdate" name="searchdate" placeholder="YYYY-MM-DD" pattern="(?:19|20)[0-9]{2}-(?:(?:0[1-9]|1[0-2])-(?:0[1-9]|1[0-9]|2[0-9])|(?:(?!02)(?:0[1-9]|1[0-2])-(?:30))|(?:(?:0[13578]|1[02])-31))" value="' . $searchdate . '">'
            . '</td>'
            . '</tr>';
        echo '<tr>'
            . '<th scope="row"><label for="delta">' . '&#177; ' . __('days', 'rrze-rsvp') . '</label></th>'
            . '<td><input type="number" id="delta" name="delta" min="0" required value="' . $delta . '"></td>'
            . '</tr>';
        echo '<tr>'
            . '<th scope="row"><label for="guest_email">' . __('Email', 'rrze-rsvp') . '</label></th>'
            . '<td><input type="text" id="guest_email" name="guest_email" value="' . $guest_email . '">'
            . '</td>'
            . '</tr>';
        echo '<tr>'
            . '<th scope="row"><label for="guest_phone">' . __('Phone', 'rrze-rsvp') . '</label></th>'
            . '<td><input type="text" id="guest_phone" name="guest_phone" value="' . $guest_phone . '">'
            . '</td>'
            . '</tr>';
        echo '</tbody></table>';
        echo '<p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="' . __('Search', 'rrze-rsvp') . '"></p>';
        echo '</form>';
        echo '</div>';
    }


    public function admin_page_tracking_info() {
        echo '<div class="wrap">'
            . '<h1>' . esc_html_x( 'Contact tracking', 'admin page title', 'rrze-rsvp' ) . '</h1>'
            . '<span class="rrze-rsvp-tracking-info">' . $this->contact_tracking_note . '</span>'
            . '</div>';
    }


    public function tracking_csv_pull() {
        $searchdate = filter_input(INPUT_GET, 'searchdate', FILTER_SANITIZE_STRING); // filter stimmt nicht
        $delta = filter_input(INPUT_GET, 'delta', FILTER_VALIDATE_INT, ['min_range' => 0]);
        $hash_guest_email = filter_input(INPUT_GET, 'hash_guest_email', FILTER_SANITIZE_STRING);
        $hash_guest_phone = filter_input(INPUT_GET, 'hash_guest_phone', FILTER_SANITIZE_STRING);

        $aGuests = Tracking::getUsersInRoomAtDate($searchdate, $delta, $hash_guest_email, $hash_guest_phone);

        $filename = 'rrze_tracking_csv_' . date("Y-m-d_H-i", time());

        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename={$filename}.csv");
        $fp = fopen('php://output', 'w');

        $aHeadings = ['START','END','ROOM','STREET','ZIP','CITY','EMAIL','PHONE','FIRSTNAME','LASTNAME'];

        if ($aGuests){
            fputcsv($fp, $aHeadings, ';');
            foreach ($aGuests as $aRow){
                fputcsv($fp, $aRow, ';');
             }
        }
        exit;
    }


    private function getTrackingID(int $blogID, int $bookingID, $trackingTable): int {
        global $wpdb;
        
        $prepare_vals = [
            $blogID,
            $bookingID
        ];

        $row = $wpdb->get_results( 
            $wpdb->prepare("SELECT ID FROM {$trackingTable} WHERE blog_id = %d AND booking_id = %d", $prepare_vals), ARRAY_A);

        return ( isset($row[0]['ID']) ? $row[0]['ID'] : 0 );
    }


    /*
    * inserts, updates or deletes booking infos in table tracking
    */ 
    public function doTracking(int $blogID, int $bookingID) {
        // if row does not exist: insert
        // if row exists in table tracking and $booking['status'] != 'cancelled' : update
        // if row exists in table tracking and $booking['status'] == 'cancelled' : delete

        global $wpdb;

        $trackBookingStatus = [
            'checked-in', // is in the room at the moment
            'checked-out', // has been in the room
            'cancelled', // booking has been cancelled
        ];

        $booking = Functions::getBooking($bookingID);

        if (!$booking) {
            do_action('rrze.log.error', 'rrze-rsvp : BOOKING NOT FOUND | Functions::getBooking() returns [] in Tracking->insertOrUpdateTracking() with $bookingID = ' . $bookingID);
            return;
        }

        if (!in_array($booking['status'], $trackBookingStatus)) {
            return;
        }

        $tableType = get_option('rsvp_tracking_tabletype');
        $trackingTable = Tracking::getTableName($tableType);
        $trackingID = $this->getTrackingID($blogID, $bookingID, $trackingTable);

        if ($trackingID){
            if ($booking['status'] == 'cancelled'){
                $this->deleteTracking($trackingID, $trackingTable);
            }else{
                $this->updateTracking($trackingID, $booking, $trackingTable);
            }
        }else{
            $this->insertTracking($blogID, $booking, $trackingTable);
        }

    } 


    private function insertTracking(int $blogID, array &$booking, string $trackingTable) {
        global $wpdb;
        $ret = false;

        $start = date('Y-m-d H:i:s', get_post_meta($booking['id'], 'rrze-rsvp-booking-start', true));
        $end = date('Y-m-d H:i:s', get_post_meta($booking['id'], 'rrze-rsvp-booking-end', true));

        $guest_phone = preg_replace('/[^0-9]/', '', $booking['guest_phone']);
        $hash_guest_phone = Functions::crypt($guest_phone, 'encrypt');

        $fields = [
            'blog_id' => $blogID,
            'booking_id' => $booking['id'],
            'start' => $start,
            'end' => $end,
            'room_post_id' => (int)$booking['room'],
            'room_name' => $booking['room_name'],
            'room_street' => $booking['room_street'],
            'room_zip' => (int)$booking['room_zip'],
            'room_city' => $booking['room_city'],
            'seat_name' => $booking['seat_name'],
            'hash_guest_firstname' => Functions::crypt($booking['guest_firstname'], 'encrypt'),
            'hash_guest_lastname' => Functions::crypt($booking['guest_lastname'], 'encrypt'),
            'hash_guest_email' => Functions::crypt($booking['guest_email'], 'encrypt'),
            'hash_guest_phone' => $hash_guest_phone
        ];

        $fields_format = [
            '%d',
            '%d',
            '%s',
            '%s',
            '%d',
            '%s',
            '%s',
            '%d',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s'
        ];

        $rowCnt = $wpdb->insert(
            $trackingTable,
            $fields,
            $fields_format
        );

        if ($rowCnt){
            $ret = $wpdb->insert_id;
        }else{
            Tracking::logError('insertTracking');
        }

        return $ret;  // returns the id (AUTO_INCREMENT) or false
    }


    private function updateTracking(int $trackingID, array &$booking, string $trackingTable) {
        global $wpdb;

        $start = date('Y-m-d H:i:s', get_post_meta($booking['id'], 'rrze-rsvp-booking-start', true));
        $end = date('Y-m-d H:i:s', get_post_meta($booking['id'], 'rrze-rsvp-booking-end', true));

        $guest_phone = preg_replace('/[^0-9]/', '', $booking['guest_phone']);
        $hash_guest_phone = Functions::crypt($guest_phone, 'encrypt');

        $fields = [
            'start' => $start,
            'end' => $end,
            'room_post_id' => (int)$booking['room'],
            'room_name' => $booking['room_name'],
            'room_street' => $booking['room_street'],
            'room_zip' => (int)$booking['room_zip'],
            'room_city' => $booking['room_city'],
            'seat_name' => $booking['seat_name'],
            'hash_guest_firstname' => Functions::crypt($booking['guest_firstname'], 'encrypt'),
            'hash_guest_lastname' => Functions::crypt($booking['guest_lastname'], 'encrypt'),
            'hash_guest_email' => Functions::crypt($booking['guest_email'], 'encrypt'),
            'hash_guest_phone' => $hash_guest_phone
        ];

        $fields_format = [
            '%s',
            '%s',
            '%d',
            '%s',
            '%s',
            '%d',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s',
            '%s'
        ];

        $where = [
            'id' => $trackingID
        ];

        $where_format =[
            '%d'
        ];

        $ret = $wpdb->update(
            $trackingTable,
            $fields,
            $where,
            $fields_format,
            $where_format
        ); // returns the number of rows updated, or false on error.

        if (false === $ret){
            Tracking::logError('updateTracking');
        }

        return $ret;
    }


    private function deleteTracking(int $trackingID, string $trackingTable) {
        global $wpdb;

        $ret = $wpdb->delete( $trackingTable, array( 'id' => $trackingID ) );

        if (false === $ret){
            Tracking::logError('deleteTracking');
        }

        return $ret;
    }

    private static function deCryptField(&$item, string $key){
        // $item can be of different types (int, string, ...)
        $aFields = [
            'hash_guest_firstname',
            'hash_guest_lastname',
            'hash_guest_email',
            'hash_guest_phone'
        ];
        if (in_array($key, $aFields)){
            $item = Functions::crypt($item, 'decrypt');
        }
        $item = (string) $item;
    }


    public static function getUsersInRoomAtDate(string $searchdate, int $delta, string $hash_guest_email, string $hash_guest_phone): array {
        global $wpdb;
        $aRet = [];

        if (!$hash_guest_email && !$hash_guest_phone) {
            // we have nothing to search for
            return [];
        }

        if (!Functions::validateDate($searchdate)){
            // is not 'YYYY-MM-DD'
            return [];
        }

        $tableType = get_option('rsvp_tracking_tabletype');
        $trackingTable = Tracking::getTableName($tableType);

        $prepare_vals = [
            $hash_guest_email,
            $hash_guest_phone,
            $searchdate,
            $delta,
            $searchdate,
            $delta,
            $searchdate,
            $delta,
            $searchdate,
            $delta,
        ];

        // 1. get all room_post_id where hash_guest_email or hash_guest_phone has been in the date-span 
        $aRoomsNeedle = $wpdb->get_results( 
            $wpdb->prepare("SELECT room_post_id, start, end  
                FROM {$trackingTable} 
                WHERE (hash_guest_email = %s OR hash_guest_phone = %s) AND 
                (DATE(start) BETWEEN DATE_SUB(%s, INTERVAL %d DAY) AND DATE_ADD(%s, INTERVAL %d DAY)) AND 
                (DATE(end) BETWEEN DATE_SUB(%s, INTERVAL %d DAY) AND DATE_ADD(%s, INTERVAL %d DAY))", $prepare_vals), ARRAY_A); // returns assoc array

        if ($wpdb->last_error){
            Tracking::logError('getUsersInRoomAtDate');
            return [];
        }

        // 2. get needle plus all guests in those room_post_id and datetimes
        if ($aRoomsNeedle){
            foreach($aRoomsNeedle as $aRowNeedle){
                $prepare_vals = [
                    $aRowNeedle['room_post_id'],
                    $aRowNeedle['start'],
                    $aRowNeedle['end'],
                ];

                $aGuests = $wpdb->get_results( 
                    $wpdb->prepare("SELECT 
                        start, end, room_name, room_street, room_zip, room_city, hash_guest_email, hash_guest_phone, hash_guest_firstname, hash_guest_lastname 
                        FROM {$trackingTable}
                        WHERE room_post_id = %d AND
                        start = %s AND
                        end = %s", $prepare_vals), ARRAY_A); // returns assoc array

                foreach($aGuests as $aRowGuests){
                    array_walk($aRowGuests, 'self::deCryptField');
                    $aRet[] = $aRowGuests;
                }

                if ($wpdb->last_error){
                    Tracking::logError('getUsersInRoomAtDate');
                    return [];
                }
            }
        }

        return $aRet;
    }


    protected function createTable( string $tableType = 'network' ) {
        global $wpdb;

        if (false == $this->isUpdate()){
            return;
        }

        // store $tableType we are using for this blog (because of the use cases defined in https://github.com/RRZE-Webteam/rrze-rsvp/issues/110)
        update_option('rsvp_tracking_tabletype', $tableType, false);

        $charsetCollate = $wpdb->get_charset_collate();
        $trackingTable = Tracking::getTableName($tableType);

        $sql = "CREATE TABLE " . $trackingTable . " (
            id bigint(20) UNSIGNED NOT NULL auto_increment,
            blog_id bigint(20) NOT NULL,
            booking_id bigint(20) NOT NULL,
            ts_inserted timestamp DEFAULT CURRENT_TIMESTAMP,
            ts_updated timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            start datetime NOT NULL,
            end datetime NOT NULL,
            room_post_id bigint(20) NOT NULL,
            room_name text NOT NULL,
            room_street text, 
            room_zip varchar(10),
            room_city text, 
            seat_name text NOT NULL, 
            hash_guest_firstname char(64) NOT NULL,
            hash_guest_lastname char(64) NOT NULL,
            hash_guest_email char(64) NOT NULL,
            hash_guest_phone char(64) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uk_blog_booking (blog_id, booking_id)
            ) $charsetCollate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $aRet = dbDelta($sql);
        do_action('rrze.log.info', 'rrze-rsvp: Tracking DB Update v' . $this->dbVersion . ' | $sql = ' . json_encode($sql));
        do_action('rrze.log.info', 'rrze-rsvp: Tracking DB Update v' . $this->dbVersion . ' | dbDelta() returned ' . json_encode($aRet));
    }


    public static function getTableName( string $tableType = 'network', int $blogID = 0 ): string {
        global $wpdb;

        return ( $tableType == 'network' ? $wpdb->base_prefix : $wpdb->get_blog_prefix($blogID) ) . static::DB_TABLE;
    }

}
