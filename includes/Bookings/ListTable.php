<?php

namespace RRZE\RSVP\Bookings;

defined('ABSPATH') || exit;

use RRZE\RSVP\CPT;
use Carbon\Carbon;

use function RRZE\RSVP\plugin;

use WP_List_Table;
use WP_Query;

class ListTable extends WP_List_Table
{
	protected $archive = false;

	public function __construct($archive)
	{
		parent::__construct([
			'singular' => 'booking',
			'plural' => 'bookings',
			'ajax' => false
		]);
		$this->archive = $archive;
	}

	public function get_columns()
	{
		$columns = [
			'date' => __('Date', 'rrze-rsvp'),
			'time' => __('Time', 'rrze-rsvp'),
			'category' => __('Service', 'rrze-rsvp'),
			'field_seat' => __('Seat', 'rrze-rsvp'),
			'field_name' => __('Name', 'rrze-rsvp'),
			'field_email' => __('Email', 'rrze-rsvp'),
			'field_phone' => __('Phone', 'rrze-rsvp')
		];
		$columns['actions'] = '';
		return $columns;
	}

	public function prepare_items()
	{
		$perPage = $this->get_items_per_page('rrze_rsvp_bookings_per_page', 10);
		$currentPage = $this->get_pagenum();
		$offset = ($currentPage - 1) * $perPage;
		$args = [
			'post_type' => CPT::getCptBookingName(),
			'post_status' => 'publish',
			'posts_per_page' => -1,
			'offset' => -1,
			'meta_key' => 'rrze_rsvp_start',
			'orderby' => 'meta_value',
			'order' => $this->archive ? 'DESC' : 'ASC',
			'meta_query' => [
				'relation' => $this->archive ? 'OR' : 'AND',
				'status_clause' => [
					'key' => 'rrze_rsvp_status',
					'value' => 'notconfirmed',
					'compare' => $this->archive ? '=' : '!='
				],
				'status_start' => [
					'key' => 'rrze_rsvp_start',
					'value' => current_time('mysql'),
					'compare' => $this->archive ? '<' : '>='
				]
			]
		];

		$query = new WP_Query();
		$posts = $query->query($args);

		$prepItems = [];

		foreach ($posts as $post) {
			$start = new Carbon(get_post_meta($post->ID, 'rrze_rsvp_start', true));
			$end = new Carbon(get_post_meta($post->ID, 'rrze_rsvp_end', true));
			$date = date_i18n(get_option('date_format'), $start->timestamp);
			$time = date_i18n(get_option('time_format'), $start->timestamp) . " - " . date_i18n(get_option('time_format'), $end->timestamp);

			$booking_date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($post->post_date));

			$categories = get_the_terms($post->ID, CPT::getTaxonomyServiceName());
			$category = $categories[0]->name;

			$prepItems[$post->ID]['id'] = $post->ID;
			$prepItems[$post->ID]['status'] = get_post_meta($post->ID, 'rrze_rsvp_status', true);
			$prepItems[$post->ID]['date'] = $date;
			$prepItems[$post->ID]['category'] = $category;
			$prepItems[$post->ID]['date_raw'] = get_post_meta($post->ID, 'rrze_rsvp_start', true);
			$prepItems[$post->ID]['booking_date'] = $booking_date;
			$prepItems[$post->ID]['time'] = $time;

			$prepItems[$post->ID]['field_seat'] = get_post_meta($post->ID, 'rrze_rsvp_seat', true);

			$prepItems[$post->ID]['field_name'] = get_post_meta($post->ID, 'rrze_rsvp_user_name', true);
			$prepItems[$post->ID]['field_email'] = get_post_meta($post->ID, 'rrze_rsvp_user_email', true);
			$prepItems[$post->ID]['field_phone'] = get_post_meta($post->ID, 'rrze_rsvp_user_phone', true);

			$prepItems[$post->ID]['actions'] = "";
		}

		$columns = $this->get_columns();
		$hidden = [];
		$sortable = [];
		$this->_column_headers = [$columns, $hidden, $sortable];

		$totalItems = count($prepItems);
		$this->items = array_slice($prepItems, $offset, $perPage);

		$this->set_pagination_args(
			[
				'total_items' => $totalItems,
				'per_page' => $perPage,
				'total_pages' => ceil($totalItems / $perPage)
			]
		);
	}

	public function column_default($item, $column_name)
	{
		$nonce_action = wp_create_nonce('action');

		if (substr($column_name, 0, 6) == 'field_' && strlen($item[$column_name]) > 40) {
			return mb_substr($item[$column_name], 0, 30) . '... <a href="#" class="rrze-rsvp-show" data-show="' . nl2br($item[$column_name]) . '">' . __('Show more', 'rrze-rsvp') . '</a>';
		}

		switch ($column_name) {
			case 'actions':
				$booking_date = '<span class="booking_date">' . __('Booked on', 'rrze-rsvp') . ' ' . $item['booking_date'] . '</span>';
				if ($this->archive) {
					$start = new Carbon($item['date_raw']);
					if ($item['status'] == 'canceled' && $start->endOfDay()->gt(new Carbon('now'))) {
						$button = "<button class='button rrzs-rsvp-delete' disabled>" . __('Canceled', 'rrze-rsvp') . "</button>
						<a href='admin.php?page=" . plugin()->getSlug() . "&action=rec&id=" . $item['id'] . "&_wpnonce=" . $nonce_action . "' class='button'>" . __('Restore', 'rrze-rsvp') . "</a>";
					} else {
						switch ($item['status']) {
							case 'canceled':
								$button = __('Canceled', 'rrze-rsvp');
								break;
							case 'notconfirmed':
								$button = __('Not confirmed', 'rrze-rsvp');
								break;
							case 'confirmed':
								$button = __('Confirmed', 'rrze-rsvp');
								break;
						}
						$button .= "<a href='admin.php?page=" . plugin()->getSlug() . "&action=del_permanent&id=" . $item['id'] . "&_wpnonce=" . $nonce_action . "' class='delete'>" . __('Delete', 'rrze-rsvp') . "</a>";
					}
					return $button . $booking_date;
				} else {
					$deleteButton = "<a href='admin.php?page=" . plugin()->getSlug() . "&action=del&id=" . $item['id'] . "&_wpnonce=" . $nonce_action . "' class='button rrze-rsvp-delete' data-id='" . $item['id'] . "' data-email='" . $item['field_email'] . "'>" . _x('Cancel', 'Cancel Booking', 'rrze-rsvp') . "</a>";
					if ($item['status'] == 'confirmed') {
						$actionButton = "<button class='button button-primary rrze-rsvp-confirmed' disabled>" . __('Confirmed', 'rrze-rsvp') . "</button>";
					} else {
						$actionButton = "<a href='admin.php?page=" . plugin()->getSlug() . "&action=acc&id=" . $item['id'] . "&_wpnonce=" . $nonce_action . "' class='button button-primary rrze-rsvp-accept' data-id='" . $item['id'] . "' data-email='" . $item['field_email'] . "'>" . __('Confirm', 'rrze-rsvp') . "</a>";
					}
					return $deleteButton . $actionButton . $booking_date;
				}
			default:
				return ! empty($item[$column_name]) ? $item[$column_name] : '&mdash;';
		}
	}

	public function get_table_classes()
	{
		return array('widefat', 'fixed', 'striped');
	}
}
