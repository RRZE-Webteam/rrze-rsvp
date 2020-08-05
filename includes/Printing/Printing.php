<?php

namespace RRZE\RSVP\Printing;

defined('ABSPATH') || exit;

use RRZE\RSVP\Printing\PDF;


class Printing {

	public function __construct() {
    }

	public function onLoaded() {
		add_filter('bulk_actions-edit-room', [$this, 'addBulkActions']);
		add_filter('bulk_actions-edit-seat', [$this, 'addBulkActions']);
		add_filter('handle_bulk_actions-edit-room', [$this, 'bulkGeneratePDF'], 10, 3);
		add_filter('handle_bulk_actions-edit-seat', [$this, 'bulkGeneratePDF'], 10, 3);
        add_action('admin_notices', [$this, 'bulkAdminNotice']);
		add_action('admin_init', [$this, 'handlePDFAction']);
    }

    public function handlePDFAction(){
        if( isset($_POST['generate_pdf'])){
            $seat_ids = get_option('rsvp_pdf_ids');
            if (!$seat_ids){
                echo __('No seats foound', 'rrze-rsvp');
                exit;
            }
            $pdf = new PDF();
            $pdf->createPDF($seat_ids);
        }
    }
    
    public function addBulkActions($bulk_actions) {
        $bulk_actions['generate-pdf'] = __( 'Generate PDF', 'rrze-rsvp');
        return $bulk_actions;
    }

    public function bulkGeneratePDF( $redirect_to, $doaction, $post_ids ) {
        if ( $doaction !== 'generate-pdf' ) {
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
                $aSeats = array_merge($aSeats, $seat_ids);
            }
        } else {
            $aSeats = $post_ids;
        }

        // store $post_ids for seats in option 'rsvp-pdf-ids'
        update_option('rsvp_pdf_ids', json_encode($aSeats));

        $redirect_to = add_query_arg('seatCnt', count($aSeats), $redirect_to);
        return $redirect_to;
    }

    public function bulkAdminNotice() {
        if (!empty($_REQUEST['seatCnt'])) {
            $cnt = intval( $_REQUEST['seatCnt'] );
            // remove_query_arg('seatCnt');

            printf( '<div id="message" class="updated fade">' 
                . '<form method="post" id="as-fdpf-form" target="_blank"><button class="button button-primary" type="submit" name="generate_pdf" value="generate">' 
                . _n( 'Generate PDF for %s seat', 'Generate PDF for %s seats', $cnt, 'rrze-rsvp') 
                . '</button></form>' . '</div>', $cnt );
        }
    }

}
