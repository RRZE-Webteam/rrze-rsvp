<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use RRZE\RSVP\Settings;

class Tracking {
    const DB_TABLE = 'rrze_rsvp_tracking';
    const DB_VERSION = '1.1.1';
    const DB_VERSION_OPTION_NAME = 'rrze_rsvp_tracking_db_version';

    protected $settings;
    protected $trackingTable;
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
        add_action( 'rrze-rsvp-checked-in', [$this, 'insertTracking'], 10, 2 );
        add_action( 'wp_ajax_csv_pull', [$this, 'tracking_csv_pull'] );
    }
    
 
    protected function isUpdate() {
        // returns true if tracking table does not exist or DB_VERSION has changed 
        // BK 2DO: get_site_option & set_site_option seems to be quite useless if there is no "create OR REPLACE table" (which is "DROP TABLE IF EXISTS `tablename`; CREATE TABLE..." in MySQL) ... combined with caching old data to insert into new table (CREATE TEMPORARY TABLE ... ) but this could be an overkill
        if (get_site_option($this->dbOptionName, '') != $this->dbVersion) {
            update_site_option($this->dbOptionName, $this->dbVersion);
            return true;
        }
        return false;
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
        $guest_phone = '';

        echo '<div class="wrap">';
        echo '<h1>' . esc_html_x( 'Contact tracking', 'admin page title', 'rrze-rsvp' ) . '</h1>';

        if ( isset( $_GET['submit'])) {
            $searchdate = filter_input(INPUT_GET, 'searchdate', FILTER_SANITIZE_STRING); // filter stimmt nicht
            $delta = filter_input(INPUT_GET, 'delta', FILTER_VALIDATE_INT, ['min_range' => 0]);
            $guest_firstname = filter_input(INPUT_GET, 'guest_firstname', FILTER_SANITIZE_STRING);
            $guest_lastname = filter_input(INPUT_GET, 'guest_lastname', FILTER_SANITIZE_STRING);
            $guest_email = filter_input(INPUT_GET, 'guest_email', FILTER_VALIDATE_EMAIL);
            $guest_phone = filter_input(INPUT_GET, 'guest_phone', FILTER_SANITIZE_STRING);

            $aGuests = Tracking::getUsersInRoomAtDate($searchdate, $delta, $guest_firstname, $guest_lastname, $guest_email, $guest_phone);

            if ($aGuests){
                $ajax_url = admin_url('admin-ajax.php?action=csv_pull') . '&page=rrze-rsvp-tracking&searchdate=' . urlencode($searchdate) . '&delta=' . urlencode($delta) . '&guest_firstname=' . urlencode($guest_firstname) . '&guest_lastname=' . urlencode($guest_lastname) . '&guest_email=' . urlencode($guest_email) . '&guest_phone=' . urlencode($guest_phone);
                echo '<div class="notice notice-success is-dismissible">'
                    . '<h2>Guests found!</h2>'
                    . "<a href='$ajax_url'>Download CSV</a>"
                    . '</div>';
            }else{
                echo '<div class="notice notice-success is-dismissible">'
                    . '<h2>No guests found</h2>'
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
        $hash_guest_firstname = filter_input(INPUT_GET, 'guest_firstname', FILTER_SANITIZE_STRING);
        $hash_guest_lastname = filter_input(INPUT_GET, 'guest_lastname', FILTER_SANITIZE_STRING);
        $hash_guest_email = filter_input(INPUT_GET, 'guest_email', FILTER_VALIDATE_EMAIL);
        $hash_guest_phone = filter_input(INPUT_GET, 'guest_phone', FILTER_SANITIZE_STRING);

        $aGuests = Tracking::getUsersInRoomAtDate($searchdate, $delta, $hash_guest_firstname, $hash_guest_lastname, $hash_guest_email, $hash_guest_phone);

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



    public function insertTracking(int $blogID, int $bookingId) {
        // Info: insertTracking() is called via action hook 'rrze-rsvp-checked-in' 
        //       see $this->onLoaded : add_action('rrze-rsvp-checked-in', [$this, 'insertTracking'], 10, 2);
        //       see Actions.php : do_action('rrze-rsvp-checked-in', get_current_blog_id(), $bookingId);

        global $wpdb;

        $booking = Functions::getBooking($bookingId);
        if (!$booking) {
            return;
        }

        $start = date('Y-m-d H:i:s', get_post_meta($bookingId, 'rrze-rsvp-booking-start', true));
        $end = date('Y-m-d H:i:s', get_post_meta($bookingId, 'rrze-rsvp-booking-end', true));

        $fields = [
            'blog_id' => $blogID,
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
            'hash_guest_phone' => Functions::crypt($booking['guest_phone'], 'encrypt'),
        ];

        $wpdb->insert(
            $this->trackingTable,
            $fields,
            [
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
                '%s',
            ]
        );

        return $wpdb->insert_id; // returns the id (AUTO_INCREMENT) or false
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



    public static function getUsersInRoomAtDate(string $searchdate, int $delta, string $hash_guest_firstname, string $hash_guest_lastname, string $hash_guest_email = '', string $hash_guest_phone = ''): array {
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

        //  "Identifikationsmerkmalen für eine Person (Name, E-Mail und oder Telefon)" see https://github.com/RRZE-Webteam/rrze-rsvp/issues/89

        $prepare_vals = [
            $searchdate,
            $delta,
            $searchdate,
            $delta,
            $searchdate,
            $delta,
            $searchdate,
            $delta,
            $searchdate,
            $delta,
            $searchdate,
            $delta,
            $searchdate,
            $delta,
            $searchdate,
            $delta,
            $hash_guest_firstname,
            $hash_guest_lastname,
            $hash_guest_email,
            $hash_guest_phone
        ];

        // simpelst solution would be: 
        // select ... INTO OUTFILE '$path_to_file' FIELDS TERMINATED BY ',' LINES TERMINATED BY ';' from ...
        // but this is a question of user's file writing rights
        $aRows = $wpdb->get_results( 
            $wpdb->prepare("SELECT surrounds.start, surrounds.end, surrounds.room_name, surrounds.room_street, surrounds.room_zip, surrounds.room_city, surrounds.hash_guest_email, surrounds.hash_guest_phone, surrounds.hash_guest_firstname, surrounds.hash_guest_lastname 
            FROM {$trackingTable} AS surrounds 
            WHERE (DATE(surrounds.start) BETWEEN DATE_SUB(%s, INTERVAL %d DAY) AND DATE_ADD(%s, INTERVAL %d DAY)) AND (DATE(surrounds.end) BETWEEN DATE_SUB(%s, INTERVAL %d DAY) AND DATE_ADD(%s, INTERVAL %d DAY)) AND 
            surrounds.room_post_id IN 
            (SELECT needle.room_post_id FROM {$trackingTable} AS needle WHERE 
            (DATE(needle.start) BETWEEN DATE_SUB(%s, INTERVAL %d DAY) AND DATE_ADD(%s, INTERVAL %d DAY)) AND 
            (DATE(needle.end) BETWEEN DATE_SUB(%s, INTERVAL %d DAY) AND DATE_ADD(%s, INTERVAL %d DAY)) AND 
            needle.hash_guest_firstname = %s AND needle.hash_guest_lastname = %s AND
            ((needle.hash_guest_email = %s) OR (needle.hash_guest_phone = %s))) 
            ORDER BY surrounds.start", $prepare_vals), ARRAY_A); // returns assoc array
        array_walk($aRows, 'self::deCryptField');
        return $aRows;
    }


    protected function createTable( string $tableType = 'network' ) {
        global $wpdb;

        if (false == $this->isUpdate()){
            return;
        }

        $trackingTable = Tracking::getTableName($tableType);
        $charsetCollate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS " . $trackingTable . " (
            id bigint(20) UNSIGNED NOT NULL auto_increment,
            blog_id bigint(20) NOT NULL,
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
            KEY k_blog_id (blog_id)
            ) $charsetCollate;";
            // ,UNIQUE KEY uk_guest_room_time (start,end,room_post_id,seat_name,hash_guest_firstname,hash_guest_lastname,hash_guest_email,hash_guest_phone)            

        // echo "<script>console.log('sql = " . $sql . "' );</script>";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $aRet = dbDelta($sql);
        // echo "<script>console.log('dbDelta returns " . json_encode($aRet) . "' );</script>";
    }

    public static function getTableName( string $tableType = 'network', int $blogID = 0 ): string {
        global $wpdb;
        return ( $tableType == 'network' ? $wpdb->base_prefix : $wpdb->get_blog_prefix($blogID) ) . static::DB_TABLE;
    }


    protected function checkTableExists($tableName){
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM information_schema.tables WHERE table_schema = '{$wpdb->dbname}' AND table_name = '{$tableName}' LIMIT 1", ARRAY_A);
    }

}
