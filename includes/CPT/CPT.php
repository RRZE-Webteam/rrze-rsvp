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
    public $occupancy;

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

        if (isset($_GET['format']) && $_GET['format'] == 'embedded') {
            add_filter(
                'body_class',
                function ($classes) {
                    return array_merge($classes, array('embedded'));
                }
            );
        }
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

        add_submenu_page(
            'edit.php?post_type=booking',
            __( 'Room occupancy for today', 'rrze-rsvp' ),
            __( 'Room occupancy', 'rrze-rsvp' ),
            'edit_seats',
            'occupancy',
            [$this, 'getOccupancyPage']
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

    public function getOccupancyPage(){
        echo '<div class="wrap">'
            . '<h1>' . esc_html_x( 'Room occupancy for today', 'admin page title', 'rrze-rsvp' ) . '</h1>'

            . '<div class="tablenav top">'
            . '<div class="alignleft actions bulkactions">'
            . '<label for="select_room" class="screen-reader-text">' . __('Room','rrze-rsvp') . '</label>'
            . '<form action="" method="post" class="occupancy">'
            . '<select id="rsvp_room_id" name="rsvp_room_id">'
            . '<option>&mdash; ' . __('Please select room', 'rrze-rsvp') . ' &mdash;</option>';

        $rooms = get_posts([
            'post_type' => 'room',
            'post_statue' => 'publish',
            'nopaging' => true,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        foreach ($rooms as $room) {
            echo '<option value="' . $room->ID . '">' . $room->post_title . '</option>';
        }
            echo '</select></form>'
            . '<div id="loading"><i class="fa fa-refresh fa-spin fa-4x"></i></div>'
            . '</div>'
            . '<div class="rsvp-occupancy-links"></div>' 
            . '<div class="rsvp-occupancy-container"></div>' 
            . '</div>'; 
     }

}
