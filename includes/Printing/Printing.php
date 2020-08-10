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
        add_filter('post_row_actions', [$this, 'addRowActions'], 10, 2 );

        add_action('admin_notices', [$this, 'bulkAdminNotice']);
		add_action('admin_init', [$this, 'handlePDFAction']);
    }


    public function addRowActions( $actions, $post ) {
        if ($post->post_type == 'seat' || $post->post_type == 'room'){
            $actions['generate-pdf'] = '<a href="?'. $post->post_type . '='. $post->ID . '&generate_pdf" title="" rel="permalink">' . __( 'Generate PDF', 'rrze-rsvp') . '</a>';
        }
        return $actions;
    }    

    public function handlePDFAction(){
        $aSeats = [];

        if (isset($_GET['generate_pdf'])){
            // Click on row action link on wp-admin/edit.php?post_type=seat or wp-admin/edit.php?post_type=room
            if (isset($_GET['seat'])){
                $aSeats = [filter_input(INPUT_GET, 'seat', FILTER_VALIDATE_INT)];
            }elseif (isset($_GET['room'])){
                $room_id = filter_input(INPUT_GET, 'room', FILTER_VALIDATE_INT);
                // get seats for this room
                $seat_ids = get_posts([
                    'meta_key'   => 'rrze-rsvp-seat-room',
                    'meta_value' => $room_id,
                    'post_type' => 'seat',
                    'fields' => 'ids',
                    'orderby' => 'post_title',
                    'order' => 'ASC',
                    'numberposts' => -1
                ]);
                $aSeats = array_merge($aSeats, $seat_ids);
            }else{
                echo __('No seats found', 'rrze-rsvp');
                exit;
            }
        }elseif (isset($_POST['generate_pdf'])){
            // Click on button "Generate PDF for x seats" which is generated after click on bulk actions on wp-admin/edit.php?post_type=seat or wp-admin/edit.php?post_type=room
            $seat_ids = get_option('rsvp_pdf_ids');
            if (!$seat_ids){
                echo __('No seats found', 'rrze-rsvp');
                exit;
            }
            $aSeats = json_decode($seat_ids);
        }

        if ($aSeats){
            // set document information
            $pdf = new PDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
            // $pdf->SetCreator(PDF_CREATOR);
            $pdf->SetAuthor('RRZE-Webteam');
            $pdf->SetTitle('RRZE-RSVP');
            // $pdf->SetSubject('');
            // $pdf->SetKeywords('PDF, Booking');
    
            // set default header data
            $instructions_de = $this->options->pdf_instructions_de;
            $instructions_en = $this->options->pdf_instructions_en;
            
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
                $y = $pdf->GetY() + $ySpace;
                $x = $pdf->GetX();
                $pdf->SetXY($x, $y);
                $pdf->Line(PDF_MARGIN_LEFT, $pdf->GetY() - 5, $pdf->getPageWidth() - PDF_MARGIN_RIGHT, $pdf->GetY() - 5);

                // room data:
                $room_post_id = get_post_meta($seat_post_id, 'rrze-rsvp-seat-room', true);
                $room = get_post($room_post_id);

                // Room title
                $pdf->MultiCell($w, 5, __('Room', 'rrze-rsvp') . ':', 0, 'L', 0);
                $pdf->SetFont('helvetica', '', 26, '', true);
                if (isset($room->post_title)){
                    $pdf->MultiCell($w, 5, $room->post_title, 0, 'L', 0);
                }
    
                // Seat
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
    
                // Room address
                $ySeat = $pdf->GetY();
                $yRoomSeat = ($yRoom < $ySeat ? $ySeat : $yRoom) + $ySpace;
                $y = 0;
                if ($this->options->pdf_room_address == 'on'){
                    $room_street = get_post_meta($room_post_id, 'rrze-rsvp-room-street', true);
                    $room_zip = get_post_meta($room_post_id, 'rrze-rsvp-room-zip', true);
                    $room_city = get_post_meta($room_post_id, 'rrze-rsvp-room-city', true);
                    $pdf->MultiCell(0, 5, $room_street . "\n" . $room_zip . ' ' . $room_city, 0, 'L', 0, 1, '', $yRoomSeat, true, 0);
                }
    
                // Room description
                $y = $pdf->GetY();
                $y = ( $y < $yRoomSeat ? $yRoomSeat : $y );
                if ($this->options->pdf_room_text == 'on'){
                    $pdf->MultiCell(0, 5, $room->post_content, 0, 'L', 0, 1, '', $y + $ySpace, true, 0);
                    $y = 10;
                    $yRoomSeat = $pdf->GetY();
                }

                // Floor plan
                // 2DO: check file-type + set x/y
                // $y = 0;
                // if ($this->options->pdf_room_floorplan == 'on'){
                //     $floorplan = get_post_meta($room_post_id, 'rrze-rsvp-room-floorplan');
                //     if (isset($floorplan) && isset($floorplan[0])) {
                //         $pdf->MultiCell(0, 5, __('Floor Plan', 'rrze-rsvp') . ':', 0, 'L', 0, 1, '', $pdf->GetY(), true, 0);
                //         $pdf->Image($floorplan[0], $x='', $y, $w, $h, 'JPG', '', '', false, 300, '', false, false, 0, $fitbox, false, false);
                //         $y = 10;
                //     }
                // }
    
                // QR Code
                $y = $pdf->GetY();
                $y = ( $y < $yRoomSeat ? $yRoomSeat : $y );
                
                $permalink = get_permalink($seat_post_id);
                $pdf->write2DBarcode($permalink, 'QRCODE,H', '', $y + $ySpace, 50, 50, $qr_style, 'N');
                $yQR = $pdf->GetY();
    
                // Instructions
                $pdf->MultiCell(0, 5, $instructions_de, 0, 'L', 0, 1, 50 + 20, $y + $ySpace, true, 0);
                $pdf->MultiCell(0, 5, $instructions_en, 0, 'L', 0, 1, 50 + 20, $pdf->GetY() + $ySpace, true, 0);
                $y = $pdf->GetY();
                $y = ($y < $yQR ? $yQR : $y);
                $pdf->Text($pdf->GetX(), $y + 5, $permalink);


                // Seat equiptment
                if ($this->options->pdf_seat_equipment == 'on'){
                    $html = '';
                    $aEquipment = get_the_terms($seat_post_id, 'rrze-rsvp-equipment');
                    if ($aEquipment){
                        $html = '<strong>' . __('Equipment:', 'rrze-rsvp') . '</strong><ul>';
                        foreach($aEquipment as $equipment){
                            $html .= '<li>' . $equipment->name . '</li>';
                        }
                        $html .= '<ul>';
                        $pdf->writeHTMLCell(0, 1, PDF_MARGIN_LEFT, $pdf->GetY() + 2*$ySpace, $html, 0, 2, 0);
                    }
                }
            }
    
            $pdf_file_name = 'rrze-rsvp-' . date('Y-m-d-His') . '.pdf';
            $pdf->Output($pdf_file_name, 'I');
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
