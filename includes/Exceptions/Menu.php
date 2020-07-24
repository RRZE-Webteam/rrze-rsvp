<?php

namespace RRZE\RSVP\Exceptions;

defined('ABSPATH') || exit;

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
            if ('rrze_rsvp_exceptions_per_page' == $option) {
                return $value;
            }
            return $status;
        }, 11, 3);        
    }

    public function add_admin_menu()
    {
        $submenuPage = add_submenu_page(
            plugin()->getSlug(), 
            __('Bookings', 'rrze-rsvp'),
            __('Exceptions', 'rrze-rsvp'),
            'manage_options',
            plugin()->getSlug() . '-exceptions',
            [$this, 'exceptionsPage']
        );

        add_action("load-$submenuPage", function() {
            $option = 'per_page';
            $args = [
                'label' => __('Number of exceptions per page:', 'rrze-rsvp'),
                'default' => 10,
                'option' => 'rrze_rsvp_exceptions_per_page'
            ];

            add_screen_option($option, $args);
        });

    }
    
    public function exceptionsPage()
    {
        $options = Options::getOptions();
        
        $search = '';
        
        $table = new ListTable(false);
        $table->prepare_items();
        ?>
        <div class="rrze-rsvp wrap">
            <?php settings_errors(); ?>
            <h1><?php _e('Exceptions', 'rrze-rsvp'); ?></h1>
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

}
