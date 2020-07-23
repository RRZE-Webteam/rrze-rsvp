<?php

namespace RRZE\RSVP\Services;

defined('ABSPATH') || exit;

use RRZE\RSVP\CPT;
use RRZE\RSVP\Functions;

use WP_List_Table;
use WP_Term_Query;

class ListTable extends WP_List_Table
{
	public function __construct()
	{
		parent::__construct([
			'singular' => 'service',
			'plural' => 'services',
			'ajax' => false
		]);
	}

	public function get_columns()
	{
		$columns = [
			'service' => __('Service', 'rrze-rsvp'),
			'slug' => __('Slug', 'rrze-rsvp'),
			'description' => __('Description', 'rrze-rsvp')
		];
		$columns['actions'] = '';
		return $columns;
	}

	public function prepare_items()
	{
		$perPage = $this->get_items_per_page('rrze_rsvp_services_per_page', 10);
		$currentPage = $this->get_pagenum();
		$offset = ($currentPage - 1) * $perPage;

		$args = [
			'taxonomy' => CPT::getTaxonomyServiceName(),
			'hide_empty' => false
		];

		$query = new WP_Term_Query();
		$terms = $query->query($args);

		$prepItems = [];

		foreach ($terms as $term) {
			$prepItems[$term->term_id]['id'] = $term->term_id;
			$prepItems[$term->term_id]['slug'] = $term->slug;
			$prepItems[$term->term_id]['service'] = $term->name;
			$prepItems[$term->term_id]['description'] = $term->description;
			$prepItems[$term->term_id]['actions'] = "";
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
		if (substr($column_name, 0, 6) == 'field_' && strlen($item[$column_name]) > 40) {
			return mb_substr($item[$column_name], 0, 30) . '... <a href="#" class="rzze-rsvp-show" data-show="' . nl2br($item[$column_name]) . '">' . __('Show more', 'rrze-rsvp') . '</a>';
		}

		switch ($column_name) {
			case 'actions':
				$actionUrl = Functions::actionUrl(['page' => 'rrze-rsvp-services', 'action' => 'edit', 'item' => $item['id']]);
				return sprintf('<a href="%s" class="button" data-id="%s">%s</a>', $actionUrl, $item['id'], _x('Edit', 'Edit service', 'rrze-rsvp'));
			default:
				return $item[$column_name];
		}
	}

	public function get_table_classes()
	{
		return ['widefat', 'fixed', 'striped'];
	}
}
