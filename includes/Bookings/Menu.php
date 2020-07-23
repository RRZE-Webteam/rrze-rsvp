<?php

namespace RRZE\RSVP\Bookings;

defined('ABSPATH') || exit;

use WP_Query;

use RRZE\RSVP\CPT;
use RRZE\RSVP\Options;
use function RRZE\RSVP\plugin;

/**
 * [Main description]
 */
class Menu
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
        add_action('admin_menu', [$this, 'add_admin_menu']);

        add_filter('set-screen-option', function($status, $option, $value) {
            if ('rrze_rsvp_bookings_per_page' == $option) {
                return $value;
            }
            return $status;
        }, 11, 3);        
    }

    public function add_admin_menu()
    {
        // Bookings menu
        $menuPage = add_menu_page(
            __('Bookings', 'rrze-rsvp'),
            __('Bookings', 'rrze-rsvp') . $this->menuNotice(),
            'manage_options',
            plugin()->getSlug(),
            [$this, 'bookingsPage'],
            'dashicons-calendar-alt',
            '18'
        );

        add_action("load-$menuPage", function() {
            $args = [
                'label' => __('Number of bookings per page:', 'rrze-rsvp'),
                'default' => 10,
                'option' => 'rrze_rsvp_bookings_per_page'
            ];

            add_screen_option('per_page', $args);
        });

        // Archive menu
        $submenuPage = add_submenu_page(
            plugin()->getSlug(), 
            __('Bookings', 'rrze-rsvp'),
            __('Archive', 'rrze-rsvp'),
            'manage_options',
            plugin()->getSlug() . '-archive',
            [$this, 'archivePage']
        );

        add_action("load-$submenuPage", function() {
            $option = 'per_page';
            $args = [
                'label' => __('Number of bookings per page:', 'rrze-rsvp'),
                'default' => 10,
                'option' => 'rrze_rsvp_bookings_per_page'
            ];

            add_screen_option($option, $args);
        });
    }
    
    public function bookingsPage()
    {
        $options = Options::getOptions();
        
        $search = '';
        
        $table = new ListTable(false);
        $table->prepare_items();
        ?>
        <div class="rrze-rsvp wrap">
            <?php settings_errors(); ?>
            <h1><?php _e('Bookings', 'rrze-rsvp'); ?></h1>
            <form method="get">
                <input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>">
                <?php $table->search_box(__('Search'), 's'); ?>
            </form>
        
            <form method="get">
                <input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>">
                <input type="hidden" name="s" value="<?php echo $search ?>">
                <?php $table->display(); ?>
            </form>
        </div>
        <?php        
    }

    public function archivePage()
    {
        $options = Options::getOptions();
        
        $search = '';
        
        $table = new ListTable(true);
        $table->prepare_items();
        ?>
        <div class="rrze-rsvp wrap">
            <?php settings_errors(); ?>
            <h1><?php _e('Archive', 'rrze-rsvp'); ?></h1>
            <form method="get">
                <input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>">
                <?php $table->search_box(__('Search'), 's'); ?>
            </form>
        
            <form method="get">
                <input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>">
                <input type="hidden" name="s" value="<?php echo $search ?>">
                <?php $table->display(); ?>
            </form>
        </div>
        <?php        
    }

    private function menuNotice()
    {
        $title = __('New Booking', 'rrze-rsvp');

        $args = [
            'post_type' => CPT::getCptBookingName(),
            'post_status' => 'publish',
            'nopaging' => true,
            'meta_query' => [
                'relation' => 'AND',
                'status_clause' => [
                    'key' => 'rrze_rsvp_status',
                    'value' => 'confirmed',
                    'compare' => '='
                ],
                'status_start' => [
                    'key' => 'rrze_rsvp_start',
                    'value' => current_time('mysql'),
                    'compare' => '>='
                ]
            ]
        ];

        $query = new WP_Query();
        $posts = $query->query($args);            
        $count = count($posts);

        if ($count > 0) {
            return " <span class='update-plugins count-$count' title='$title'><span class='update-count'>" . number_format_i18n($count) . "</span></span>";
        } else {
            return '';
        }
    }    
}
