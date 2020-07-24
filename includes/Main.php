<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use RRZE\RSVP\Bookings\Main as Bookings;
use RRZE\RSVP\Services\Main as Services;
use RRZE\RSVP\Exceptions\Main as Exceptions;

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

		if (defined('WP_DEBUG') && WP_DEBUG) {
			add_action('admin_init', function() {
				$this->test();
			});
		}
	}

	public function adminEnqueueScripts($hook)
	{
		if (strpos($hook, 'rrze-rsvp') === false) {
			return;
		}

		wp_enqueue_script('wp-color-picker');
		wp_enqueue_script(
			'rrze-rsvp-admin',
			plugins_url('assets/js/admin.js', plugin()->getBasename()),
			[],
			plugin()->getVersion()
		);

		wp_enqueue_style('wp-color-picker');
		wp_enqueue_style(
			'rrze-rsvp-admin',
			plugins_url('assets/css/admin.css', plugin()->getBasename()),
			[],
			plugin()->getVersion()
		);
	}

	private function test()
	{
		if (!post_type_exists(CPT::getCptBookingName())) {
			return;
		}

		$status = [
			'confirmed' => 'confirmed',
			'canceled' => 'canceled',
			'notconfirmed' => 'not confirmed'
		];

		$users = [
			'john' => [
				'user_id' => 'john',
				'user_name' => 'John Mustermann',
				'user_email' => 'john@cms.wordpress.localhost',
				'user_phone' => '0873 376461'
			],
			'susanne' => [
				'user_id' => 'susanne',
				'user_name' => 'Susanne Musterfrau',
				'user_email' => 'susanne@cms.wordpress.localhost',
				'user_phone' => '0373 576461'
			],
			'marc' => [
				'user_id' => 'marc',
				'user_name' => 'Marc Mustermann',
				'user_email' => 'marc@cms.wordpress.localhost',
				'user_phone' => '0656 576561'
			],
			'donald' => [
				'user_id' => 'donald',
				'user_name' => 'Donald Mustermann',
				'user_email' => 'donald@cms.wordpress.localhost',
				'user_phone' => '0955 573761'
			],
			'abigail' => [
				'user_id' => 'abigail',
				'user_name' => 'Abigail Musterfrau',
				'user_email' => 'abigail@cms.wordpress.localhost',
				'user_phone' => '0876 577762'
			],
			'candy' => [
				'user_id' => 'candy',
				'user_name' => 'Candy Musterfrau',
				'user_email' => 'candy@cms.wordpress.localhost',
				'user_phone' => '0996 976567'
			]
		];

		$terms = [
			'lesesaal-1' => [
				'title' => 'Lesesaal 1',
				'description' => '26 Sitzplätze'
			],
			'lesesaal-2' => [
				'title' => 'Lesesaal 2',
				'description' => '36 Sitzplätze'
			],
			'lesesaal-3' => [
				'title' => 'Lesesaal 3',
				'description' => '31 Sitzplätze'
			]			
		];

		$args = [
			'post_type' => 'rrze_rsvp_booking',
			'post_status' => 'publish',
			'nopaging' => true
		];

		$query = new \WP_Query();
		$posts = $query->query($args);
		$count = count($posts);
		$maxCount = 27;

		if ($count >= $maxCount) {
			return;
		}
		$count = $maxCount - $count;

		$tax = [];
		foreach ($terms as $slug => $term) {
			wp_insert_term(
				$term['title'],
				CPT::getTaxonomyServiceName(),
				[
					'description' => $term['description'],
					'slug' => $slug
				]
			);
			
			$t = get_term_by('slug', $slug, CPT::getTaxonomyServiceName());
			$tax[$t->term_id] = $slug;
		}

		for ($i = 1; $i <= $count; $i++) {
			$startTime = strtotime('today', current_time('timestamp')) + rand(1, 7) * DAY_IN_SECONDS + rand(8, 14) * HOUR_IN_SECONDS;
			$endTime = $startTime + 4 * HOUR_IN_SECONDS;

			$user = $users[array_rand($users)];

			$booking = [
				'post_type' => CPT::getCptBookingName(),
				'post_title' => bin2hex(random_bytes(8)),
				'post_content' => '',
				'post_status' => 'publish',
				'post_author' => 1,
				'tax_input' => [
					'rrze_rsvp_service' => [array_rand($tax)]
				],
				'meta_input' => [
					'rrze_rsvp_id' => bin2hex(random_bytes(8)),
					'rrze_rsvp_start' => date('d-m-Y H:i:s', $startTime),
					'rrze_rsvp_end' => date('d-m-Y H:i:s', $endTime),
					'rrze_rsvp_status' => array_rand($status),
					'rrze_rsvp_user_id' => $user['user_id'],
					'rrze_rsvp_user_name' => $user['user_name'],
					'rrze_rsvp_user_email' => $user['user_email'],
					'rrze_rsvp_user_phone' => $user['user_phone']
				]
			];

			wp_insert_post($booking);
		}
	}
}
