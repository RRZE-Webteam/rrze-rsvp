<?php
/** Delegate the metadata-registered dynamic block to the shared booking adapter. */
defined('ABSPATH') || exit;
echo \RRZE\RSVP\Blocks\BookingBlock::render($attributes);
