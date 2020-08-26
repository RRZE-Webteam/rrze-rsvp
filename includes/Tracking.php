<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use RRZE\RSVP\Settings;

class Tracking
{
    const DB_TABLE = 'rrze_rsvp_tracking';

    const DB_VERSION = '1.0.0';

    const DB_VERSION_OPTION_NAME = 'rrze_rsvp_tracking_db_version';

    protected $dbTable;

    protected $dbVersion;

    protected $dbOptionName;

    protected $contact_tracking_note;


    public function __construct()
    {
        global $wpdb;
        $this->dbTable = $wpdb->base_prefix . static::DB_TABLE;
        $this->dbVersion = static::DB_VERSION;
        $this->dbOptionName = static::DB_VERSION_OPTION_NAME;
        $settings = new Settings(plugin()->getFile());
        $this->contact_tracking_note = $settings->getOption('general', 'contact_tracking_note');
    }

    public function onLoaded()
    {
        $this->updateDbVersion();

        if (is_multisite()) {
            add_action( 'network_admin_menu', [$this, 'add_tracking_networkmenu']) ;
            add_action( 'admin_menu', [$this, 'add_tracking_menuinfo'] );
        }else{
            add_action( 'admin_menu', [$this, 'add_tracking_menu'] );
        }
        add_action( 'wp_ajax_csv_pull', [$this, 'tracking_csv_pull'] );
    }

    protected function updateDbVersion()
    {
        if (get_site_option($this->dbOptionName, NULL) != $this->dbVersion) {
            $this->createTrackingTable();
            update_site_option($this->dbOptionName, $this->dbVersion);
        }
    }

    public function add_tracking_menu() {
        $menu_id = add_management_page(
            _x( 'Contact tracking', 'admin page title', 'rrze-rsvp' ),
            _x( 'RSVP Contact tracking', 'admin menu entry title', 'rrze-rsvp' ),
            'manage_options',
            'rrze-rsvp-tracking',
            [$this, 'admin_page_tracking']
        );
    }

    public function add_tracking_networkmenu() {
        $menu_id = add_menu_page(
            _x( 'Contact tracking', 'admin page title', 'rrze-rsvp' ),
            _x( 'RSVP Contact tracking', 'admin menu entry title', 'rrze-rsvp' ),
            'manage_network_options',
            'rrze-rsvp-tracking',
            [$this, 'admin_page_tracking']
        );
    }

    public function add_tracking_menuinfo() {
        $menu_id = add_management_page(
            _x( 'Contact tracking', 'admin page title', 'rrze-rsvp' ),
            _x( 'RSVP Contact tracking', 'admin menu entry title', 'rrze-rsvp' ),
            'manage_options',
            'rrze-rsvp-tracking',
            [$this, 'admin_page_trackinginfo']
        );
    }


    public function admin_page_tracking() {
        $searchdate = '';
        $delta = 0;
        $guest_firstname = '';
        $guest_lastname = '';
        $guest_email = '';
        $guest_phone = '';

        echo '<div class="wrap">';
        echo '<h1>' . esc_html_x( 'Contact tracking', 'admin page title', 'rrze-rsvp' ) . '</h1>';

        if ( isset( $_GET['submit'])) {
            /*
             * Submit Form, Search for users
             */

            $searchdate = filter_input(INPUT_GET, 'searchdate', FILTER_SANITIZE_STRING); // filter stimmt nicht
            $delta = filter_input(INPUT_GET, 'delta', FILTER_VALIDATE_INT, ['min_range' => 0]);
            $guest_firstname = filter_input(INPUT_GET, 'guest_firstname', FILTER_SANITIZE_STRING);
            $guest_lastname = filter_input(INPUT_GET, 'guest_lastname', FILTER_SANITIZE_STRING);
            $guest_email = filter_input(INPUT_GET, 'guest_email', FILTER_VALIDATE_EMAIL);
            $guest_phone = filter_input(INPUT_GET, 'guest_phone', FILTER_SANITIZE_STRING);

            $aGuests = Tracking::getUsersInRoomAtDate($searchdate, $delta, $guest_firstname, $guest_lastname, $guest_email, $guest_phone);

            if ($aGuests){
                // generate CSV
                $ajax_url = admin_url('admin-ajax.php?action=csv_pull') . '&page=rrze-rsvp-tracking&searchdate=' . urlencode($searchdate) . '&delta=' . urlencode($delta) . '&guest_firstname=' . urlencode($guest_firstname) . '&guest_lastname=' . urlencode($guest_lastname) . '&guest_email=' . urlencode($guest_email) . '&guest_phone=' . urlencode($guest_phone);
                echo '<div class="notice notice-success is-dismissible">';
                echo '<h2>Guests found!</h2>';
                echo "<a href='$ajax_url'>Download CSV</a>";
                echo '</div>';
            }else{
                echo '<div class="notice notice-success is-dismissible">';
                echo '<h2>No guests found</h2>';
                echo '</div>';
            }
        }

        /*
         * Build Form
         */

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
            . '</tr>'; // $value_delta
        
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

    public function admin_page_trackinginfo() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html_x( 'Contact tracking', 'admin page title', 'rrze-rsvp' ) . '</h1>';
        echo '<span class="rrze-rsvp-tracking-info">' . $this->contact_tracking_note . '</span>';
        echo '</div>';
    }

