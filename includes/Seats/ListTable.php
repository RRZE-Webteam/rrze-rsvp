<?php

namespace RRZE\RSVP\Seats;

defined('ABSPATH') || exit;

use RRZE\RSVP\CPT;
use RRZE\RSVP\Functions;

use WP_List_Table;
use WP_Query;

class ListTable extends WP_List_Table
{
    public function __construct()
    {
        parent::__construct([
            'singular' => 'seats',
            'plural' => 'seats',
            'ajax' => false
        ]);
    }

    function get_columns()
    {
        $columns = [
            'seat' => __('Seat', 'rrze-rsvp'),
            'service' => __('Service', 'rrze-rsvp'),
            'description' => __('Description', 'rrze-rsvp'),
            'actions' => ''
        ];
        return $columns;
    }

    function prepare_items()
    {
        $perPage = $this->get_items_per_page('rrze_rsvp_exceptions_per_page', 10);
        $currentPage = $this->get_pagenum();
        $offset = ($currentPage - 1) * $perPage;
        $args = [
            'post_type' => CPT::getCptSeatsName(),
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'offset' => -1
        ];

        $query = new WP_Query();
        $posts = $query->query($args);

        $prepItems = [];

        foreach ($posts as $post) {
            $categories = get_the_terms($post->ID, CPT::getTaxonomyServiceName());
            $service = $categories[0]->name;

            $prepItems[$post->ID]['id'] = $post->ID;
            $prepItems[$post->ID]['seat'] = $post->post_title;
            $prepItems[$post->ID]['service'] = $service;
            $prepItems[$post->ID]['description'] = $post->post_content;

            $prepItems[$post->ID]['actions'] = '';
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

    function column_default($item, $column_name)
    {
        $nonce_action = wp_create_nonce('action');

        switch ($column_name) {
            case 'actions':
                $actionUrl = Functions::actionUrl(['page' => 'rrze-rsvp-seats', 'action' => 'edit', 'item' => $item['id']]);
                $editButton = sprintf('<a href="%s" class="button" data-id="%s">%s</a>', $actionUrl, $item['id'], _x('Edit', 'Edit seat', 'rrze-rsvp'));
                $actionUrl = Functions::actionUrl(['page' => 'rrze-rsvp-seats', 'action' => 'cancel', 'item' => $item['id']]);
                $cancelButton = sprintf('<a href="%s" class="button" data-id="%s">%s</a>', $actionUrl, $item['id'], _x('Cancel', 'Cancel seat', 'rrze-rsvp'));
                $actionUrl = Functions::actionUrl(['page' => 'rrze-rsvp-seats', 'action' => 'delete', 'item' => $item['id']]);
                $deleteButton = sprintf('<a href="%s" class="button" data-id="%s">%s</a>', $actionUrl, $item['id'], _x('Delete', 'Delete seat', 'rrze-rsvp'));
                return $editButton . $deleteButton;
            default:
                return !empty($item[$column_name]) ? $item[$column_name] : '&mdash;';
        }
    }
}
