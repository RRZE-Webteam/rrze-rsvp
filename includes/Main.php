<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

/**
 * [Main description]
 */
class Main
{
    /**
     * [__construct description]
     */
    public function __construct()
    {
        //
    }

    public function onLoaded()
    {
        $cpt = new CPT;
        $cpt->onLoaded();
    }
}
