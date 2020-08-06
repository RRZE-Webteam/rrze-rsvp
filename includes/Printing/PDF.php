<?php
namespace RRZE\RSVP\Printing;

defined('ABSPATH') || exit;

require_once __DIR__ . '/tcpdf/tcpdf.php';

use TCPDF;
use RRZE\RSVP\Settings;
use function RRZE\RSVP\plugin;



class PDF extends TCPDF{

    public $pdf;
    protected $options;


	public function __construct() {
        $settings = new Settings(plugin()->getFile());
        $this->options = (object) $settings->getOptions();
    }

    public function Header() {

        // $customheader =  get_custom_header();
        // $customheader->url
        // $customheader->width
        // $customheader->height

        // Logo
        // https://www.nickless.test.rrze.fau.de/dev4/wp-content/themes/FAU-Einrichtungen/img/logos/fau-logo-240x65.svg
        // $image_file = K_PATH_IMAGES.'logo_example.jpg';
        // $this->Image($image_file, 10, 10, 15, '', 'JPG', '', 'T', false, 300, '', false, false, 0, false, false, false);

        // Set font
        // $this->SetFont('helvetica', 'B', 20);
        // Title
        // $this->Cell(0, 15, '<< TCPDF Example 003 >>', 0, false, 'C', 0, '', 0, false, 'M', 'M');
    }    

    public function createPDF($seat_ids){
        if (!$seat_ids){
            return;
        }

        $aSeats = json_decode($seat_ids);

        // set document information
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        // $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor('RRZE-Webteam');
        // $pdf->SetTitle('TCPDF Example 001');
        // $pdf->SetSubject('TCPDF Tutorial');
        // $pdf->SetKeywords('TCPDF, PDF, example, test, guide');

        // set default header data
        $logo = $this->options->pdf_logo;
        $instructions_de = $this->options->pdf_instructions_de;
        $instructions_en = $this->options->pdf_instructions_en;
        
        // room data:
        $room_post_id = get_post_meta($aSeats[0], 'rrze-rsvp-seat-room', true);
        $room = get_post($room_post_id);

        // logo:
        // $custom_logo_id = get_theme_mod( 'custom_logo' );
        // $image = wp_get_attachment_image_src( $custom_logo_id , 'full' );
        // echo $image[0];

        // $pdf->SetHeaderData($logo, PDF_HEADER_LOGO_WIDTH, '', PDF_HEADER_STRING, array(0,64,255), array(0,64,128));
        // $pdf->SetHeaderData($logo, PDF_HEADER_LOGO_WIDTH, $room->post_title, '', 0, 0);
        // $pdf->setFooterData(array(0,64,0), array(0,64,128));
        // $pdf->setFooterData(array(0,64,0), array(0,64,128));
        $pdf->SetPrintFooter(false);

        // set header and footer fonts
        // $pdf->setHeaderFont(Array(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN));
        // $pdf->setFooterFont(Array(PDF_FONT_NAME_DATA, '', PDF_FONT_SIZE_DATA));

        // set default monospaced font
        $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);

        // set margins
        $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
        $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
        $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);

        // set auto page breaks
        // $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

        // set image scale factor
        // $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

        // set some language-dependent strings (optional)
        // if (@file_exists(dirname(__FILE__).'/lang/eng.php')) {
        //     require_once(dirname(__FILE__).'/lang/eng.php');
        //     $pdf->setLanguageArray($l);
        // }

        // ---------------------------------------------------------

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
            $pdf->SetFont('helvetica', '', 36, '', true);
            $pdf->MultiCell($w, 5, $room->post_title, 0, 'L', 0);

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

            // $pdf->Cell(70, -5, $room->post_title, '', 0, 'l', true);

            // $pdf->MultiCell(0, 5, __('Room', 'rrze-rsvp') . ':', 0, 'L', 0, 1, '', $pdf->GetY(), true, 0);
            // $pdf->MultiCell(0, 5, $room->post_title, 0, 'L', 0, 1, '', '', true, 0);


            $y = 0;
            if ($this->options->pdf_room_address == 'on'){
                // $x = $pdf->GetX();
                // $y = $pdf->GetY() + $ySpace;
                // $pdf->SetXY($x, $y);
                $room_street = get_post_meta($room_post_id, 'rrze-rsvp-room-street', true);
                $room_zip = get_post_meta($room_post_id, 'rrze-rsvp-room-zip', true);
                $room_city = get_post_meta($room_post_id, 'rrze-rsvp-room-city', true);
                $pdf->MultiCell(0, 5, $room_street . "\n" . $room_zip . ' ' . $room_city, 0, 'L', 0, 1, '', $yRoomSeat, true, 0);
                // $pdf->MultiCell(0, 5, $room_street . "\n" . $room_zip . ' ' . $room_city, 0, 'L', 0, 1, '', '', true, 0);
                // $pdf->MultiCell(0, 5, $room_street . "\n" . $room_zip . ' ' . $room_city, 0, 'L', 0);
                // $y = 10;
            }

            // $y = 0;
            $y = $pdf->GetY();
            $y = ( $y < $yRoomSeat ? $yRoomSeat : $y );
            if ($this->options->pdf_room_text == 'on'){
                $pdf->MultiCell(0, 5, $room->post_content, 0, 'L', 0, 1, '', $y + $ySpace, true, 0);
                // $pdf->MultiCell(0, 5, $room->post_content, 0, 'L', 0, 1, '', $pdf->GetY() + 10, true, 0);
                // $pdf->MultiCell(0, 5, $room->post_content, 0, 'L', 0);
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

            // $seat_title = get_the_title($seat_post_id);
            // $pdf->MultiCell(0, 5, __('Seat', 'rrze-rsvp') . ':', 0, 'L', 0, 1, '', $pdf->GetY() + 10, true, 0);
            // $pdf->MultiCell(0, 5, $seat_title, 0, 'L', 0, 1, '', $pdf->GetY(), true, 0);

            $y = $pdf->GetY();
            $y = ( $y < $yRoomSeat ? $yRoomSeat : $y );

            $pdf->MultiCell(0, 5, $instructions_de, 0, 'L', 0, 1, '', $y + $ySpace, true, 0);
            $pdf->MultiCell(0, 5, $instructions_en, 0, 'L', 0, 1, '', $pdf->GetY() + $ySpace, true, 0);
            
            $permalink = get_permalink($seat_post_id);
            $pdf->write2DBarcode($permalink, 'QRCODE,H', 20, $pdf->GetY() + 10, 50, 50, $qr_style, 'N');
            $pdf->Text(20, $pdf->GetY() + 5, $permalink);

        }


        // ---------------------------------------------------------

        // Close and output PDF document
        // This method has several options, check the source code documentation for more information.
        $pdf->Output(sanitize_file_name($room->post_title) . '.pdf', 'I');
    }

}


