<?php

namespace RRZE\RSVP\Printing;

defined('ABSPATH') || exit;

use RRZE\RSVP\Settings;
use function RRZE\RSVP\plugin;
use RRZE\RSVP\Printing\PDF;


class Printing {

    protected $options;

	public function __construct() {
        $settings = new Settings(plugin()->getFile());
        $this->options = (object) $settings->getOptions();
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

            $aSeats = json_decode($seat_ids);
    
            // set document information
            $pdf = new PDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
            // $pdf->SetCreator(PDF_CREATOR);
            $pdf->SetAuthor('RRZE-Webteam');
            // $pdf->SetTitle('');
            // $pdf->SetSubject('');
            // $pdf->SetKeywords('PDF, Booking');
    
            // set default header data
            $instructions_de = $this->options->pdf_instructions_de;
            $instructions_en = $this->options->pdf_instructions_en;
            
            // room data:
            $room_post_id = get_post_meta($aSeats[0], 'rrze-rsvp-seat-room', true);
            $room = get_post($room_post_id);
    
            // set default monospaced font
            $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    
            // set margins
            $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
            $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
            $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
    
            // set default font subsetting mode
            $pdf->setFontSubsetting(true);
    
            $pdf->SetFont('helvetica', '', 12, '', true);
    
            $qr_style = array(
                'border' => true,
                'vpadding' => 'auto',
                'hpadding' => 'auto',
                'fgcolor' => array(0,0,0),
                'bgcolor' => false, //array(255,255,255)
                'module_width' => 1, // width of a single module in points
                'module_height' => 1 // height of a single module in points
            );
    
            foreach($aSeats as $seat_post_id){
                $pdf->AddPage();
    
                $columnMargin = 5;
                $ySpace = 10;
                $w = ($pdf->getPageWidth() - PDF_MARGIN_LEFT - PDF_MARGIN_RIGHT - $columnMargin) / 2;
                $y = $pdf->GetY();
                $pdf->MultiCell($w, 5, __('Room', 'rrze-rsvp') . ':', 0, 'L', 0);
                $pdf->SetFont('helvetica', '', 26, '', true);
                if (isset($room->post_title)){
                    $pdf->MultiCell($w, 5, $room->post_title, 0, 'L', 0);
                }
    
                $x = $pdf->GetX();
                $yRoom = $pdf->GetY();
                $pdf->SetXY($x + $w + $columnMargin, $y);
    
                $seat_title = get_the_title($seat_post_id);
                $pdf->SetFont('helvetica', '', 12, '', true);
                $pdf->MultiCell(0, 5, __('Seat', 'rrze-rsvp') . ':', 0, 'L', 0);
                $x = $pdf->GetX();
                $y = $pdf->GetY();
                $pdf->SetXY($x + $w + $columnMargin, $y);
                $pdf->SetFont('helvetica', '', 36, '', true);
                $pdf->MultiCell($w, 5, $seat_title, 0, 'L', 0);
                $pdf->SetFont('helvetica', '', 12, '', true);
    
                $ySeat = $pdf->GetY();
    
                $yRoomSeat = ($yRoom < $ySeat ? $ySeat : $yRoom) + $ySpace;
    
                $y = 0;
                if ($this->options->pdf_room_address == 'on'){
                    $room_street = get_post_meta($room_post_id, 'rrze-rsvp-room-street', true);
                    $room_zip = get_post_meta($room_post_id, 'rrze-rsvp-room-zip', true);
                    $room_city = get_post_meta($room_post_id, 'rrze-rsvp-room-city', true);
                    $pdf->MultiCell(0, 5, $room_street . "\n" . $room_zip . ' ' . $room_city, 0, 'L', 0, 1, '', $yRoomSeat, true, 0);
                }
    
                $y = $pdf->GetY();
                $y = ( $y < $yRoomSeat ? $yRoomSeat : $y );
                if ($this->options->pdf_room_text == 'on'){
                    $pdf->MultiCell(0, 5, $room->post_content, 0, 'L', 0, 1, '', $y + $ySpace, true, 0);
                    $y = 10;
                    $yRoomSeat = $pdf->GetY();
                }
    
                // $y = 0;
                // if ($this->options->pdf_room_floorplan == 'on'){
                //     $meta = get_post_meta($room_post_id, 'rrze-rsvp-room-floorplan'); // why rrze-rsvp-room-floorplan_id and not rrze-rsvp-room-floorplan?
                //     if (isset($meta['rrze-rsvp-room-floorplan_id']) && $meta['rrze-rsvp-room-floorplan_id'] != '') {
                //         $img_src = wp_get_attachment_image_src( $meta['rrze-rsvp-room-floorplan_id'][0]);
                //         $pdf->MultiCell(0, 5, __('Floor Plan', 'rrze-rsvp') . ':', 0, 'L', 0, 1, '', $pdf->GetY(), true, 0);
                //         $pdf->Image($img_src, $x, $y, $w, $h, 'JPG', '', '', false, 300, '', false, false, 0, $fitbox, false, false);
                //         $y = 10;
                //     }
                // }
    
                $y = $pdf->GetY();
                $y = ( $y < $yRoomSeat ? $yRoomSeat : $y );
                
                $permalink = get_permalink($seat_post_id);
                $pdf->write2DBarcode($permalink, 'QRCODE,H', '', $y + $ySpace, 50, 50, $qr_style, 'N');
                $yQR = $pdf->GetY();
    
                $pdf->MultiCell(0, 5, $instructions_de, 0, 'L', 0, 1, 50 + 20, $y + $ySpace, true, 0);
                $pdf->MultiCell(0, 5, $instructions_en, 0, 'L', 0, 1, 50 + 20, $pdf->GetY() + $ySpace, true, 0);
                $y = $pdf->GetY();
                $y = ($y < $yQR ? $yQR : $y);
                $pdf->Text($pdf->GetX(), $y + 5, $permalink);
                $pdf->Text(0, 0, $website_url);
            }
    
            if (isset($room->post_title)){
                $pdf_file_name = sanitize_file_name($room->post_title);
            }else{
                $pdf_file_name = 'seat';
            }
    
            $pdf->Output($pdf_file_name . '.pdf', 'I');
    
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