    public function tracking_csv_pull() {
        $searchdate = filter_input(INPUT_GET, 'searchdate', FILTER_SANITIZE_STRING); // filter stimmt nicht
        $delta = filter_input(INPUT_GET, 'delta', FILTER_VALIDATE_INT, ['min_range' => 0]);
        $guest_firstname = filter_input(INPUT_GET, 'guest_firstname', FILTER_SANITIZE_STRING);
        $guest_lastname = filter_input(INPUT_GET, 'guest_lastname', FILTER_SANITIZE_STRING);
        $guest_email = filter_input(INPUT_GET, 'guest_email', FILTER_VALIDATE_EMAIL);
        $guest_phone = filter_input(INPUT_GET, 'guest_phone', FILTER_SANITIZE_STRING);

        $aGuests = Tracking::getUsersInRoomAtDate($searchdate, $delta, $guest_firstname, $guest_lastname, $guest_email, $guest_phone);

        $file = 'rrze_tracking_csv';
        $csv_output = '';

        if ($aGuests){
            foreach ($aGuests as $row){
                $row = array_values($row);
                $row = implode(", ", $row);
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


    public function insertTracking(int $blogId, int $bookingId)
    {
        global $wpdb;

        $booking = Functions::getBooking($bookingId);
        if (!$booking) {
            return;
        }

        $start = date('Y-m-d H:i:s', get_post_meta($bookingId, 'rrze-rsvp-booking-start', true));
        $end = date('Y-m-d H:i:s', get_post_meta($bookingId, 'rrze-rsvp-booking-end', true));

        $fields = [
            'blog_id' => $blogId,
            'start' => $start,
            'end' => $end,
            'room_post_id' => (int)$booking['room'],
            'room_name' => $booking['room_name'],
            'room_street' => $booking['room_street'],
            'room_zip' => (int)$booking['room_zip'],
            'room_city' => $booking['room_city'],
            'seat_name' => $booking['seat_name'],
            'hash_seat_name' => Functions::crypt(strtolower($booking['seat_name'])),
            'guest_firstname' => $booking['guest_firstname'],
            'hash_guest_firstname' => Functions::crypt(strtolower($booking['guest_firstname'])),
            'guest_lastname' => $booking['guest_lastname'],
            'hash_guest_lastname' => Functions::crypt(strtolower($booking['guest_lastname'])),
            'guest_email' => $booking['guest_email'],
            'hash_guest_email' => Functions::crypt(strtolower($booking['guest_email'])),
            'guest_phone' => $booking['guest_phone'],
            'hash_guest_phone' => Functions::crypt($booking['guest_phone']),
        ];

        $cnt = $wpdb->insert(
            $this->dbTable,
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
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
            ]
        );

        // echo "<script>console.log('insertTracking fields= " . json_encode($fields) . "' );</script>";
        // echo "<script>console.log('insertTracking cnt= " . ($cnt?$cnt:'FALSE') . "' );</script>";
        // exit(var_dump( $wpdb->last_query));
    }

    public static function getUsersInRoomAtDate(string $searchdate, int $delta, string $guest_firstname, string $guest_lastname, string $guest_email = '', string $guest_phone = ''): array
    {
        global $wpdb;

        $dbTrackingTable = Tracking::getDbTableName();

        if (!$guest_email && !$guest_firstname && !$guest_lastname){
            // we have nothing to search for
            return [];
        }

        if (!Functions::validateDate($searchdate)){
            // is not 'YYYY-MM-DD'
            return [];
        }

        //  "Identifikationsmerkmalen für eine Person (Name, E-Mail und oder Telefon)" see https://github.com/RRZE-Webteam/rrze-rsvp/issues/89
        $hash_guest_firstname = Functions::crypt(strtolower($guest_firstname));
        $hash_guest_lastname = Functions::crypt(strtolower($guest_lastname));
        $hash_guest_email = Functions::crypt(strtolower($guest_email));
        $hash_guest_phone = Functions::crypt($guest_phone);

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

        $rows = $wpdb->get_results( 
            $wpdb->prepare("SELECT surrounds.start, surrounds.end, surrounds.room_name, surrounds.room_street, surrounds.room_zip, surrounds.room_city, surrounds.guest_email, surrounds.guest_phone, surrounds.guest_firstname, surrounds.guest_lastname 
            FROM {$dbTrackingTable} AS surrounds 
            WHERE (DATE(surrounds.start) BETWEEN DATE_SUB(%s, INTERVAL %d DAY) AND DATE_ADD(%s, INTERVAL %d DAY)) AND (DATE(surrounds.end) BETWEEN DATE_SUB(%s, INTERVAL %d DAY) AND DATE_ADD(%s, INTERVAL %d DAY)) AND 
            surrounds.room_post_id IN 
            (SELECT needle.room_post_id FROM {$dbTrackingTable} AS needle WHERE 
            (DATE(needle.start) BETWEEN DATE_SUB(%s, INTERVAL %d DAY) AND DATE_ADD(%s, INTERVAL %d DAY)) AND 
            (DATE(needle.end) BETWEEN DATE_SUB(%s, INTERVAL %d DAY) AND DATE_ADD(%s, INTERVAL %d DAY)) AND 
            needle.hash_guest_firstname = %s AND needle.hash_guest_lastname = %s AND
            ((needle.hash_guest_email = %s) OR (needle.hash_guest_phone = %s))) 
            ORDER BY surrounds.start, surrounds.guest_lastname", $prepare_vals), ARRAY_A);

        // simpelst solution but a question of user's file rights: 
        // select ... INTO OUTFILE '$path_to_file' FIELDS TERMINATED BY ',' LINES TERMINATED BY ';' from ...

        return $rows;
    }



    protected function createTrackingTable()
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS " . $this->dbTable . " (
            id bigint(20) UNSIGNED NOT NULL auto_increment,
            blog_id bigint(20) NOT NULL,
            ts_updated timestamp DEFAULT CURRENT_TIMESTAMP,
            ts_inserted timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            start datetime NOT NULL,
            end datetime NOT NULL,
            room_post_id bigint(20) NOT NULL,
            room_name text NOT NULL,
            room_street text NOT NULL, 
            room_zip smallint(5) NOT NULL,
            room_city text NOT NULL, 
            seat_name text NOT NULL, 
            hash_seat_name char(64) NOT NULL,
            guest_firstname text NOT NULL, 
            guest_lastname text NOT NULL, 
            hash_guest_firstname char(64) NOT NULL,
            hash_guest_lastname char(64) NOT NULL,
            guest_email text NOT NULL, 
            hash_guest_email char(64) NOT NULL,
            guest_phone text NOT NULL, 
            hash_guest_phone char(64) NOT NULL,
            PRIMARY KEY  (id),
            KEY k_blog_id (blog_id)
            ) $charsetCollate;";

            // reason for all those hashes is that you cannot use TEXT (but CHAR / VARCHAR) for any kind of index resp KEY which improves performance & data integrity big times :-D 
            // ,UNIQUE KEY uk_guest_room_time (start,end,room_post_id,hash_seat_name,hash_guest_firstname,hash_guest_lastname,hash_guest_email,hash_guest_phone)            

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $aRet = dbDelta($sql);
        // echo "<script>console.log('dbDelta returns " . json_encode($aRet) . "' );</script>";
    }


    public static function getDbTableName()
    {
        global $wpdb;
        return $wpdb->base_prefix . static::DB_TABLE;
    }
}
