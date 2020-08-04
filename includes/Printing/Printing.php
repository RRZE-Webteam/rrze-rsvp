<?php

namespace RRZE\RSVP\Printing;

defined('ABSPATH') || exit;

use RRZE\RSVP\Printing\PDF;



class Printing {

    private $file_name;
    private $file_url;

	public function __construct() {
        $upload_dir = wp_get_upload_dir();
        $this->file_name = $upload_dir['basedir'] . '/seats.pdf';
        $this->file_url = $upload_dir['baseurl'] . '/seats.pdf';
	}

	public function onLoaded() {
		add_filter('bulk_actions-edit-room', [$this, 'add_bulk_actions']);
		add_filter('bulk_actions-edit-seat', [$this, 'add_bulk_actions']);
		add_filter('handle_bulk_actions-edit-room', [$this, 'bulk_print_qr'], 10, 3);
		add_filter('handle_bulk_actions-edit-seat', [$this, 'bulk_print_qr'], 10, 3);
		add_action('admin_notices', [$this, 'bulk_action_admin_notice']);
    }
    
    public function add_bulk_actions($bulk_actions) {
        $bulk_actions['print-qr'] = __( 'Generate PDF', 'rrze-rsvp');
        return $bulk_actions;
    }

    public function bulk_print_qr( $redirect_to, $doaction, $post_ids ) {
        if ( $doaction !== 'print-qr' ) {
            return $redirect_to;
        }

        $aSeats = array();

        $currentScreen = get_current_screen();
        $postType = $currentScreen->post_type;

        if ($postType == 'room'){
            // get seats for each selected room
            foreach ( $post_ids as $post_id ){
                $seat_ids = get_posts([
                    'meta_key'   => 'rrze-rsvp-seat-room',
                    'meta_value' => $post_id,
                    'post_type' => 'seat',
                    'fields' => 'ids',
                    'orderby' => 'post_title',
                    'order' => 'ASC',
                    'numberposts' => -1
                ]);
                array_push($aSeats, $seat_ids);
            }
        } else {
            $aSeats = $post_ids;
        }

        $pdf = new PDF();
        $pdf->generatePDF($this->file_name, json_encode($aSeats));

        $redirect_to = add_query_arg('seatCnt', count($aSeats), $redirect_to);
        return $redirect_to;
    }

    public function bulk_action_admin_notice() {
        if (!empty($_REQUEST['seatCnt'])) {
            $cnt = intval( $_REQUEST['seatCnt'] );
            printf( '<div id="message" class="updated fade">' .
            _n( 'PDF generated for %s seat',
                'PDF generated for %s seats',
                $cnt,
                'rrze-rsvp'
            ) . ': ' . '<a href="' . $this->file_url . '" target="_blank">Open PDF</a>' . '</div>', $cnt );
        }
    }

}
