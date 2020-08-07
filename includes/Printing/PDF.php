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
        // $this->options->pdf_logo
        $wLogo = 60;
        $xLogo = $this->getPageWidth() - PDF_MARGIN_RIGHT - $wLogo;
        $logo = plugins_url('assets/img/fau-logo-240x65.svg', plugin()->getBasename());
        $this->ImageSVG($file=$logo, $x=$xLogo, $y=0, $w=60, $h='', $link='', $align='', $palign='', $border=0, $fitonpage=false);
    }    

    public function Footer() {
        $website_url = preg_replace( "/^((http|https):\/\/)?/i", '', home_url() );
        $this->Cell(0, 0, $website_url, 0, false, 'L', 0, '', 0, false, 'T', 'M');
    }
   
}


