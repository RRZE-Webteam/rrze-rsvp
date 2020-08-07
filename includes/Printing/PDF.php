<?php
namespace RRZE\RSVP\Printing;

defined('ABSPATH') || exit;

require_once __DIR__ . '/../../vendor/tcpdf/tcpdf.php';

use TCPDF;
use RRZE\RSVP\Settings;
use function RRZE\RSVP\plugin;

class PDF extends TCPDF{

    protected $options;

	public function __construct() {
        parent::__construct();

        $settings = new Settings(plugin()->getFile());
        $this->options = (object) $settings->getOptions();
    }

    //Page header
    public function Header() {
        $wTitle = 0;
        if ($this->options->pdf_fau_logo == 'on'){
            $fau_logo = esc_url(plugins_url('assets/img/fau-logo-240x65.svg', plugin()->getBasename()));
            $this->ImageSVG($file=$fau_logo, $x=0, $y=0, $w='', $h='', $link='', $align='', $palign='R', $border=0, $fitonpage=false);
        }
        if ($this->options->pdf_website_logo == 'on'){
            $website_logo = get_header_image();
            if ( !empty($website_logo)) {
                $filetype = wp_check_filetype($website_logo);
                switch($filetype['ext']){
                    case 'svg':
                        $this->ImageSVG($file=$website_logo, $x='', $y=5, $w='', $h='', $link='', $align='', $palign='', $border=0, $fitonpage=false);
                    break;
                    default:
                        $this->Image($website_logo, $x='', $y=5, $w='', $h=20);
                }
            } else {
                $this->SetFont('helvetica', '', 26, '', true);
                $this->MultiCell(0, 5, get_bloginfo( 'title' ), 0, 'L', 0);
            }
        }    
    }    

    public function Footer() {
        if ($this->options->pdf_website_url == 'on'){
            $this->Line(PDF_MARGIN_LEFT, $this->GetY() - 5, $this->getPageWidth() - PDF_MARGIN_RIGHT, $this->GetY() - 5);
            $this->SetY(-14); 
            $website_url = preg_replace( "/^((http|https):\/\/)?/i", '', home_url() );
            $this->Cell(0, 0, $website_url, 0, false, 'L', 0, '', 0, false, 'T', 'M');
        }
    }
   
}


