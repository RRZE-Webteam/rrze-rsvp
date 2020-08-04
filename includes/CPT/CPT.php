<?php

namespace RRZE\RSVP\CPT;

defined('ABSPATH') || exit;

use RRZE\RSVP\Main;
use RRZE\RSVP\Capabilities;

/**
 * Laden und definieren der Posttypes
 */
class CPT extends Main
{
    protected $pluginFile;
    protected $settings;

    public function __construct($pluginFile, $settings)
    {
        $this->pluginFile = $pluginFile;
        $this->settings = $settings;
    }

    public function onLoaded() {
        $bookings = new Bookings($this->pluginFile, $this->settings);
        $bookings->onLoaded();

        $rooms = new Rooms($this->pluginFile, $this->settings);
        $rooms->onLoaded();

        $seats = new Seats($this->pluginFile, $this->settings);
        $seats->onLoaded();

        add_action('admin_menu', [$this, 'bookingMenu']);
        add_filter('parent_file', [$this, 'filterParentMenu']);
    }

    public function bookingMenu()
    {
        $cpts = array_keys(Capabilities::getCurrentCptArgs());
        $hiddenTitle = 'rrze-rsvp-submenu-hidden';

        foreach ($cpts as $cpt) {
            $cpt_obj = get_post_type_object($cpt);
            add_submenu_page(
                'edit.php?post_type=booking',      // parent slug
                $cpt_obj->labels->name,            // page title
                $cpt_obj->labels->menu_name,       // menu title
                $cpt_obj->cap->edit_posts,         // capability
                'edit.php?post_type=' . $cpt       // menu slug
            );

            add_submenu_page(
                'edit.php?post_type=booking',
                $cpt_obj->labels->name,
                $hiddenTitle,
                $cpt_obj->cap->edit_posts,
                'post-new.php?post_type=' . $cpt
            );
        }

        add_submenu_page(
            'edit.php?post_type=booking',
            __('Equipment', 'rrze-rsvp'),
            __('Equipment', 'rrze-rsvp'),
            'edit_seats',
            'edit-tags.php?taxonomy=rrze-rsvp-equipment&post_type=seat'
        );

        remove_submenu_page('edit.php?post_type=booking', 'edit.php?post_type=booking');
        remove_submenu_page('edit.php?post_type=booking', 'post-new.php?post_type=booking');

        global $submenu;
        $hiddenClass = $hiddenTitle;
        if (isset($submenu['edit.php?post_type=booking'])) {
            foreach ($submenu['edit.php?post_type=booking'] as $key => $menu) {
                if ($menu[0] == $hiddenTitle) {
                    $submenu['edit.php?post_type=booking'][$key][4] = $hiddenClass;
                }
            }
        }
    }

    public function filterParentMenu($parent_file)
    {
        global $submenu_file, $current_screen, $pagenow;

        $cpts = array_keys(Capabilities::getCurrentCptArgs());

        foreach ($cpts as $cpt) {
            if ($current_screen->post_type == $cpt) {

                if ($pagenow == 'post.php') {
                    $submenu_file = 'edit.php?post_type=' . $current_screen->post_type;
                }

                if ($pagenow == 'post-new.php') {
                    $submenu_file = 'edit.php?post_type=' . $current_screen->post_type;
                }

                $parent_file = 'edit.php?post_type=booking';
            }
        }

        if ($current_screen->post_type == 'seat') {
            if ($pagenow == 'edit-tags.php') {
                $submenu_file = 'edit-tags.php?taxonomy=rrze-rsvp-equipment&post_type=seat';
            }

            if ($pagenow == 'term.php') {
                $submenu_file = 'edit-tags.php?taxonomy=rrze-rsvp-equipment&post_type=seat';
            }

            $parent_file = 'edit.php?post_type=booking';
        }

        return $parent_file;
    }
}
