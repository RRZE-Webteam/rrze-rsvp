<?php

namespace RRZE\RSVP\Services;

defined('ABSPATH') || exit;

use RRZE\RSVP\CPT;
use RRZE\RSVP\Options;
use RRZE\RSVP\Settings;
use RRZE\RSVP\Functions;

class GeneralSettings extends Settings
{
    protected $serviceOptions;
        
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

        $this->serviceOptions = Options::getServiceOptions($this->wpTerm->term_id);
        
        $serviceName = isset($input['service_name']) ? trim($input['service_name']) : '';
        if (empty($serviceName)) {
            $this->addSettingsError('service_name', '', __('The service name is required.', 'rrze-rsvp'));
        }

        $serviceDescription = isset($input['service_description']) ? sanitize_text_field($input['service_description']) : '';
        $this->addSettingsError('service_description', $serviceDescription, '', false);

        $notificationEmail = isset($input['notification_email']) ? trim($input['notification_email']) : '';
        if (! filter_var($notificationEmail, FILTER_VALIDATE_EMAIL)) {
            $this->addSettingsError('notification_email', '', __('The email address for notifications is not valid.', 'rrze-rsvp'));
        } else {
            $this->addSettingsError('notification_email', $this->serviceOptions->notification_email, '', false);
            $this->serviceOptions->notification_email = $notificationEmail;
        }

        $weeksInAdvance = isset($input['weeks_in_advance']) ? absint($input['weeks_in_advance']) : $this->serviceOptions->weeks_in_advance;
        $this->serviceOptions->weeks_in_advance = !$weeksInAdvance ? 1 : $weeksInAdvance;
        $this->addSettingsError('weeks_in_advance', $this->serviceOptions->weeks_in_advance, '', false);

        $autoConfirmation = empty($input['auto_confirmation']) ? 0 : 1;
        $this->addSettingsError('auto_confirmation', $autoConfirmation, '', false);

        if (! $this->settingsErrors()) {
            $term = wp_update_term(
                $this->wpTerm->term_id,
                CPT::getTaxonomyServiceName(),
                [
                    'name' => $serviceName,
                    'description' => $serviceDescription
                ]
            );
            
            if (is_wp_error($term)) {
                $this->addAdminNotice(__('The service could not be updated.', 'rrze-rsvp'), 'error');
                wp_redirect(Functions::actionUrl(['page' => 'rrze-rsvp-services', 'action' => 'edit', 'item' => $this->wpTerm->term_id,  'tab' => 'general']));
                exit();
            }

            update_term_meta($this->wpTerm->term_id, Options::getServiceOptionName(), $this->serviceOptions);

        } else {
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

        $this->serviceOptions = Options::getServiceOptions($this->wpTerm->term_id);

        add_settings_section(
            'rrze-rsvp-services-edit-general-section',
            false,
            '__return_false',
            'rrze-rsvp-services-edit-general'
        );

        add_settings_field(
            'service_name',
            __('Title', 'rrze-rsvp'),
            [$this, 'serviceNameField'],
            'rrze-rsvp-services-edit-general',
            'rrze-rsvp-services-edit-general-section'
        );

        add_settings_field(
            'service_description',
            __('Description', 'rrze-rsvp'),
            [$this, 'serviceDerscriptionField'],
            'rrze-rsvp-services-edit-general',
            'rrze-rsvp-services-edit-general-section'
        );

        add_settings_field(
            'notification_email',
            __('Email address for notifications', 'rrze-rsvp'),
            [$this, 'notificationEmailField'],
            'rrze-rsvp-services-edit-general',
            'rrze-rsvp-services-edit-general-section'
        );
        
        add_settings_field(
            'weeks_in_advance',
            __('Available weeks in advance', 'rrze-rsvp'),
            [$this, 'weeksInAdvance'],
            'rrze-rsvp-services-edit-general',
            'rrze-rsvp-services-edit-general-section'
        );
        
        add_settings_field(
            'auto_confirmation',
            __('Automatic confirmation', 'rrze-rsvp'),
            [$this, 'autoConfirmation'],
            'rrze-rsvp-services-edit-general',
            'rrze-rsvp-services-edit-general-section'
        );        
    }

    public function serviceNameField()
    {   
        $settingsErrors = $this->settingsErrors();
        $title = isset($settingsErrors['service_name']['value']) ? esc_html($settingsErrors['service_name']['value']) : $this->wpTerm->name;
        ?>
        <input type="text" name="<?php printf('%s[service_name]', $this->optionName); ?>" value="<?php echo $title; ?>" id="rrze_rsvp_service_name" class="regular-text">
        <?php       
    }

    public function serviceDerscriptionField()
    {
        $settingsErrors = $this->settingsErrors();
        $description = isset($settingsErrors['service_description']['value']) ? esc_textarea($settingsErrors['service_description']['value']) : $this->wpTerm->description;
        ?>
        <textarea cols="50" rows="3" name="<?php printf('%s[service_description]', $this->optionName); ?>" id="rrze_rsvp_service_description"><?php echo $description; ?></textarea>
        <?php        
    }

    public function notificationEmailField()
    {
        $settingsErrors = $this->settingsErrors();
        $notificationEmail = isset($settingsErrors['notification_email']['value']) ? esc_html($settingsErrors['notification_email']['value']) : $this->serviceOptions->notification_email;
        ?>
        <input type="text" name="<?php printf('%s[notification_email]', $this->optionName); ?>" value="<?php echo $notificationEmail; ?>" id="rrze_rsvp_notification_email" class="regular-text">
        <p class="description"><?php _e('Notifications are sent to this email address when a new booking is made. If the field is left empty, no notifications will be sent.'); ?></p>
        <?php 
    }

    public function weeksInAdvance()
    {
        $settingsErrors = $this->settingsErrors();
        $notificationEmail = isset($settingsErrors['weeks_in_advance']['value']) ? esc_html($settingsErrors['weeks_in_advance']['value']) : $this->serviceOptions->weeks_in_advance;
        ?>
        <input type="number" name="<?php printf('%s[weeks_in_advance]', $this->optionName); ?>" value="<?php echo $notificationEmail; ?>" id="rrze_rsvp_weeks_in_advance" min="1" class="regular-text">
        <p class="description"><?php _e('The number of weeks for which bookings are availiable in advance.'); ?></p>
        <?php 
    }

    public function autoConfirmation()
    {
        $settingsErrors = $this->settingsErrors();
        $autoConfirmation = isset($settingsErrors['auto_confirmation']['value']) ? esc_html($settingsErrors['auto_confirmation']['value']) : $this->serviceOptions->auto_confirmation;
        ?>
        <input type="checkbox" name="<?php printf('%s[auto_confirmation]', $this->optionName); ?>" value="1" id="rrze_rsvp_auto_confirmation" <?php checked($autoConfirmation); ?>>
        <p class="description"><?php _e('If the automatic confirmation is not activated, the booking must be confirmed manually.'); ?></p>
        <?php
    }

}
