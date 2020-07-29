<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use RRZE\RSVP\CPT\CPT;

// use RRZE\RSVP\Exceptions\Main as Exceptions;
use RRZE\RSVP\Settings;
use RRZE\RSVP\EmailSettings\EmailSettings;
use RRZE\RSVP\Shortcodes\Shortcodes;

/**
 * [Main description]
 */
class Main{
    
    protected $pluginFile;
    private $settings = '';


	/**
	 * [__construct description]
	 */
	public function __construct($pluginFile)	{

	     $this->pluginFile = $pluginFile;
	    add_action('admin_enqueue_scripts', [$this, 'adminEnqueueScripts']);
		add_action('rest_api_init', function () {
			//$api = new API;
			//$api->register_routes();
		});
	}

	public function onLoaded() {
	    
	    $settings = new Settings($this->pluginFile);
	    $settings->onLoaded();
	
	    	// Posttypes 
	    $cpt = new CPT($this->pluginFile, $settings);
	    $cpt->onLoaded();
	
	// Old:
	//	$cpt = new CPT;
	//	$cpt->onLoaded();

		$actions = new Actions;
		$actions->onLoaded();

/*
 * Erstmal noch nicht, werden umbenannt in Blocking Time:
 
		$exceptions = new Exceptions;
		$exceptions->onLoaded();

	*/

		$shortcodes = new Shortcodes($this->pluginFile, $settings);
		$shortcodes->onLoaded();
	
	}

	public function adminEnqueueScripts($hook)
	{
		if (strpos($hook, 'rrze-rsvp') === false) {
			return;
		}

		// wozu?
		wp_enqueue_script('jquery-ui-core');
		// wird?
		
		wp_enqueue_script('jquery-ui-datepicker');
		// dies?
		
		wp_enqueue_script('wp-color-picker');
		// gebraucht??!?!?!=!?=!?!!!
		    
		wp_enqueue_script(
			'rrze-rsvp-admin',
			plugins_url('assets/js/admin.js', plugin()->getBasename()),
			['jquery'],
			plugin()->getVersion()
		);
		$nonce = wp_create_nonce('rrze-rsvp-ajax-nonce');
		wp_localize_script('rrze-rsvp-admin', 'rrze_rsvp_admin', array(
			'dateformat' => get_option('date_format'),
			'text_cancel' => __('Do you want to cancel?', 'rrze-rsvp'),
			'text_cancelled' => __('Canceled', 'rrze-rsvp'),
			'text_confirmed' => __('Confirmed', 'rrze-rsvp'),
			'ajaxurl' => admin_url('admin-ajax.php')
		));

		wp_enqueue_style(
			'jquery-ui-css',
			plugins_url('assets/css/jquery-ui-min.css', plugin()->getBasename())
		);
		wp_enqueue_style('jquery-ui-datepicker');
		wp_enqueue_style('wp-color-picker');
		wp_enqueue_style(
			'rrze-rsvp-admin',
			plugins_url('assets/css/admin.css', plugin()->getBasename()),
			[],
			plugin()->getVersion()
		);
	}

}
