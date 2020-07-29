<?php

namespace RRZE\RSVP\Shortcodes;

use function RRZE\RSVP\Config\getShortcodeSettings;
use function RRZE\RSVP\Config\getShortcodeDefaults;



defined('ABSPATH') || exit;

/**
 * Define Shortcode Bookings
 */
class Availability extends Shortcodes {
    protected $pluginFile;
    private $settings = '';
    private $shortcodesettings = '';
    
    public function __construct($pluginFile, $settings) {
	$this->pluginFile = $pluginFile;
	$this->settings = $settings;	
	$this->shortcodesettings = getShortcodeSettings();
    }


    public function onLoaded() {	

	add_shortcode('rsvp-booking', [$this, 'shortcodeOutput'], 10, 2);

    }
   
  
   public function shortcodeAvailability($shortcode_atts) {
        $output = '';
        $days = sanitize_text_field($shortcode_atts['days']); // kann today, tomorrow oder eine Zahl sein (kommende X Tage)
        $seats = explode(',', sanitize_text_field($shortcode_atts['seat']));
        $seats = array_map('trim', $seats);
        $seats = array_map('sanitize_title', $seats);
        $services = explode(',', sanitize_text_field($shortcode_atts['service']));
        $services = array_map('trim', $services);
        $services = array_map('sanitize_title', $services);
//var_dump($seats, $services);
        $post = get_posts();

        wp_enqueue_style('rrze-rsvp-shortcode');
        //wp_enqueue_script('rrze-rsvp-shortcode');

        return $output;
    }

   
}

