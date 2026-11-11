<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

class Occupancy
{
    /**
     * Register authenticated legacy occupancy requests and the public display reload script.
     *
     * @return void
     */
    public function onLoaded()
    {
        add_action('wp_ajax_ShowOccupancy', [$this, 'ajaxGetOccupancy']);
        add_action('wp_ajax_ShowOccupancyLinks', [$this, 'ajaxGetOccupancyLinks']);
        add_action('wp_enqueue_scripts', [$this, 'wpEnqueueScripts']);
        add_action('wp_print_scripts', [$this, 'wpDenqueueScripts'], 100);
    }


    public function wpEnqueueScripts()
    {
        if (isset($_GET['reload'])) {
            wp_enqueue_script(
                'rrze-rsvp-reload',
                plugins_url('build/reload.js', plugin()->getBasename()),
                ['jquery'],
                plugin()->getVersion()
            );
        }
    }

    public function wpDenqueueScripts()
    {
        if (!isset($_GET['reload'])) {
            wp_dequeue_script('rrze-rsvp-reload');
        }
    }

    /**
     * Return the daily seat and time slot view for an authorized admin request.
     *
     * @return void Sends a JSON response and exits.
     */
    public function ajaxGetOccupancy()
    {
        check_ajax_referer('rsvp-ajax-nonce', 'nonce');
        $roomId = filter_input(INPUT_POST, 'roomId', FILTER_VALIDATE_INT);

        if (!current_user_can('edit_seats') || !$roomId || get_post_type($roomId) !== 'room') {
            header('HTTP/1.0 403 Forbidden');
            exit;
        }

        if (function_exists('is_post_publicly_viewable') && !is_post_publicly_viewable($roomId)) {
            header('HTTP/1.0 403 Forbidden');
            exit;
        }

        $response = Functions::getOccupancyByRoomIdHTMLAdmin($roomId);
        wp_send_json($response);
    }

    /**
     * Return the two public room links for an authorized admin request.
     *
     * @return void Sends a JSON response and exits.
     */
    public function ajaxGetOccupancyLinks()
    {
        check_ajax_referer('rsvp-ajax-nonce', 'nonce');
        $roomId = filter_input(INPUT_POST, 'roomId', FILTER_VALIDATE_INT);

        if (!current_user_can('edit_seats') || !$roomId || get_post_type($roomId) !== 'room') {
            header('HTTP/1.0 403 Forbidden');
            exit;
        }

        if (function_exists('is_post_publicly_viewable') && !is_post_publicly_viewable($roomId)) {
            header('HTTP/1.0 403 Forbidden');
            exit;
        }

        $response = Functions::getOccupancyLinks($roomId);
        wp_send_json($response);
    }
}
