<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use RRZE\RSVP\Functions;
// use RRZE\RSVP\Capabilities;


if (isset($_POST['rsvp_room_id'])){
    echo '<pre>';
    echo 'echo rsvp_room_id posted';
    $roomId = filter_input(INPUT_POST, 'rsvp_room_id', FILTER_VALIDATE_INT);
    $response = Functions::getOccupancyByRoomId($roomId);

    var_dump($response);

    exit;
}

class Occupancy{
    /**
     * Options
     * @var object
     */
    protected $options;


    /**
     * __construct
     */
    public function __construct(){
        // $settings = new Settings(plugin()->getFile());
        // $this->options = (object) $settings->getOptions();
    }

    public function onLoaded(){
        add_action( 'admin_menu', [$this, 'registerOccupancyPage'] );
        add_action( 'admin_enqueue_scripts', [$this, 'adminEnqueueScripts']);
        // add_action( 'wp_ajax_ShowOccupancy', [$this, 'ajaxGetOccupancy'] );
        // add_action( 'wp_ajax_nopriv_ShowOccupancy', [$this, 'ajaxGetOccupancy'] );
    }

    public function registerOccupancyPage(){
        // $cpts = array_keys(Capabilities::getCurrentCptArgs());

        add_submenu_page(
            'edit.php?post_type=booking',      // parent slug
            __( 'Current room occupancy', 'rrze-rsvp' ),            // page title
            __( 'Room occupancy', 'rrze-rsvp' ),       // menu title
            'manage_options',         // capability
            'rrze-rsvp', // 'rrze-rsvp'
            [$this, 'getOccupancyPage'],
            2      
        );
    }

    public function adminEnqueueScripts(){
        // wp_register_style('rrze-rsvp-occupancy', plugins_url('assets/css/rrze-rsvp.css', plugin_basename($this->pluginFile)));
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
        // check_ajax_referer( 'rsvp-ajax-nonce', 'nonce'  );
        $roomId = filter_input(INPUT_POST, 'roomId', FILTER_VALIDATE_INT);
        // echo "<script>console.log('in ajaxGetOccupancy roomId = " . $roomId . "' );</script>";
        $response = Functions::getOccupancyByRoomId($roomId);
        wp_send_json($response);
        // echo $response;
    }


    public function getOccupancyPage(){
        echo '<div class="wrap">';
        echo '<h1>' . esc_html_x( 'Current room occupancy', 'admin page title', 'rrze-rsvp' ) . '</h1>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="select_room">' . __('Room','rrze-rsvp') . '</label></th>';
        echo '<td>'
            . '<form action="" method="post" class="occupancy">'
            // . '<form action="' . get_permalink() . '" method="post" id="rsvp_by_room">'
            // . '<div id="loading"><i class="fa fa-refresh fa-spin fa-4x"></i></div>'
            . '<select id="rsvp_room_id" name="rsvp_room_id">'
            . '<option>&mdash; ' . __('Please select', 'rrze-rsvp') . ' &mdash;</option>';

        $rooms = get_posts([
            'post_type' => 'room',
            'post_statue' => 'publish',
            'nopaging' => true,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        foreach ($rooms as $room) {
            echo '<option value="' . $room->ID . '">' . $room->post_title . '</option>';
        }

        // echo '</select></form></td></tr>';
        echo '</select> <input type="submit" value="Submit"></form></td></tr>';
        echo '</tbody></table>';

        echo '<div class="rsvp-occupancy-container"></div>';
        
        echo '</div>';
     }

}
