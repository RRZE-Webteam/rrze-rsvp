<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use stdClass;

class FormPage
{
    protected $pageSlug;

    protected $pageTitle;

    protected $content;

    public function __construct(string $pageSlug, string $pageTitle, string $content)
    {
        $this->pageSlug = sanitize_title($pageSlug);
        $this->pageTitle = sanitize_text_field($pageTitle);
        $this->content = sanitize_textarea_field($content);
    }

    public function onLoaded()
    {
        if (empty($this->pageSlug)) {
            return;
        }
        add_action('init', [$this, 'init']);
    }

    public function init()
    {
        if (get_option('permalink_structure')) {
            $param = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
        } else {
            parse_str(parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $params);
            $param = (isset($params['page_id']) ? $params['page_id'] : false);
        }

        if ($param == $this->pageSlug) {
            wp_enqueue_style('rrze-rsvp-shortcode');
            wp_enqueue_script('rrze-rsvp-shortcode');
            wp_localize_script('rrze-rsvp-shortcode', 'rsvp_ajax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('rsvp-ajax-nonce'),
            ]);

            add_filter('the_posts', [$this, 'generatePage']);
        }
    }

    public function generatePage(array $posts): array
    {
        global $wp, $wp_query;

        if (strcasecmp($wp->request, $this->pageSlug) !== 0) {
            return $posts;
        }

        $post = $this->postObject();

        $posts = [$post];

        $wp_query->is_page = true;
        $wp_query->is_singular = true;
        $wp_query->is_home = false;
        $wp_query->is_archive = false;
        $wp_query->is_category = false;
        unset($wp_query->query['error']);
        $wp_query->query_vars['error'] = '';
        $wp_query->is_404 = false;

        return ($posts);
    }

    protected function postObject(): object
    {
        $post                        = new stdClass;
        $post->ID                    = -1;
        $post->post_author           = 1;
        $post->post_date             = current_time('mysql');
        $post->post_date_gmt         = current_time('mysql', true);
        $post->post_content          = $this->content;
        $post->post_title            = $this->pageTitle;
        $post->post_excerpt          = '';
        $post->post_status           = 'publish';
        $post->comment_status        = 'closed';
        $post->ping_status           = 'closed';
        $post->post_password         = '';
        $post->post_name             = $this->pageSlug;
        $post->to_ping               = '';
        $post->pinged                = '';
        $post->modified              = $post->post_date;
        $post->modified_gmt          = $post->post_date_gmt;
        $post->post_content_filtered = '';
        $post->post_parent           = 0;
        $post->guid                  = get_home_url(1, '/' . $this->pageSlug);
        $post->menu_order            = 0;
        $post->post_tyle             = 'page';
        $post->post_mime_type        = '';
        $post->comment_count         = 0;

        return $post;
    }
}
