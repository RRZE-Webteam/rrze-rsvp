<?php

namespace RRZE\RSVP\Printing;

defined('ABSPATH') || exit;

require_once __DIR__ . '/fpdf/fpdf.php';
use FPDF;
use RRZE\RSVP\Printing\QR;

class PDF extends FPDF{

	public function __construct() {
        parent::__construct();

	}

    // 2DO: get logo from settings
    // public function Header() {
        // Logo
        // $this->Image('../assets/img/Logo.jpg', 15, 10, 75, 0, 'JPG');
        // $this->Image('../assets/img/Logo_RW.png', 120, 12, 75, 0, 'PNG');
    // }
 
    // 2DO: add footer's txt to settings + __()
    // public function Footer() {
    //     $this->SetY(-30);
    //     $this->SetFont('Helvetica','',8);
    //     $this->MultiCell(0,4, utf8_decode('Universität Erlangen-Nürnberg'),0,'C');
    //     $this->Cell(0,5,'Seite '.$this->PageNo().'/{nb}',0,0,'C');
    // }

    // 2DO: generate QR thru loop over $post_ids + add QR size to settings 
    public function generatePDF($filename, $post_ids){
        $this->AddPage();
        $this->SetFont('Arial','B',16);
        $this->Cell(40,10,'¡Hola, Mundo! $post_ids = ' . $post_ids );
        $this->Output($filename,'F');    
    }
}