<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use RRZE\RSVP\Bookings\Main as Bookings;
use RRZE\RSVP\Services\Main as Services;
use RRZE\RSVP\Exceptions\Main as Exceptions;
use RRZE\RSVP\Seats\Main as Seats;
use RRZE\RSVP\Shortcodes\Shortcode as Shortcodes;

/**
 * [Main description]
 */
class Main
{
	/**
	 * [__construct description]
	 */
	public function __construct()
	{
		add_action('admin_enqueue_scripts', [$this, 'adminEnqueueScripts']);
		add_action('rest_api_init', function () {
			//$api = new API;
			//$api->register_routes();
		});
	}

	public function onLoaded()
	{
		$cpt = new CPT;
		$cpt->onLoaded();

		$bookings = new Bookings;
		$bookings->onLoaded();

		$services = new Services;
		$services->onLoaded();

		$exceptions = new Exceptions;
		$exceptions->onLoaded();

		$seats = new Seats;
		$seats->onLoaded();

		$shortcodes = new Shortcodes;
		$shortcodes->onLoaded();		
	}

	public function adminEnqueueScripts($hook)
	{
		if (strpos($hook, 'rrze-rsvp') === false) {
			return;
		}

		wp_enqueue_script('jquery-ui-core');
		wp_enqueue_script('jquery-ui-datepicker');
		wp_enqueue_script('wp-color-picker');
		wp_enqueue_script(
			'rrze-rsvp-admin',
			plugins_url('assets/js/admin-min.js', plugin()->getBasename()),
			['jquery'],
			plugin()->getVersion()
		);
		wp_localize_script('rrze-rsvp-admin', 'rrze_rsvp_admin', array(
			'dateformat' => get_option('date_format'),
			'text_delete' => __('Do you want to delete?', 'rrze-rsvp'),
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
