<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use RRZE\RSVP\CPT\CPT;
use RRZE\RSVP\Shortcodes\Shortcodes;
use RRZE\RSVP\Printing\Printing;

/**
 * [Main description]
 */
class Main
{

	protected $pluginFile;
	private $settings = '';


	/**
	 * [__construct description]
	 */
	public function __construct($pluginFile)
	{
		$this->pluginFile = $pluginFile;
	}

	public function onLoaded()
	{
		// Settings
		$settings = new Settings($this->pluginFile);
		$settings->onLoaded();

		// IdM
        $idm = new IdM;
        $idm->onLoaded();		

		// Posttypes 
		$cpt = new CPT;
		$cpt->onLoaded();

		// CMB2
		$metaboxes = new Metaboxes;
		$metaboxes->onLoaded();

		// Tracking
		$tracking = new Tracking;
		$tracking->onLoaded();

<<<<<<< Updated upstream
		// LDAP
		$ldap = new LDAP;
		$ldap->onLoaded();
=======
		// LDAP 
		// $ldap = new LDAP;
		// $ldap->onLoaded();
>>>>>>> Stashed changes

		$shortcodes = new Shortcodes($this->pluginFile, $settings);
		$shortcodes->onLoaded();

		$printing = new Printing;
		$printing->onLoaded();

		$schedule = new Schedule;
		$schedule->onLoaded();

		$occupancy = new Occupancy;
		$occupancy->onLoaded();

		$tools = new Tools;
		$tools->onLoaded();

		$virtualPage = new VirtualPage(__('Booking', 'rrze-rsvp'), 'rsvp-booking');
		$virtualPage->onLoaded();

		$actions = new Actions;
		$actions->onLoaded();

		add_action('admin_enqueue_scripts', [$this, 'adminEnqueueScripts']);
		add_action('wp_enqueue_scripts', [$this, 'wpEnqueueScripts']);

		add_action('rest_api_init', function () {
			//$api = new API;
			//$api->register_routes();
		});
	}

	public function adminEnqueueScripts()
	{
		global $post_type;

		wp_enqueue_style(
			'rrze-rsvp-admin-menu',
			plugins_url('assets/css/rrze-rsvp-admin-menu.css', plugin()->getBasename()),
			[],
			plugin()->getVersion()
		);

		if (!in_array($post_type, array_keys(Capabilities::getCurrentCptArgs()))) {
			return;
		}

		wp_enqueue_style(
			'rrze-rsvp-admin',
			plugins_url('assets/css/rrze-rsvp-admin.css', plugin()->getBasename()),
			[],
			plugin()->getVersion()
		);

		wp_enqueue_script(
			'rrze-rsvp-admin',
			plugins_url('assets/js/rrze-rsvp-admin.js', plugin()->getBasename()),
			['jquery'],
			plugin()->getVersion()
		);

		wp_localize_script('rrze-rsvp-admin', 'rrze_rsvp_admin', array(
			'dateformat' => get_option('date_format'),
			'text_cancel' => __('Do you want to cancel?', 'rrze-rsvp'),
			'text_cancelled' => _x('Cancelled', 'Booking', 'rrze-rsvp'),
			'text_confirmed' => _x('Confirmed', 'Booking', 'rrze-rsvp'),
			'ajaxurl' => admin_url('admin-ajax.php'),
			// Strings für CPT Booking Backend
			'alert_no_seat_date' => __('Please select a seat and a date first.', 'rrze-rsvp')
		));

		if ($post_type == 'booking') {
			wp_dequeue_script('autosave');
		} elseif ($post_type == 'room') {
			wp_dequeue_script('autosave');
		} elseif ($post_type == 'seat') {
			wp_dequeue_script('autosave');
			wp_enqueue_script(
				'rrze-rsvp-seat',
				plugins_url('assets/js/rrze-rsvp-seat.js', plugin()->getBasename()),
				['jquery'],
				plugin()->getVersion()
			);

			wp_localize_script('rrze-rsvp-seat', 'button_label', __('Create Seats', 'rrze-rsvp'));
		}
	}

	public function wpEnqueueScripts()
	{
		wp_register_style(
			'rrze-rsvp-shortcode',
			plugins_url('assets/css/rrze-rsvp.css', plugin()->getBasename()),
			[],
			plugin()->getVersion()
		);
		wp_register_script(
			'rrze-rsvp-shortcode',
			plugins_url('assets/js/shortcode.js', plugin()->getBasename()),
			['jquery'],
			plugin()->getVersion()
		);
	}
}
