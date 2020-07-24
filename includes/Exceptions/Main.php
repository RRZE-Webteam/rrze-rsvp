<?php

namespace RRZE\RSVP\Exceptions;

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