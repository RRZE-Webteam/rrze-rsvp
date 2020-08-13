<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use RRZE\RSVP\Functions;



class Occupancy{

    public function __construct(){
    }

    public function onLoaded(){
        add_action( 'admin_enqueue_scripts', [$this, 'adminEnqueueScripts']);
        add_action( 'wp_ajax_ShowOccupancy', [$this, 'ajaxGetOccupancy'] );
        add_action( 'wp_ajax_nopriv_ShowOccupancy', [$this, 'ajaxGetOccupancy'] );
    }

    public function adminEnqueueScripts(){
        wp_enqueue_script(
			'rrze-rsvp-occupancy',
			plugins_url('assets/js/occupancy.js', plugin()->getBasename()),
			['jquery'],
			plugin()->getVersion()
        );    

        wp_localize_script('rrze-rsvp-occupancy', 'rsvp_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce( 'rsvp-ajax-nonce' ),
        ]);

    }

    public function ajaxGetOccupancy() {
        check_ajax_referer( 'rsvp-ajax-nonce', 'nonce'  );
        $roomId = filter_input(INPUT_POST, 'roomId', FILTER_VALIDATE_INT);
        $response = Functions::getOccupancyByRoomIdHTML($roomId);
        wp_send_json($response);
    }
}
