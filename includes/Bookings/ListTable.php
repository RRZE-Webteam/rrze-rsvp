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
        $columns = $this->get_columns();
        $hidden = [];
        $sortable = $this->get_sortable_columns();
		$this->_column_headers = [$columns, $hidden, $sortable];
				
		$perPage = $this->get_items_per_page('rrze_rsvp_bookings_per_page', 10);
		$currentPage = $this->get_pagenum();
		$offset = ($currentPage - 1) * $perPage;
		$args = [
			'post_type' => CPT::getBookingName(),
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
					'value' => 'canceled',
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

			$bookingDate = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($post->post_date));

			$categories = get_the_terms($post->ID, CPT::getTaxonomyServiceName());
			$category = $categories[0]->name;

			$prepItems[$post->ID]['id'] = $post->ID;
			$prepItems[$post->ID]['status'] = get_post_meta($post->ID, 'rrze_rsvp_status', true);
			$prepItems[$post->ID]['date'] = $date;
			$prepItems[$post->ID]['category'] = $category;
			$prepItems[$post->ID]['date_raw'] = get_post_meta($post->ID, 'rrze_rsvp_start', true);
			$prepItems[$post->ID]['booking_date'] = $bookingDate;
			$prepItems[$post->ID]['time'] = $time;

			$prepItems[$post->ID]['field_seat'] = get_post_meta($post->ID, 'rrze_rsvp_seat', true);

			$prepItems[$post->ID]['field_name'] = get_post_meta($post->ID, 'rrze_rsvp_user_name', true);
			$prepItems[$post->ID]['field_email'] = get_post_meta($post->ID, 'rrze_rsvp_user_email', true);
			$prepItems[$post->ID]['field_phone'] = get_post_meta($post->ID, 'rrze_rsvp_user_phone', true);

			$prepItems[$post->ID]['actions'] = "";
		}

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
		$nonceAction = wp_create_nonce('action');

		if (substr($column_name, 0, 6) == 'field_' && strlen($item[$column_name]) > 40) {
			return mb_substr($item[$column_name], 0, 30) . '... <a href="#" class="rrze-rsvp-show" data-show="' . nl2br($item[$column_name]) . '">' . __('Show more', 'rrze-rsvp') . '</a>';
		}

		switch ($column_name) {
			case 'actions':
				$bookingDate = '<span class="booking_date">' . __('Booked on', 'rrze-rsvp') . ' ' . $item['booking_date'] . '</span>';
				if ($this->archive) {
					$start = new Carbon($item['date_raw']);
					if ($item['status'] == 'canceled' && $start->endOfDay()->gt(new Carbon('now'))) {
						$canceledButton = '<button class="button rrzs-rsvp-cancel" disabled>' . __('Canceled', 'rrze-rsvp') . '</button>';
						$restoreButton = sprintf(
							'<a href="admin.php?page=%1$s&action=restore&id=%2$d&_wpnonce=%3$s" class="button">%4$s</a>', 
							plugin()->getSlug(),
							$item['id'],
							$nonceAction,
							__('Restore', 'rrze-rsvp')
						);
						$button = $canceledButton . $restoreButton;
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
						$button = sprintf(
							'<a href="admin.php?page=%1$s&action=delete&id=%2$d&_wpnonce=%3$s" class="delete">%4$s</a>', 
							plugin()->getSlug(),
							$item['id'],
							$nonceAction,
							__('Delete', 'rrze-rsvp')
						);						
					}
					return $button . $bookingDate;
				} else {
					$cancelButton = sprintf(
						'<a href="admin.php?page=%1$s&action=delete&id=%2$d&_wpnonce=%3$s" class="button rrze-rsvp-cancel" data-id="%2$d">%4$s</a>', 
						plugin()->getSlug(),
						$item['id'],
						$nonceAction,
						__('Cancel', 'rrze-rsvp')
					);					
					if ($item['status'] == 'confirmed') {						
						$confirmButton = "<button class='button button-primary rrze-rsvp-confirmed' disabled>" . __('Confirmed', 'rrze-rsvp') . "</button>";
					} else {
						$confirmButton = sprintf(
							'<a href="admin.php?page=%1$s&action=confirm&id=%2$d&_wpnonce=%3$s" class="button button-primary rrze-rsvp-confirm" data-id="%2$d">%4$s</a>', 
							plugin()->getSlug(),
							$item['id'],
							$nonceAction,
							__('Confirm', 'rrze-rsvp')
						);						
					}
					return $cancelButton . $confirmButton . $bookingDate;
				}
			default:
				return ! empty($item[$column_name]) ? $item[$column_name] : '&mdash;';
		}
	}

	public function get_table_classes()
	{
		return ['rrze-rsvp-bookings', 'widefat', 'fixed', 'striped'];
	}
}
