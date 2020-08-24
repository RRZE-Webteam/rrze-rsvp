<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

class Tracking
{
    const DB_TABLE = 'rrze_rsvp_tracking';

    const DB_VERSION = '1.0.0';

    const DB_VERSION_OPTION_NAME = 'rrze_rsvp_tracking_db_version';

    protected $dbTable;

    protected $dbVersion;

    protected $dbOptionName;

    public function __construct()
    {
        global $wpdb;
        $this->dbTable = $wpdb->base_prefix . static::DB_TABLE;
        $this->dbVersion = static::DB_VERSION;
        $this->dbOptionName = static::DB_VERSION_OPTION_NAME;
    }

    public function onLoaded()
    {
        $this->updateDbVersion();
        add_action('rrze-rsvp-checked-in', [$this, 'insertTracking'], 10, 2);
    }

    protected function insertTracking(int $blogId, int $bookingId)
    {
        global $wpdb;

        $booking = Functions::getBooking($bookingId);
        if (!$booking) {
            return;
        }


        $wpdb->insert(
            $this->dbTable,
            [
                'blog_id' => $blogId,
                'ts_start' => $booking['start'],
                'ts_end' => $booking['end'],
                'room_post_id' => $booking['room'],
                'room_name' => $booking['room_name'],
                'room_street' => $booking['room_street'],
                'room_zip' => $booking['room_zip'],
                'room_city' => $booking['room_city'],
                'seat_name' => $booking['seat_name'],
                'hash_seat_name' => Functions::crypt(strtolower($booking['seat_name'])),
                'guest_firstname' => $booking['guest_firstname'],
                'guest_lastname' => $booking['guest_lastname'],
                'hash_guest_lastname' => Functions::crypt(strtolower($booking['guest_lastname'])), // for search only
                'guest_email' => $booking['guest_email'],
                'hash_guest_email' => Functions::crypt(strtolower($booking['guest_email'])),
                'guest_phone' => $booking['guest_phone'],
                'hash_guest_phone' => Functions::crypt($booking['guest_phone']), // for search only
            ],
            [
                '%d',
                '%d',
                '%d',
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
            ]
        );
    }

    public function getUsersInRoomAtDate(string $searchdate, int $delta, string $guest_lastname, string $guest_email = '', string $guest_phone = ''): array
    {
        global $wpdb;

        if (!$guest_email && !$guest_name){
            // we have nothing to search for
            return false;
        }

        if (!Functions::validateDate($searchdate)){
            // is not 'YYY-MM-DD'
            return false;
        }

        //  "Identifikationsmerkmalen für eine Person (Name, E-Mail und oder Telefon)" see https://github.com/RRZE-Webteam/rrze-rsvp/issues/89
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
            $hash_guest_lastname,
            $hash_guest_email,
            $hash_guest_phone
        ];

        $results = $wpdb->get_results( 
                    $wpdb->prepare("SELECT surrounds.room_post_id, surrounds.room_name, surrounds.room_street, surrounds.room_zip, surrounds.room_city, surrounds.guest_email, surrounds.guest_phone, surrounds.guest_firstname, surrounds.guest_lastname FROM {$this->dbTable} AS surrounds WHERE surrounds.hash_room_name IN 
            (SELECT needle.hash_room_name FROM {$this->dbTable} AS needle WHERE 
            (needle.start BETWEEN DATE_SUB(%s  $searchdate, INTERVAL %d $delta DAY) AND DATE_ADD(%s  $searchdate, INTERVAL %d  $delta DAY)) AND 
            (needle.end BETWEEN DATE_SUB(%s $searchdate, INTERVAL %d $delta DAY) AND DATE_ADD(%s $searchdate, INTERVAL %d $delta DAY)) AND 
            needle.hash_guest_lastname = %s $hash_guest_lastname AND
            (needle.hash_guest_email = %s $hash_guest_email) OR (needle.hash_guest_phone = %s $hash_guest_phone))", $prepare_vals), ARRAY_A);

        if ($results){

            // 2DO separate Methode für CSV falls andere Formate gefordert werden & Layout-Trennung 


            
        }
    }


    protected function updateDbVersion()
    {
        if (get_site_option($this->dbOptionName, NULL) != $this->dbVersion) {
            $this->trackingInstall();
            update_site_option($this->dbOptionName, $this->dbVersion);
        }
    }

    protected function trackingInstall()
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS " . $this->dbTable . " (
            id bigint(20) UNSIGNED NOT NULL auto_increment,
            blog_id bigint(20) NOT NULL,
            ts_updated timestamp DEFAULT CURRENT_TIMESTAMP,
            ts_inserted timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            ts_start timestamp NOT NULL,
            ts_end timestamp NOT NULL,
            room_post_id bigint(20) NOT NULL,
            room_name text NOT NULL,
            room_street text NOT NULL, 
            room_zip smallint(5) NOT NULL,
            room_city text NOT NULL, 
            seat_name text NOT NULL, 
            hash_seat_name char(64) NOT NULL,
            guest_firstname text NOT NULL, 
            guest_lastname text NOT NULL, 
            hash_guest_name char(64) NOT NULL,
            guest_email text NOT NULL, 
            hash_guest_email char(64) NOT NULL,
            guest_phone text NOT NULL, 
            hash_guest_phone char(64) NOT NULL,
            PRIMARY KEY  (id),
            KEY k_blog_id (blog_id),
            UNIQUE KEY uk_guest_room_time (ts_start,ts_end,room_post_id,hash_seat_name,hash_guest_name,hash_guest_email,hash_guest_phone)
            ) $charsetCollate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        dbDelta($sql);

    }


    public static function getDbTableName()
    {
        global $wpdb;
        return $wpdb->base_prefix . static::DB_TABLE;
    }
}
