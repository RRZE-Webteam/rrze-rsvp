<?php

namespace RRZE\RSVP\Seats;

defined('ABSPATH') || exit;

class Main
{
    public function __construct()
    {
        //
    }

    public function onLoaded()
    {
        $this->menu = new Menu;
        $this->menu->onLoaded();
    }    
}