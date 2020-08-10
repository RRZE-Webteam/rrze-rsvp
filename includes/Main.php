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
		$settings = new Settings($this->pluginFile);
		$settings->onLoaded();

		// Posttypes 
		$cpt = new CPT($this->pluginFile, $settings);
		$cpt->onLoaded();

		$actions = new Actions;
		$actions->onLoaded();

		$shortcodes = new Shortcodes($this->pluginFile, $settings);
		$shortcodes->onLoaded();

		$printing = new Printing;
		$printing->onLoaded();

		$schedule = new Schedule;
		$schedule->onLoaded();

//        $tools = new Tools($this->pluginFile);
//        $tools->onLoaded();

		add_action('admin_enqueue_scripts', [$this, 'adminEnqueueScripts']);

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
			'text_confirmed' => __('Confirmed', 'rrze-rsvp'),
			'ajaxurl' => admin_url('admin-ajax.php')
		));
	}
}
