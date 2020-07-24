<?php

namespace RRZE\RSVP\Seats;

defined('ABSPATH') || exit;

use RRZE\RSVP\CPT;
use RRZE\RSVP\Settings;
use RRZE\RSVP\Functions;

class EditSettings extends Settings
{
    protected $optionName = 'rrze_rsvp_seats';

    protected $wpPost;

    protected $wpTerms;

    public function __construct()
    {
        $this->settingsErrorTransient = 'rrze-rsvp-seats-settings-edit-error-';
        $this->noticeTransient = 'rrze-rsvp-seats-settings-edit-notice-';
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

        if ($optionPage == 'rrze-rsvp-seats-edit') {
            $this->validate();
        }
    }

    protected function validate()
    {
        $input = (array) Functions::requestVar($this->optionName);
        $nonce = Functions::requestVar('_wpnonce');

        if (!wp_verify_nonce($nonce, 'rrze-rsvp-seats-edit-options')) {
            wp_die(__('Something went wrong.', 'rrze-rsvp'));
        }

        $item = absint(Functions::requestVar('item'));
        $this->wpPost = get_post($item);
        if (is_null($this->wpPost)) {
            wp_die(__('Something went wrong.', 'rrze-rsvp'));
        }
        $this->wpTerms = wp_get_post_terms($this->wpPost->ID, CPT::getTaxonomyServiceName());
        if (is_wp_error($this->wpTerms)) {
            wp_die(__('Something went wrong.', 'rrze-rsvp'));
        }

        $number = isset($input['seat_number']) ? trim($input['seat_number']) : '';
        if (empty($number)) {
            $this->addSettingsError('seat_number', '', __('The number is required.', 'rsvp'));
        } else {
            $this->addSettingsError('seat_number', $number, '', false);
        }

        $description = isset($input['seat_description']) ? sanitize_text_field($input['seat_description']) : '';
        $this->addSettingsError('seat_description', $description, '', false);

        $service = isset($input['seat_service']) ? absint($input['seat_service']) : '';
        if (! get_term_by('id', $service, CPT::getTaxonomyServiceName())) {
            $this->addSettingsError('seat_service', '', __('The service is required.', 'rsvp'));
        } else {
            $this->addSettingsError('seat_service', $service, '', false);
        }

        if (! $this->settingsErrors()) {
            $post = wp_update_post(
                [
                    'ID' => $this->wpPost->ID,
                    'post_title' => $number,
                    'post_conntent' => $description,
                    'tax_input' => [
                        'rrze_rsvp_service' => [$service]
                    ]                    
                ],
                true
            );
            
            if (is_wp_error($post)) {
                $this->addAdminNotice(__('The seat could not be updated.', 'rrze-rsvp'), 'error');
                wp_redirect(Functions::actionUrl(['page' => 'rrze-rsvp-seats', 'action' => 'edit', 'item' => $this->wpPost->ID]));
                exit();
            }
        }  

        if ($this->settingsErrors()) {
            foreach ($this->settingsErrors() as $error) {
                if ($error['message']) {
                    $this->addAdminNotice($error['message'], 'error');
                }
            }
            wp_redirect(Functions::actionUrl(['page' => 'rrze-rsvp-seats', 'action' => 'edit', 'item' => $this->wpPost->ID]));
            exit();
        }
        
        $this->addAdminNotice(__('The seat has been updated.', 'rrze-rspv'));
        wp_redirect(Functions::actionUrl(['page' => 'rrze-rsvp-seats', 'action' => 'edit', 'item' => $this->wpPost->ID]));
        exit();        
    }

    public function settings()
    {
        $item = absint(Functions::requestVar('item'));
        $this->wpPost = get_post($item);
        if (is_null($this->wpPost)) {
            return;
        }
        $this->wpTerms = wp_get_post_terms($this->wpPost->ID, CPT::getTaxonomyServiceName());
        if (is_wp_error($this->wpTerms)) {
            return;
        }

        add_settings_section(
            'rrze-rsvp-seats-edit-section',
            false,
            '__return_false',
            'rrze-rsvp-seats-edit'
        );

        add_settings_field(
            'seat_number',
            __('Number', 'rrze-ac'),
            [$this, 'numberField'],
            'rrze-rsvp-seats-edit',
            'rrze-rsvp-seats-edit-section'
        );

        add_settings_field(
            'seat_description',
            __('Description', 'rrze-ac'),
            [$this, 'descriptionField'],
            'rrze-rsvp-seats-edit',
            'rrze-rsvp-seats-edit-section'
        );
        
        add_settings_field(
            'seat_service',
            __('Service', 'rrze-ac'),
            [$this, 'serviceField'],
            'rrze-rsvp-seats-edit',
            'rrze-rsvp-seats-edit-section'
        );  
    }

    public function numberField()
    {   $settingsErrors = $this->settingsErrors();
        $number = isset($settingsErrors['seat_number']['value']) ? esc_html($settingsErrors['seat_number']['value']) : $this->wpPost->post_title;
        ?>
        <input type="text" value="<?php echo $number; ?>" name="<?php printf('%s[seat_number]', $this->optionName); ?>" class="regular-text">
        <?php       
    }

    public function descriptionField()
    {
        $settingsErrors = $this->settingsErrors();
        $description = isset($settingsErrors['seat_description']['value']) ? esc_textarea($settingsErrors['seat_description']['value']) : $this->wpPost->post_content;
        ?>
        <textarea id="description" cols="50" rows="3" name="<?php printf('%s[seat_description]', $this->optionName); ?>"><?php echo $description; ?></textarea>
        <?php        
    }

    public function serviceField() {
        $dataService = $this->wpTerms[0];
        $services = Functions::getServices();
        $settingsErrors = $this->settingsErrors();
        $settingsService = isset($settingsErrors['seat_service']['value']) ? esc_textarea($settingsErrors['seat_service']['value']) : $dataService->term_id;
        ?>
        <?php if ($services) : ?>
            <select name="<?php printf('%s[seat_service]', $this->optionName); ?>">
                <option value="0"><?php _e('&mdash; Please select &mdash;', 'rrze-rsvp'); ?></option>
            <?php foreach ($services as $service) : ?>
                <option value="<?php echo $service->term_id; ?>" <?php $settingsService ? selected($service->term_id, $settingsService) : ''; ?>><?php echo $service->name; ?></option>
            <?php endforeach; ?>
            </select>
        <?php else: ?>
            <p><?php _e('No services found.', 'rrze-rsvp'); ?></p>
        <?php endif; ?>
        <?php
    }
}
