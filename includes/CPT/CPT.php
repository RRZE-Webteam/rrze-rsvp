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

	 add_action( 'admin_menu' , array( $this, 'cpt_admin_submenu' )); 

    }
    public function cpt_admin_submenu(){
	$cpts = array('room', 'seat');

	foreach ($cpts as $cpt) {
	    $cpt_obj = get_post_type_object( $cpt );

	    add_submenu_page(
		'edit.php?post_type=booking',      // parent slug
		$cpt_obj->labels->name,            // page title
		$cpt_obj->labels->menu_name,       // menu title
		$cpt_obj->cap->edit_posts,         // capability
		'edit.php?post_type=' . $cpt       // menu slug
	    );
	}
    }
    
}
