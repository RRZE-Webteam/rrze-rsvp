<?php

namespace RRZE\RSVP\CPT;

defined('ABSPATH') || exit;

use RRZE\RSVP\Main;
use RRZE\RSVP\Capabilities;
use RRZE\RSVP\Functions;

/**
 * Laden und definieren der Posttypes
 */
class CPT extends Main
{
    public function __construct()
    {
        //
    }

    public function onLoaded()
    {
        $bookings = new Bookings;
        $bookings->onLoaded();

        $rooms = new Rooms;
        $rooms->onLoaded();

        $seats = new Seats;
        $seats->onLoaded();

        add_action('admin_menu', [$this, 'bookingMenu']);
        add_filter('parent_file', [$this, 'filterParentMenu']);
        add_action('pre_get_posts', [$this, 'archiveShowAllRooms']);

        // Prüfung: gibt es Buchung zu Raum oder Platz 
        // add_action('add_meta_boxes', [$this, 'customSubmitdiv']);


        add_action('add_meta_boxes', [$this, 'shortcodeHelper']);

        if (isset($_GET['format']) && $_GET['format'] == 'embedded') {
            add_filter(
                'body_class',
                function ($classes) {
                    return array_merge($classes, array('embedded'));
                }
            );
        }
    }

    public function activation()
    {
        $bookings = new Bookings;
        $bookings->booking_post_type();

        $rooms = new Rooms;
        $rooms->room_post_type();

        $seats = new Seats;
        $seats->seats_post_type();
        $seats->seats_taxonomies();
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
            __('Room occupancy for today', 'rrze-rsvp'),
            __('Room occupancy', 'rrze-rsvp'),
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

    public function getOccupancyPage()
    {
        echo '<div class="wrap">'
            . '<h1>' . esc_html_x('Room occupancy for today', 'admin page title', 'rrze-rsvp') . '</h1>'

            . '<div class="tablenav top">'
            . '<div class="alignleft actions bulkactions">'
            . '<label for="select_room" class="screen-reader-text">' . __('Room', 'rrze-rsvp') . '</label>'
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

    public function customSubmitdiv()
    {

        // um zu verhindern, dass der Admin den Status des Posts ändert oder schlimmer noch, ihn versehentlich löscht. 

        remove_meta_box('submitdiv', 'booking', 'core');
        add_meta_box('submitdiv', __('Publish'), [$this, 'addCustomSubmitdiv'], 'booking', 'side', 'high');
        remove_meta_box('submitdiv', 'room', 'core');
        add_meta_box('submitdiv', __('Publish'), [$this, 'addCustomSubmitdiv'], 'room', 'side', 'high');          
        remove_meta_box('submitdiv', 'seat', 'core');
        add_meta_box('submitdiv', __('Publish'), [$this, 'addCustomSubmitdiv'], 'seat', 'side', 'high');
    }

    public function addCustomSubmitdiv()
    {
        global $post;
        $postType = $post->post_type;
        $postTypeObject = get_post_type_object($postType);
        $canPublish = current_user_can($postTypeObject->cap->publish_posts);
        $canDelete = Functions::canDeletePost($post->ID, $postType);
        ?>
        <div class="submitbox" id="submitpost">
            <div id="major-publishing-actions">
                <?php
                do_action('post_submitbox_start');
                ?>
                <div id="delete-action">
                <?php
                if ($canDelete && current_user_can('delete_post', $post->ID)) {
                    if (!EMPTY_TRASH_DAYS)
                        $delete_text = __('Delete Permanently');
                    else
                        $delete_text = __('Move to Trash');
                    ?>
                    <a class="submitdelete deletion" href="<?php echo get_delete_post_link($post->ID); ?>"><?php echo $delete_text; ?></a>
                    <?php
                }
                ?>
                </div>
                <div id="publishing-action">
                    <span class="spinner"></span>
                    <?php
                    if (!in_array($post->post_status, array('publish', 'future', 'private')) || 0 == $post->ID) {
                        if ($canPublish) : ?>
                            <input name="original_publish" type="hidden" id="original_publish" value="<?php esc_attr_e('Add Tab') ?>" />
                            <?php submit_button(__('Publish'), 'primary button-large', 'publish', false); ?>
                        <?php
                        endif;
                    } else { ?>
                        <input name="original_publish" type="hidden" id="original_publish" value="<?php esc_attr_e('Update'); ?>" />
                        <input name="save" type="submit" class="button button-primary button-large" id="publish" value="<?php esc_attr_e('Update'); ?>">
                    <?php
                    }
                    ?>
                </div>
                <div class="clear"></div>
            </div>
        </div>
        <?php
    }

    public function shortcodeHelper()
    {
        add_meta_box('rrze-rsvp-room-shortcode-helper', esc_html__('Shortcode', 'rrze-rsvp'), [$this, 'shortcodeHelperCallback'], 'room', 'side', 'high');
    }

    public function shortcodeHelperCallback()
    {
        printf('<p class="description">%s</p>', __('To add a booking form for this room, add the following shortcode to a page:', 'rrze-rsvp'));
        printf('<p><code>[rsvp-booking room="%s" sso="true"]</code></p>', get_the_ID());
        printf('<p>%s</p>', __('Skip <code>sso="true"</code> to deactivate SSO authentication.', 'rrze-rsvp'));
        printf('<p>%s</p>', __('Add <code>days="20"</code> to overwrite the number of days you can book a seat in advance.', 'rrze-rsvp'));
    }

    public function archiveShowAllRooms($query) {
        if ( ! is_admin() && $query->is_main_query() ) {
            if ( is_post_type_archive( 'room' ) ) {
                $query->set('posts_per_page', -1 );
            }
        }
    }
}
