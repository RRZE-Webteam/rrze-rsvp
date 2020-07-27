<?php

namespace RRZE\RSVP\Services;

defined('ABSPATH') || exit;

use RRZE\RSVP\CPT;
use RRZE\RSVP\Functions;

use function RRZE\RSVP\plugin;

class Menu
{   
    protected $newSettings;

    protected $generalSettings;

    public function __construct()
    {
        //
    }

    public function onLoaded()
    {
        $this->newSettings = new NewSettings;
        $this->newSettings->onLoaded();

        $this->generalSettings = new GeneralSettings;
        $this->generalSettings->onLoaded();

        $this->timeslotsSettings = new TimeslotsSettings;
        $this->timeslotsSettings->onLoaded();

        $this->emailSettings = new EmailSettings;
        $this->emailSettings->onLoaded();

        add_action('admin_menu', [$this, 'adminMenu']);

        add_filter('set-screen-option', function ($status, $option, $value) {
            if ('rrze_rsvp_services_per_page' == $option) {
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
            __('Services', 'rrze-rsvp'),
            'manage_options',
            plugin()->getSlug() . '-services',
            [$this, 'menuPage']
        );

        add_action("load-$submenuPage", function () {
            $option = 'per_page';
            $args = [
                'label' => __('Number of services per page:', 'rrze-rsvp'),
                'default' => 10,
                'option' => 'rrze_rsvp_services_per_page'
            ];

            add_screen_option($option, $args);
        });
    }

    public function menuPage()
    {
        $action = Functions::requestVar('action');
        $OptionPage = Functions::requestVar('option_page');
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
        $this->generalSettings->deleteSettingsErrors();
        $this->timeslotsSettings->deleteSettingsErrors();
        $this->emailSettings->deleteSettingsErrors();
    }

    protected function newPage()
    {
        ?>
        <h2><?php echo esc_html(__('Add New Service', 'rrze-rsvp')); ?></h2>
        <form action="<?php echo Functions::actionUrl(['page' => 'rrze-rsvp-services', 'action' => 'new']); ?>" method="post">
            <?php
            settings_fields('rrze-rsvp-services-new');
            do_settings_sections('rrze-rsvp-services-new');
            submit_button(__('Add New Service', 'rrze-rsvp')); ?>
        </form>
        <?php
    }

    protected function editPage()
    {   
        $item = absint(Functions::requestVar('item'));
        $wpTerm = get_term_by('id', $item, CPT::getTaxonomyServiceName());

        if ($wpTerm === false) {
            wp_die(__('Something went wrong.', 'rrze-rsvp'));
        }

        $termId = $wpTerm->term_id;

        $sections = [
            'general' => __('General', 'rrze-rsvp'),
            'timeslots' => __('Timeslots', 'rrze-rsvp'),
            'email' => __('Email', 'rrze-rsvp')
        ];
        $activeTab = Functions::requestVar('tab');
        if (!in_array($activeTab, array_keys($sections))) {
            $activeTab = 'general';
        }
        ?>
        <h1><?php echo esc_html(__('Edit Service', 'rrze-rsvp')); ?></h1>
        <h2 class="nav-tab-wrapper">
            <?php 
            foreach ($sections as $tab => $tabName) {
                $activeClass= ($activeTab == $tab) ? ' nav-tab-active' : '';
                $href = Functions::actionUrl(['page' => 'rrze-rsvp-services', 'action' => 'edit', 'item' => $termId,  'tab' => $tab]);
                printf('<a href="%s" class="nav-tab%s">%s</a>', $href, $activeClass, $tabName);
            }
            ?>
        </h2> 
        <form action="<?php echo Functions::actionUrl(['page' => 'rrze-rsvp-services', 'action' => 'edit', 'item' => $termId,  'tab' => $activeTab]); ?>" method="post">
            <?php
            $optionGroup = 'rrze-rsvp-services-edit-' . $activeTab;
            settings_fields($optionGroup);
            do_settings_sections($optionGroup);
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
            <?php _e('Services', 'rrze-rsvp'); ?>
            <a href="<?php echo Functions::actionUrl(['page' => 'rrze-rsvp-services', 'action' => 'new']); ?>" class="add-new-h2"><?php _e("Add New", 'rrze-rsvp'); ?></a>
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
