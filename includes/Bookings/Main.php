<?php

namespace RRZE\RSVP\Bookings;

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