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

        // Print QR 
		add_filter('bulk_actions-edit-room', [$this, 'add_bulk_actions']);
		add_filter('bulk_actions-edit-seat', [$this, 'add_bulk_actions']);
		add_filter('handle_bulk_actions-edit-room', [$this, 'bulk_print_qr'], 10, 3);
		add_filter('handle_bulk_actions-edit-seat', [$this, 'bulk_print_qr'], 10, 3);
		add_action('admin_notices', [$this, 'bulk_action_admin_notice']);
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

    public function add_bulk_actions($bulk_actions) {
        $bulk_actions['print-qr'] = __( 'Print QR code', 'rrze-rsvp');
        return $bulk_actions;
    }

    public function bulk_print_qr( $redirect_to, $doaction, $post_ids ) {
        if ( $doaction !== 'print-qr' ) {
            return $redirect_to;
        }

        $aSeats = array();

        $currentScreen = get_current_screen();
        $postType = $currentScreen->post_type;

        if ($postType == 'room'){
            // get seats for each selected room
            foreach ( $post_ids as $post_id ){
                $seat_ids = get_posts([
                    'meta_key'   => 'rrze-rsvp-seat-room',
                    'meta_value' => $post_id,
                    'post_type' => 'seat',
                    'fields' => 'ids',
                    'orderby' => 'post_title',
                    'order' => 'ASC',
                    'numberposts' => -1
                ]);
                array_push($aSeats, $seat_ids);
            }
        } else {
            $aSeats = $post_ids;
        }

        // 2DO: Generate 1 PDF including all QR-pngs
        foreach ( $aSeats as $post_id ) {
            // generate QR for each seat
            // QRcode::png($_GET['url'].'#'.$_GET['collapse']);
          

		}

        $redirect_to = add_query_arg( 'bulk_print_qr_count', count($aSeats), $redirect_to );
        return $redirect_to;
    }

 
    public function bulk_action_admin_notice() {
        if ( ! empty( $_REQUEST['bulk_print_qr_count'] ) ) {
            $printed_count = intval( $_REQUEST['bulk_print_qr_count'] );
            printf( '<div id="message" class="updated fade">' .
            _n( 'Printed QR for %s seat.',
                'Printed QR for %s seats.',
                $printed_count,
                'rrze-rsvp'
            ) . '</div>', $printed_count );
        }
    }
}
