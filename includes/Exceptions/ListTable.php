<?php

namespace RRZE\RSVP\Exceptions;

defined('ABSPATH') || exit;

use RRZE\RSVP\CPT;
use RRZE\RSVP\Functions;
use Carbon\Carbon;

use WP_List_Table;
use WP_Query;

class ListTable extends WP_List_Table
{
    public function __construct()
    {
        parent::__construct([
            'singular' => 'exception',
            'plural' => 'exceptions',
            'ajax' => false
        ]);
    }

    function get_columns()
    {
        $columns = [
            'start' => __('Start', 'rrze-rsvp'),
            'end' => __('Duration', 'rrze-rsvp'),
            'category' => __('Category', 'rrze-rsvp'),
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
            'post_type' => CPT::getCptExceptionsName(),
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'offset' => -1,
            'meta_key' => 'rrze_rsvp_excpt_end',
            'orderby' => 'meta_value',
            'order' => 'ASC',
            'meta_query' => [
                'key' => 'rrze_rsvp_excpt_end',
                'value' => current_time('mysql'),
                'compare' => '>='
            ]
        ];

        $query = new WP_Query();
        $posts = $query->query($args);

        $prepItems = [];

        foreach ($posts as $post) {
            $start = new Carbon(get_post_meta($post->ID, 'rrze_rsvp_excpt_start', true));
            $end = new Carbon(get_post_meta($post->ID, 'rrze_rsvp_excpt_end', true));

            $startOutput = date_i18n(get_option('date_format'), $start->timestamp);

            if ($start->isSameDay($end)) {
                if ($start->format('H:i') == '00:00' && $end->format('H:i') == '00:00') {
                    $endOutput = __('Full day', 'rrze-rsvp');
                } else {
                    $endOutput = date_i18n(get_option('time_format'), $start->timestamp) . ' - ' . date_i18n(get_option('time_format'), $end->timestamp);
                }
            } else {
                if ($start->format('H:i') == '00:00' && $end->format('H:i') == '00:00') {
                    $endOutput = date_i18n(get_option('date_format'), $end->timestamp);
                } else {
                    $endOutput = date_i18n(get_option('time_format'), $start->timestamp) . ' - ' .  date_i18n(get_option('date_format'), $end->timestamp) . ' ' . date_i18n(get_option('time_format'), $end->timestamp);
                }
            }

            $categories = get_the_terms($post->ID, CPT::getTaxonomyServiceName());
            $category = $categories[0]->name;

            $prepItems[$post->ID]['id'] = $post->ID;
            $prepItems[$post->ID]['start'] = $startOutput;
            $prepItems[$post->ID]['end'] = $endOutput;
            $prepItems[$post->ID]['category'] = $category;
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
            case 'start':
            case 'end':
                return $item[$column_name];
            case 'actions':
                $actionUrl = Functions::actionUrl(['page' => 'rrze-rsvp-exceptions', 'action' => 'edit', 'item' => $item['id']]);
                return sprintf('<a href="%s" class="button" data-id="%s">%s</a>', $actionUrl, $item['id'], _x('Delete', 'Edit exception', 'rrze-rsvp'));
            default:
                return !empty($item[$column_name]) ? $item[$column_name] : '&mdash;';
        }
    }
}
