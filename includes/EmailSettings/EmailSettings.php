<?php

namespace RRZE\RSVP\EmailSettings;

defined('ABSPATH') || exit;

class EmailSettings {
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