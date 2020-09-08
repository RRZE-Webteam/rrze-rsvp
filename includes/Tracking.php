<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use RRZE\RSVP\Settings;

class Tracking {
    const DB_TABLE = 'rrze_rsvp_tracking';
    const DB_VERSION = '1.3.1';
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
        add_action( 'rrze-rsvp-tracking', [$this, 'insertOrUpdateTracking'], 10, 2 );
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
        $guest_firstname = '';
        $guest_lastname = '';
        $guest_email = '';

        echo '<div class="wrap">';
        echo '<h1>' . esc_html_x( 'Contact tracking', 'admin page title', 'rrze-rsvp' ) . '</h1>';

        if ( isset( $_GET['submit'])) {
            $searchdate = filter_input(INPUT_GET, 'searchdate', FILTER_SANITIZE_STRING); // filter stimmt nicht
            $delta = filter_input(INPUT_GET, 'delta', FILTER_VALIDATE_INT, ['min_range' => 0]);
            $guest_firstname = filter_input(INPUT_GET, 'guest_firstname', FILTER_SANITIZE_STRING);
            $guest_lastname = filter_input(INPUT_GET, 'guest_lastname', FILTER_SANITIZE_STRING);
            $guest_email = filter_input(INPUT_GET, 'guest_email', FILTER_VALIDATE_EMAIL);
            $hash_guest_firstname = Functions::crypt($guest_firstname, 'encrypt');
            $hash_guest_lastname = Functions::crypt($guest_lastname, 'encrypt');
            $hash_guest_email = Functions::crypt($guest_email, 'encrypt');
            $aGuests = Tracking::getUsersInRoomAtDate($searchdate, $delta, $hash_guest_firstname, $hash_guest_lastname, $hash_guest_email);

            if ($aGuests){
                $ajax_url = admin_url('admin-ajax.php?action=csv_pull') . '&page=rrze-rsvp-tracking&searchdate=' . urlencode($searchdate) . '&delta=' . urlencode($delta) . '&hash_guest_firstname=' . urlencode($hash_guest_firstname) . '&hash_guest_lastname=' . urlencode($hash_guest_lastname) . '&hash_guest_email=' . urlencode($hash_guest_email);
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
            . '<th scope="row"><label for="guest_firstname">' . __('First name', 'rrze-rsvp') . '</label></th>'
            . '<td><input type="text" id="guest_firstname" name="guest_firstname" value="' . $guest_firstname . '">'
            . '</td>'
            . '</tr>';
        echo '<tr>'
            . '<th scope="row"><label for="guest_lastname">' . __('Last name', 'rrze-rsvp') . '</label></th>'
            . '<td><input type="text" id="guest_lastname" name="guest_lastname" value="' . $guest_lastname . '">'
            . '</td>'
            . '</tr>';
        echo '<tr>'
            . '<th scope="row"><label for="guest_email">' . __('Email', 'rrze-rsvp') . '</label></th>'
            . '<td><input type="text" id="guest_email" name="guest_email" value="' . $guest_email . '">'
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
        $hash_guest_firstname = filter_input(INPUT_GET, 'hash_guest_firstname', FILTER_SANITIZE_STRING);
        $hash_guest_lastname = filter_input(INPUT_GET, 'hash_guest_lastname', FILTER_SANITIZE_STRING);
        $hash_guest_email = filter_input(INPUT_GET, 'hash_guest_email', FILTER_VALIDATE_EMAIL);

        $aGuests = Tracking::getUsersInRoomAtDate($searchdate, $delta, $hash_guest_firstname, $hash_guest_lastname, $hash_guest_email);

        $file = 'rrze_tracking_csv';
        $csv_output = 'START,END,ROOM,STREET,ZIP,CITY,EMAIL,PHONE,FIRSTNAME,LASTNAME'."\n";

        if ($aGuests){
            foreach ($aGuests as $row){
                $row = array_values($row);
                $row = implode(",", $row);
                $csv_output .= $row."\n";
             }
        }
 
        $filename = $file . "_" . date("Y-m-d_H-i", time());
        header( "Content-type: application/vnd.ms-excel" );
        header( "Content-disposition: csv" . date("Y-m-d") . ".csv" );
        header( "Content-disposition: filename=" . $filename . ".csv" );
        print $csv_output;
        exit;
    }


    private function getTrackingID(int $blogID, int $bookingID, $trackingTable): int {
        global $wpdb;
        
        $ret = false;

        $prepare_vals = [
            $blogID,
            $bookingID
        ];

        $row = $wpdb->get_results( 
            $wpdb->prepare("SELECT ID FROM {$trackingTable} WHERE blog_id = %d AND booking_id = %d", $prepare_vals), ARRAY_A);

        if (isset($row["ID"])){
            $ret = $row["ID"];
        } 

        return $ret;
    }


    public function insertOrUpdateTracking(int $blogID, int $bookingID) {
        global $wpdb;

        $trackBookingStatus = [
            'checked-in', // is in the room at the moment
            'checked-out' // has been in the room
        ];

        $booking = Functions::getBooking($bookingID);
        if (!$booking || !in_array($booking['status'], $trackBookingStatus)) {
            return;
        }

        $tableType = get_option('rsvp_tracking_tabletype');
        $trackingTable = Tracking::getTableName($tableType);
        $trackingID = $this->getTrackingID($blogID, $bookingID, $trackingTable);

        if ($trackingID){
            $this->updateTracking($trackingID, $trackingTable);
        }else{
            $this->insertTracking($blogID, $bookingID, $trackingTable);
        }

    } 


    private function insertTracking(int $blogID, int $bookingID, string $trackingTable) {
        global $wpdb;
        $ret = false;

        $start = date('Y-m-d H:i:s', get_post_meta($bookingID, 'rrze-rsvp-booking-start', true));
        $end = date('Y-m-d H:i:s', get_post_meta($bookingID, 'rrze-rsvp-booking-end', true));

        $booking = Functions::getBooking($bookingID);

        $fields = [
            'blog_id' => $blogID,
            'booking_id' => $bookingID,
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
            'hash_guest_phone' => Functions::crypt($booking['guest_phone'], 'encrypt')
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


    private function updateTracking(int $trackingID, string $trackingTable) {
        global $wpdb;

        $start = date('Y-m-d H:i:s', get_post_meta($bookingID, 'rrze-rsvp-booking-start', true));
        $end = date('Y-m-d H:i:s', get_post_meta($bookingID, 'rrze-rsvp-booking-end', true));

        $booking = Functions::getBooking($bookingID);

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
            'hash_guest_phone' => Functions::crypt($booking['guest_phone'], 'encrypt')
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


    private static function deCryptField(&$item, string $key): string{
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
    }


    public static function getUsersInRoomAtDate(string $searchdate, int $delta, string $hash_guest_firstname, string $hash_guest_lastname, string $hash_guest_email): array {
        global $wpdb;

        if (!$hash_guest_email && !$hash_guest_firstname && !$hash_guest_lastname){
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
            $hash_guest_firstname,
            $hash_guest_lastname,
            $hash_guest_email,
            $searchdate,
            $delta,
            $searchdate,
            $delta,
            $searchdate,
            $delta,
            $searchdate,
            $delta,
        ];

        $aRows = $wpdb->get_results( 
            $wpdb->prepare("SELECT 
                tracking.start, tracking.end, tracking.room_name, tracking.room_street, tracking.room_zip, tracking.room_city, tracking.hash_guest_email, tracking.hash_guest_phone, tracking.hash_guest_firstname, tracking.hash_guest_lastname 
                FROM {$trackingTable} tracking
                INNER JOIN  {$trackingTable} start_tracking ON (start_tracking.start = tracking.start)
                INNER JOIN {$trackingTable} end_tracking ON (end_tracking.start = tracking.end)
                WHERE 
                tracking.room_post_id = start_tracking.room_post_id AND 
                tracking.room_post_id = end_tracking.room_post_id AND 
                tracking.hash_guest_firstname = %s AND 
                tracking.hash_guest_lastname = %s AND 
                tracking.hash_guest_email = %s AND 
                (DATE(tracking.start) BETWEEN DATE_SUB(%s, INTERVAL %d DAY) AND DATE_ADD(%s, INTERVAL %d DAY)) AND 
                (DATE(tracking.end) BETWEEN DATE_SUB(%s, INTERVAL %d DAY) AND DATE_ADD(%s, INTERVAL %d DAY))", $prepare_vals), ARRAY_A); // returns assoc array

        if ($wpdb->last_error){
            Tracking::logError('getUsersInRoomAtDate');
            return [];
        }

        array_walk($aRows, 'self::deCryptField');
        return $aRows;
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

        // BK 2DO 2020-09-07:
        // this is not a good solution
        // read https://wordpress.stackexchange.com/questions/78667/dbdelta-alter-table-syntax
        // ==> better use dbDelta strickly 
        // documented here: https://codex.wordpress.org/Creating_Tables_with_Plugins#Adding_an_Upgrade_Function

        // backup and drop table if it exists
        if ($this->checkTableExists($trackingTable)){
            if ($this->backupTable($trackingTable)){
                if ($this->dropTable($trackingTable)){
                    $msg = __('Warning! Database version has changed: tracking table has been backuped. New tracking table is now empty. Contact your SuperAdmin to restore tracking data!', 'rrze-rsvp');
                }else{
                    $msg = __('Error! Database version has changed but could not drop tracking table. Contact your SuperAdmin!', 'rrze-rsvp');
                }
            }else{
                $msg = __('Error! Database version has changed but could not backup tracking table. Contact your SuperAdmin!', 'rrze-rsvp');
            }
            $pluginData = get_plugin_data(plugin()->getFile());
            $pluginName = $pluginData['Name'];
            $tag = is_plugin_active_for_network(plugin()->getBaseName()) ? 'network_admin_notices' : 'admin_notices';
            add_action($tag, function () use ($pluginName, $msg) {
                printf(
                    '<div class="notice notice-error"><p>' . __('Plugins: %1$s: %2$s', 'rrze-rsvp') . '</p></div>',
                    esc_html($pluginName),
                    esc_html($msg)
                );
            });
        }

        $sql = "CREATE TABLE " . $trackingTable . " (
            id bigint(20) UNSIGNED NOT NULL auto_increment,
            blog_id bigint(20) NOT NULL,
            booking_id bigint(20) NOT NULL,
            ts_updated timestamp DEFAULT CURRENT_TIMESTAMP,
            ts_inserted timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            start datetime NOT NULL,
            end datetime NOT NULL,
            room_post_id bigint(20) NOT NULL,
            room_name text NOT NULL,
            room_street text NOT NULL, 
            room_zip varchar(10) NOT NULL,
            room_city text NOT NULL, 
            seat_name text NOT NULL, 
            hash_guest_firstname char(64) NOT NULL,
            hash_guest_lastname char(64) NOT NULL,
            hash_guest_email char(64) NOT NULL,
            hash_guest_phone char(64) NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uk_blog_booking (blog_id, booking_id)
            ) $charsetCollate;";

        // echo "<script>console.log('sql = " . $sql . "' );</script>";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $aRet = dbDelta($sql);
        // echo "<script>console.log('dbDelta returns " . json_encode($aRet) . "' );</script>";
    }


    public static function getTableName( string $tableType = 'network', int $blogID = 0 ): string {
        global $wpdb;

        return ( $tableType == 'network' ? $wpdb->base_prefix : $wpdb->get_blog_prefix($blogID) ) . static::DB_TABLE;
    }


    protected function checkTableExists(string $tableName): bool{
        global $wpdb;

        $ret = $wpdb->get_results("SELECT * FROM information_schema.tables WHERE table_schema = '{$wpdb->dbname}' AND table_name = '{$tableName}' LIMIT 1", ARRAY_A);
        return ( $ret ? true : false ); // $ret can be false, 0 or any integer
    }


    protected function dropTable(string $tableName): bool{
        global $wpdb;

        $ret = $wpdb->query(
               $wpdb->prepare("DROP TABLE IF EXISTS {$tableName}", array($tableName))); // returns true/false

        if (false === $ret){
            Tracking::logError('dropTable');
        }
        return $ret;
    }


    protected function backupTable(string $tableName){
        global $wpdb;

        $ret = $wpdb->query("RENAME TABLE $tableName TO $tableName" . '_backup_' . date('Y_m_d_His')); // returns true/false
        if (false === $ret){
            Tracking::logError('backupTable');
        }
        return $ret;
    }
}
