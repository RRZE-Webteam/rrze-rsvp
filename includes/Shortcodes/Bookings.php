<?php

namespace RRZE\RSVP\Shortcodes;

use function RRZE\RSVP\Config\getShortcodeSettings;
use function RRZE\RSVP\Config\getShortcodeDefaults;



defined('ABSPATH') || exit;

/**
 * Define Shortcode Bookings
 */
class Bookings extends Shortcodes {
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
   
   

    public function shortcodeBooking($shortcode_atts) {
        $days = (int)$shortcode_atts['days'];
        $location = sanitize_title($shortcode_atts['location']);
        if ($location != '' && $location != 'select') {
            $term = get_term_by('slug', $location, 'rsvp_locations');
            $location = ($term ? $term->term_id : false);
        }
        if ($location === false) {
            return __('Location specified in shortcode does not exist.','rrze-rsvp');
        }
        $output = '';
        $output .= '<div class="rrze-rsvp">';
        $output .= '<form action="#" id="rsvp_by_location">'
            . '<div id="loading"><i class="fa fa-refresh fa-spin fa-4x"></i></div>';

        if ($location == 'select') {
            $dropdown = wp_dropdown_categories([
                'taxonomy' => 'rsvp_locations',
                'hide_empty' => true,
                'show_option_none' => __('-- Please select --', 'rrze-rsvp'),
                'orderby' => 'name',
                'hierarchical' => true,
                'id' => 'rsvp_location',
                'echo' => false,
            ]);
            $output .= '<div class="form-group">'
                . '<label for="rsvp_location" class="h3">Location/Room</label>'
                . $dropdown . '</div>';
        } else {
            $output .= '<div><input type="hidden" value="'.$location.'" id="rsvp_location"></div>';
        }

        $output .= '<div class="rsvp-datetime-container form-group clearfix"><legend>' . __('Select date and time', 'rrze-rsvp') . '</legend>'
            . '<div class="rsvp-date-container">';
        $dateComponents = getdate();
        $month = $dateComponents['mon'];
        $year = $dateComponents['year'];
        $start = date_create();
        $end = date_create();
        date_modify($end, '+'.$days.' days');
        $output .= $this->buildCalendar($month,$year, $start, $end, $location);
//        $output .= $this->buildDateBoxes($days);
        $output .= '</div>'; //.rsvp-date-container

        $output .= '<div class="rsvp-time-container">'
            . '<h4>' . __('Available time slots:', 'rrze-rsvp') . '</h4>'
            . '<div class="rsvp-time-select error">'.__('Please select a date.', 'rrze-rsvp').'</div>'
            . '</div>'; //.rsvp-time-container

        $output .= '</div>'; //.rsvp-datetime-container

        $output .= '<div class="rsvp-service-container"></div>';

        $output .= '<div class="form-group"><label for="rrze_rsvp_user_phone">' . __('Phone Number', 'rrze-rsvp') . ' *</label>'
            . '<input type="tel" name="rrze_rsvp_user_phone" id="rrze_rsvp_user_phone" required aria-required="true">';

        $output .= '<button type="submit" class="btn btn-primary">Submit</button>
                </form>
            </div>';

        wp_enqueue_style('rrze-rsvp-shortcode');
        wp_enqueue_script('rrze-rsvp-shortcode');

        return $output;
    }
    
   
}

