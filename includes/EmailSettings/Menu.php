<?php

namespace RRZE\RSVP\EmailSettings;

defined('ABSPATH') || exit;

use RRZE\RSVP\Functions;

use function RRZE\RSVP\plugin;

class Menu {   
    protected $emailSettings;

    public function __construct()
    {
        //
    }

    public function onLoaded()
    {
        $this->emailSettings = new EmailSettings;
        $this->emailSettings->onLoaded();

        add_action('admin_menu', [$this, 'adminMenu']);
    }

    public function adminMenu()
    {
        $submenuPage = add_submenu_page(
            plugin()->getSlug(),
            __('Bookings', 'rrze-rsvp'),
            __('Settings', 'rrze-rsvp'),
            'manage_options',
            plugin()->getSlug() . '-settings',
            [$this, 'menuPage']
        );
    }

    public function menuPage()
    {
        $action = Functions::requestVar('action');
        $OptionPage = Functions::requestVar('option_page');
        ?>
        <div class="rrze-rsvp wrap">           
            <?php $this->settingsPage();?>
        </div>
        <?php
        $this->emailSettings->deleteSettingsErrors();
    }

    protected function settingsPage()
    {   
        $sections = [
            'email' => __('Email', 'rrze-rsvp')
        ];
        $activeTab = Functions::requestVar('tab');
        if (!in_array($activeTab, array_keys($sections))) {
            $activeTab = 'email';
        }
        ?>
        <h1><?php echo esc_html(__('Settings', 'rrze-rsvp')); ?></h1>
        <h2 class="nav-tab-wrapper">
            <?php 
            foreach ($sections as $tab => $tabName) {
                $activeClass= ($activeTab == $tab) ? ' nav-tab-active' : '';
                $href = Functions::actionUrl(['page' => 'rrze-rsvp-services', 'action' => 'edit', 'tab' => $tab]);
                printf('<a href="%s" class="nav-tab%s">%s</a>', $href, $activeClass, $tabName);
            }
            ?>
        </h2> 
        <form action="<?php echo Functions::actionUrl(['page' => 'rrze-rsvp-services', 'action' => 'edit', 'tab' => $activeTab]); ?>" method="post">
            <?php
            $optionGroup = 'rrze-rsvp-settings-' . $activeTab;
            settings_fields($optionGroup);
            do_settings_sections($optionGroup);
            submit_button(__('Save Changes', 'rrze-rsvp')); ?>
        </form>
        <?php
    }
}
