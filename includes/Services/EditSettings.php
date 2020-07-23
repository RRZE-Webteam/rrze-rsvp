<?php

namespace RRZE\RSVP\Services;

defined('ABSPATH') || exit;

use RRZE\RSVP\CPT;
use RRZE\RSVP\Settings;
use RRZE\RSVP\Functions;

class EditSettings extends Settings
{
    protected $optionName = 'rrze_rsvp_services';

    public function __construct()
    {
        $this->settingsErrorTransient = 'rrze-rsvp-services-settings-edit-error-';
        $this->noticeTransient = 'rrze-rsvp-services-settings-edit-notice-';
    }

    public function onLoaded()
    {
        add_action('admin_init', [$this, 'validateOptions']);
        add_action('admin_init', [$this, 'newSettings']);
        add_action('admin_notices', [$this, 'adminNotices']);
    }

    public function validateOptions()
    {
        $optionPage = Functions::requestVar('option_page');

        if ($optionPage == 'rrze-rsvp-services-edit') {
            $this->validateEdit();
        }
    }

    protected function validateEdit()
    {

    }

    public function editSettings()
    {
        add_settings_section(
            'rrze-rsvp-services-edit-section',
            false,
            '__return_false',
            'rrze-rsvp-services-edit'
        );

        add_settings_field(
            'service_title',
            __('Title', 'rrze-ac'),
            [$this, 'serviceTitleField'],
            'rrze-rsvp-services-new',
            'rrze-rsvp-services-new-section'
        );

        add_settings_field(
            'service_description',
            __('Description', 'rrze-ac'),
            [$this, 'serviceDerscriptionField'],
            'rrze-rsvp-services-new',
            'rrze-rsvp-services-new-section'
        );  
    }

    public function serviceTitleField()
    {   $settingsErrors = $this->settingsErrors();
        $title = isset($settingsErrors['service_title']['value']) ? esc_html($settingsErrors['service_title']['value']) : '';
        ?>
        <input type="text" value="<?php echo $title; ?>" name="<?php printf('%s[service_title]', $this->optionName); ?>" class="regular-text">
        <?php       
    }

    public function serviceDerscriptionField()
    {
        $settingsErrors = $this->settingsErrors();
        $description = isset($settingsErrors['service_description']['value']) ? esc_textarea($settingsErrors['service_description']['value']) : '';
        ?>
        <textarea id="description" cols="50" rows="3" name="<?php printf('%s[service_description]', $this->optionName); ?>"><?php echo $description; ?></textarea>
        <?php        
    }

}
