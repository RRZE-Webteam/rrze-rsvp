<?php

namespace RRZE\RSVP\Seats;

defined('ABSPATH') || exit;

use RRZE\RSVP\CPT;
use RRZE\RSVP\Functions;

use function RRZE\RSVP\plugin;

class Menu
{   
    protected $newSettings;

    protected $editSettings;

    public function __construct()
    {
        //
    }

    public function onLoaded()
    {
        $this->newSettings = new NewSettings;
        $this->newSettings->onLoaded();

        $this->editSettings = new EditSettings;
        $this->editSettings->onLoaded();

        add_action('admin_menu', [$this, 'adminMenu']);

        add_filter('set-screen-option', function ($status, $option, $value) {
            if ('rrze_rsvp_seats_per_page' == $option) {
                return $value;
            }
            return $status;
        }, 11, 3);
    }

    public function adminMenu()
    {
        $submenuPage = add_submenu_page(
            plugin()->getSlug(),
            __('Bookings', 'rrze-rsvp'),
            __('Seats', 'rrze-rsvp'),
            'manage_options',
            plugin()->getSlug() . '-seats',
            [$this, 'menuPage']
        );

        add_action("load-$submenuPage", function () {
            $option = 'per_page';
            $args = [
                'label' => __('Number of seats per page:', 'rrze-rsvp'),
                'default' => 10,
                'option' => 'rrze_rsvp_seats_per_page'
            ];

            add_screen_option($option, $args);
        });
    }

    public function menuPage()
    {
        $action = Functions::requestVar('action');
        ?>
        <div class="rrze-rsvp wrap">           
            <?php
            if ($action == 'new') {
                $this->newPage();
            } elseif ($action == 'edit') {
                $this->editPage();
            } else {
                $this->defaultPage();
            } ?>
        </div>
        <?php
        $this->newSettings->deleteSettingsErrors();
        $this->editSettings->deleteSettingsErrors();
    }

    protected function newPage()
    {        
        ?>
        <h2><?php echo esc_html(__('Add New Seat', 'rrze-rsvp')); ?></h2>
        <form action="<?php echo Functions::actionUrl(['page' => 'rrze-rsvp-seats', 'action' => 'new']); ?>" method="post">
            <?php
            settings_fields('rrze-rsvp-seats-new');
            do_settings_sections('rrze-rsvp-seats-new');
            submit_button(__('Add New Seat', 'rrze-rsvp')); ?>
        </form>
        <?php
    }

    protected function editPage()
    {   
        $item = absint(Functions::requestVar('item'));
        $wpPost = get_post($item);

        if (is_null($wpPost)) {
            wp_die(__('Something went wrong.', 'rrze-rsvp'));
        }

        $postId = $wpPost->ID; 
        ?>
        <h1><?php echo esc_html(__('Edit Seat', 'rrze-rsvp')); ?></h1>
        <form action="<?php echo Functions::actionUrl(['page' => 'rrze-rsvp-seats', 'action' => 'edit', 'item' => $postId]); ?>" method="post">
            <?php
            settings_fields('rrze-rsvp-seats-edit');
            do_settings_sections('rrze-rsvp-seats-edit');
            submit_button(__('Save Changes', 'rrze-rsvp')); ?>
        </form>
        <?php
    }

    protected function defaultPage()
    {
        $search = '';
        $table = new ListTable();
        $table->prepare_items();
        ?>
        <h1>
            <?php _e('Seats', 'rrze-rsvp'); ?>
            <a href="<?php echo Functions::actionUrl(['page' => 'rrze-rsvp-seats', 'action' => 'new']); ?>" class="add-new-h2"><?php _e("Add New", 'rrze-rsvp'); ?></a>
        </h1>         
        <form method="get">
            <input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>">
            <?php $table->search_box(__('Search'), 's'); ?>
        </form>

        <form method="get">
            <input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>">
            <input type="hidden" name="s" value="<?php echo $search ?>">
            <?php $table->display(); ?>
        </form>
        <?php
    }
}
