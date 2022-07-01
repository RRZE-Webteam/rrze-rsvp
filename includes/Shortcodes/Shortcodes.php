<?php

namespace RRZE\RSVP\Shortcodes;

defined('ABSPATH') || exit;

/**
 * Laden und definieren der Shortcodes
 */
class Shortcodes
{
    public function shortcodeAtts($atts, $tag, $settings)
    {
        // merge given attributes with default ones
        $defaultAtts = [];
        foreach ($settings as $tagname => $settings) {
            foreach ($settings as $k => $v) {
                if ($k != 'block') {
                    $defaultAtts[$tagname][$k] = $v['default'];
                }
            }
        }
        return shortcode_atts($defaultAtts[$tag], $atts);
    }
}
