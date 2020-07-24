<?php

namespace RRZE\RSVP\Services;

defined('ABSPATH') || exit;

use RRZE\RSVP\CPT;
use RRZE\RSVP\Settings;
use RRZE\RSVP\Functions;

class GeneralSettings extends Settings
{
    protected $optionName = 'rrze_rsvp_services';

    protected $wpTerm;

    public function __construct()
    {
        $this->settingsErrorTransient = 'rrze-rsvp-services-settings-edit-error-';
        $this->noticeTransient = 'rrze-rsvp-services-settings-edit-notice-';
    }

    public function onLoaded()
    {
        add_action('admin_init', [$this, 'validateOptions']);
        add_action('admin_init', [$this, 'settings']);
        add_action('admin_notices', [$this, 'adminNotices']);
    }

    public function validateOptions()
    {
        $optionPage = Functions::requestVar('option_page');

        if ($optionPage == 'rrze-rsvp-services-edit-general') {
            $this->validate();
        }
    }

    protected function validate()
    {
        $input = (array) Functions::requestVar($this->optionName);
        $nonce = Functions::requestVar('_wpnonce');

        if (!wp_verify_nonce($nonce, 'rrze-rsvp-services-edit-general-options')) {
            wp_die(__('Something went wrong.', 'rrze-rsvp'));
        }

        $item = absint(Functions::requestVar('item'));
        $this->wpTerm = get_term_by('id', $item, CPT::getTaxonomyServiceName());
        if ($this->wpTerm === false) {
            wp_die(__('Something went wrong.', 'rrze-rsvp'));
        }

        $title = isset($input['service_title']) ? trim($input['service_title']) : '';
        if (empty($title)) {
            $this->addSettingsError('service_title', '', __('The title is required.', 'rsvp'));
        }

        $description = isset($input['service_description']) ? sanitize_text_field($input['service_description']) : '';
        $this->addSettingsError('service_description', $description, '', false);

        if (! $this->settingsErrors()) {
            $term = wp_update_term(
                $this->wpTerm->term_id,
                CPT::getTaxonomyServiceName(),
                [
                    'name' => $title,
                    'description' => $description
                ]
            );
            
            if (is_wp_error($term)) {
                $this->addAdminNotice(__('The service could not be updated.', 'rrze-rsvp'), 'error');
                wp_redirect(Functions::actionUrl(['page' => 'rrze-rsvp-services', 'action' => 'edit', 'item' => $this->wpTerm->term_id,  'tab' => 'general']));
                exit();
            }
        }  

        if ($this->settingsErrors()) {
            foreach ($this->settingsErrors() as $error) {
                if ($error['message']) {
                    $this->addAdminNotice($error['message'], 'error');
                }
            }
            wp_redirect(Functions::actionUrl(['page' => 'rrze-rsvp-services', 'action' => 'edit', 'item' => $this->wpTerm->term_id,  'tab' => 'general']));
            exit();
        }
        
        $this->addAdminNotice(__('The service has been updated.', 'rrze-rspv'));
        wp_redirect(Functions::actionUrl(['page' => 'rrze-rsvp-services', 'action' => 'edit', 'item' => $this->wpTerm->term_id,  'tab' => 'general']));
        exit();        
    }

    public function settings()
    {
        $item = absint(Functions::requestVar('item'));
        $this->wpTerm = get_term_by('id', $item, CPT::getTaxonomyServiceName());
        if ($this->wpTerm === false) {
            return;
        }

        add_settings_section(
            'rrze-rsvp-services-edit-general-section',
            false,
            '__return_false',
            'rrze-rsvp-services-edit-general'
        );

        add_settings_field(
            'service_title',
            __('Title', 'rrze-ac'),
            [$this, 'serviceTitleField'],
            'rrze-rsvp-services-edit-general',
            'rrze-rsvp-services-edit-general-section'
        );

        add_settings_field(
            'service_description',
            __('Description', 'rrze-ac'),
            [$this, 'serviceDerscriptionField'],
            'rrze-rsvp-services-edit-general',
            'rrze-rsvp-services-edit-general-section'
        );  
    }

    public function serviceTitleField()
    {   $settingsErrors = $this->settingsErrors();
        $title = isset($settingsErrors['service_title']['value']) ? esc_html($settingsErrors['service_title']['value']) : $this->wpTerm->name;
        ?>
        <input type="text" value="<?php echo $title; ?>" name="<?php printf('%s[service_title]', $this->optionName); ?>" class="regular-text">
        <?php       
    }

    public function serviceDerscriptionField()
    {
        $settingsErrors = $this->settingsErrors();
        $description = isset($settingsErrors['service_description']['value']) ? esc_textarea($settingsErrors['service_description']['value']) : $this->wpTerm->description;
        ?>
        <textarea id="description" cols="50" rows="3" name="<?php printf('%s[service_description]', $this->optionName); ?>"><?php echo $description; ?></textarea>
        <?php        
    }

}
