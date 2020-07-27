<?php

namespace RRZE\RSVP\Shortcodes;

defined('ABSPATH') || exit;

class Main
{
    public function __construct()
    {
        //
    }

    public function onLoaded()
    {
        $this->shortcode = new Shortcode;
        $this->shortcode->onLoaded();
    }    
}