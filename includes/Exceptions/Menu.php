<?php

namespace RRZE\RSVP\Exceptions;

defined('ABSPATH') || exit;

use RRZE\RSVP\Functions;

use function RRZE\RSVP\plugin;

class Menu
{   
    protected $newSettings;

    public function __construct()
    {
        //
    }

    public function onLoaded()
    {
        $this->newSettings = new NewSettings;
        $this->newSettings->onLoaded();

        add_action('admin_menu', [$this, 'adminMenu']);

        add_filter('set-screen-option', function ($status, $option, $value) {
            if ('rrze_rsvp_exceptions_per_page' == $option) {
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
            __('Exceptions', 'rrze-rsvp'),
            'manage_options',
            plugin()->getSlug() . '-exceptions',
            [$this, 'menuPage']
        );

        add_action("load-$submenuPage", function () {
            $option = 'per_page';
            $args = [
                'label' => __('Number of exceptions per page:', 'rrze-rsvp'),
                'default' => 10,
                'option' => 'rrze_rsvp_exceptions_per_page'
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
            } else {
                $this->defaultPage();
            } ?>
        </div>
        <?php
        $this->newSettings->deleteSettingsErrors();
    }

    protected function newPage()
    {        
        ?>
        <h2><?php echo esc_html(__('Add New Exception', 'rrze-rsvp')); ?></h2>
        <form action="<?php echo Functions::actionUrl(['page' => 'rrze-rsvp-exceptions', 'action' => 'new']); ?>" method="post">
            <?php
            settings_fields('rrze-rsvp-exceptions-new');
            do_settings_sections('rrze-rsvp-exceptions-new');
            submit_button(__('Add New Exception', 'rrze-rsvp')); ?>
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
            <?php _e('Exceptions', 'rrze-rsvp'); ?>
            <a href="<?php echo Functions::actionUrl(['page' => 'rrze-rsvp-exceptions', 'action' => 'new']); ?>" class="add-new-h2"><?php _e('Add New', 'rrze-rsvp'); ?></a>
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
