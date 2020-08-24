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

        add_action('rrze-rsvp-ckecked-in', [$this, 'trackingBookingCheckedIn'], 10, 2);
    }

    public function trackingBookingCheckedIn(int $blogId, int $bookingId)
    {
        $booking = Functions::getBooking($bookingId);
        if (!$booking) {
            return;
        }

        $data = [
            'date_start' => Functions::dateFormat($booking['start']),
            'time_start' => Functions::timeFormat($booking['start']),
            'date_end' => Functions::dateFormat($booking['end']),
            'time_end' => Functions::timeFormat($booking['end']),            
            'room_name' => $booking['room_name'],
            'room_street' => $booking['room_street'],
            'room_zip' => $booking['room_zip'],
            'room_city' => $booking['room_city'],
            'seat_name' => $booking['seat_name'],
            'customer_firstname' => $booking['guest_firstname'],
            'customer_lastname' => $booking['guest_lastname'],
            'customer_email' => $booking['guest_email'],
            'customer_phone' => $booking['guest_phone']
        ];

        $jsonData = json_encode($data);
        if (is_null($jsonData)) {
            return;
        }

        $this->insertData($blogId, $jsonData);
    }

    protected function insertData(int $blog_id, string $data)
    {
        global $wpdb;

        $wpdb->insert(
            $this->dbTable,
            [
                'date' => current_time('mysql'),
                'date_gmt' => current_time('mysql', true),
                'blog_id' => $blog_id,
                'data' => $data
            ],
            [
                '%s',
                '%s',
                '%d',
                '%s'
            ]
        );
    }

    protected function updateDbVersion()
    {
        if (get_site_option($this->dbOptionName, NULL) != $this->dbVersion) {
            $this->dbDelta();
            update_site_option($this->dbOptionName, $this->dbVersion);
        }
    }

    protected function dbDelta()
    {
        global $wpdb;

        $charsetCollate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS " . $this->dbTable . " (
            id bigint(20) unsigned NOT NULL auto_increment,
            date datetime NOT NULL default '0000-00-00 00:00:00',
            date_gmt datetime NOT NULL default '0000-00-00 00:00:00',
            blog_id bigint(20) NOT NULL,
            data longtext NOT NULL,
            PRIMARY KEY  (id),
            KEY type_date (date,id),
            KEY blog_id (blog_id)            
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
