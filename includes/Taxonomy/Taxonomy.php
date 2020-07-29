<?php

namespace RRZE\RSVP\Taxonomy;

defined('ABSPATH') || exit;

use RRZE\RSVP\Main;
use RRZE\RSVP\Taxonomy\Bookings;
use RRZE\RSVP\Taxonomy\Rooms;
use RRZE\RSVP\Taxonomy\Seats;

/**
 * Laden und definieren der Posttypes
 */
class Taxonomy extends Main {
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
