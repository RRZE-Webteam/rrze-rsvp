<?php

namespace RRZE\RSVP\CPT;

defined('ABSPATH') || exit;

use RRZE\RSVP\Main;
use RRZE\RSVP\CPT\Bookings;
use RRZE\RSVP\CPT\Rooms;
use RRZE\RSVP\CPT\Seats;

/**
 * Laden und definieren der Posttypes
 */
class CPT extends Main {
    protected $pluginFile;
    protected $settings;
    
    public function __construct($pluginFile, $settings) {
        $this->pluginFile = $pluginFile;
	$this->settings = $settings;
    }

    public function onLoaded() {
        $locations = new Rooms($this->pluginFile, $this->settings);
        $locations->onLoaded();

        $seats = new Seats($this->pluginFile, $this->settings);
        $seats->onLoaded();

        $bookings = new Bookings($this->pluginFile, $this->settings);
        $bookings->onLoaded();
	
	
    }
}
