<?php
namespace RRZE\RSVP\Printing;

defined('ABSPATH') || exit;

require_once __DIR__ . '/tcpdf/tcpdf.php';

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
            $wLogo = 80;
            $wPage = $this->getPageWidth() - PDF_MARGIN_RIGHT;
            $xLogo = $wPage - $wLogo;
            $fau_logo = esc_url(plugins_url('assets/img/fau-logo-240x65.svg', plugin()->getBasename()));
            $this->ImageSVG($file=$fau_logo, $x=$xLogo, $y=0, $w='', $h='', $link='', $align='', $palign='', $border=0, $fitonpage=false);
            $wTitle = $wPage - $wLogo - 5;
        }
        if ($this->options->pdf_website_logo == 'on'){
            $website_logo = get_header_image();
            if ( !empty($website_logo)) {
                $this->ImageSVG($file=$website_logo, $x='', $y=5, $w='', $h='', $link='', $align='', $palign='', $border=0, $fitonpage=false);
            } else {
                $this->SetFont('helvetica', '', 26, '', true);
                $this->MultiCell($wTitle, 5, get_bloginfo( 'title' ), 0, 'L', 0);
                // $this->Cell(0, 0, get_bloginfo( 'title' ), 0, false, 'L', 0, '', 0, false, 'T', 'M');
                // $yLine = $this->GetY();
            }
        }    
            // $this->Line('',$this->y,200,$this->y);
            $this->Line('',$this->GetY(),200,$this->y);
            // $this->writeHTML("<hr>", true, false, false, false, '');
    }    

    public function Footer() {
        if ($this->options->pdf_website_url == 'on'){
            $website_url = preg_replace( "/^((http|https):\/\/)?/i", '', home_url() );
            $this->Cell(0, 0, $website_url, 0, false, 'L', 0, '', 0, false, 'T', 'M');
        }
    }
   
}


