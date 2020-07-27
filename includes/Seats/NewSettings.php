<?php

namespace RRZE\RSVP\Seats;

defined('ABSPATH') || exit;

use RRZE\RSVP\CPT;
use RRZE\RSVP\Settings;
use RRZE\RSVP\Functions;

class NewSettings extends Settings
{
    protected $optionName = 'rrze_rsvp_seats'; 

    public function __construct()
    {
        $this->settingsErrorTransient = 'rrze-rsvp-seats-settings-new-error-';
        $this->noticeTransient = 'rrze-rsvp-seats-settings-new-notice-';
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

        if ($optionPage == 'rrze-rsvp-seats-new') {
            $this->validate();
        }
    }

    protected function validate()
    {
        $input = (array) Functions::requestVar($this->optionName);
        $nonce = Functions::requestVar('_wpnonce');

        if (!wp_verify_nonce($nonce, 'rrze-rsvp-seats-new-options')) {
            wp_die(__('Something went wrong.', 'rrze-rsvp'));
        }

        $number = isset($input['seat_number']) ? trim($input['seat_number']) : '';
        if (empty($number)) {
            $this->addSettingsError('seat_number', '', __('The number is required.', 'rrze-rsvp'));
        } else {
            $this->addSettingsError('seat_number', $number, '', false);
        }

        $service = isset($input['seat_service']) ? absint($input['seat_service']) : '';
        if (! get_term_by('id', $service, CPT::getTaxonomyServiceName())) {
            $this->addSettingsError('seat_service', '', __('The service is required.', 'rrze-rsvp'));
        } else {
            $this->addSettingsError('seat_service', $service, '', false);
        }
        
        if (! $this->settingsErrors()) {
			$args = [
				'post_type' => CPT::getSeatName(),
				'post_title' => $number,
				'post_content' => '',
				'post_status' => 'publish',
				'post_author' => 1,
				'tax_input' => [
					'rrze_rsvp_service' => $service
				]
			];

			$postId = wp_insert_post($args);
            
            if (is_wp_error($postId)) {
                $this->addAdminNotice(__('The seat could not be added.', 'rrze-rsvp'), 'error');
                wp_redirect(Functions::actionUrl(['page' => 'rrze-rsvp-seats', 'action' => 'new']));
                exit();
            }
        }  

        if ($this->settingsErrors()) {
            foreach ($this->settingsErrors() as $error) {
                if ($error['message']) {
                    $this->addAdminNotice($error['message'], 'error');
                }
            }
            wp_redirect(Functions::actionUrl(['page' => 'rrze-rsvp-seats', 'action' => 'new']));
            exit();
        }
        
        $this->addAdminNotice(__('The seat has been added.', 'rrze-rspv'));
        wp_redirect(Functions::actionUrl(['page' => 'rrze-rsvp-seats', 'action' => 'edit', 'item' => $postId]));
        exit();        
    }

    public function settings()
    {
        add_settings_section(
            'rrze-rsvp-seats-new-section',
            false,
            '__return_false',
            'rrze-rsvp-seats-new'
        );

        add_settings_field(
            'seat_number',
            __('Number', 'rrze-rsvp'),
            [$this, 'numberField'],
            'rrze-rsvp-seats-new',
            'rrze-rsvp-seats-new-section'
        );
        
        add_settings_field(
            'seat_service',
            __('Service', 'rrze-rsvp'),
            [$this, 'serviceField'],
            'rrze-rsvp-seats-new',
            'rrze-rsvp-seats-new-section'
        );         
    }

    public function numberField()
    {   $settingsErrors = $this->settingsErrors();
        $number = isset($settingsErrors['seat_number']['value']) ? esc_html($settingsErrors['seat_number']['value']) : '';
        ?>
        <input type="text" value="<?php echo $number; ?>" name="<?php printf('%s[seat_number]', $this->optionName); ?>" class="regular-text">
        <?php       
    }

    public function serviceField() {
        $services = Functions::getServices();
        $settingsErrors = $this->settingsErrors();
        $seatService = isset($settingsErrors['seat_service']['value']) ? esc_textarea($settingsErrors['seat_service']['value']) : '';
        ?>
        <?php if ($services) : ?>
            <select name="<?php printf('%s[seat_service]', $this->optionName); ?>">
                <option value="0"><?php _e('&mdash; Please select &mdash;', 'rrze-rsvp'); ?></option>
            <?php foreach ($services as $service) : ?>
                <option value="<?php echo $service->term_id; ?>" <?php $seatService ? selected($service->term_id, $seatService) : ''; ?>><?php echo $service->name; ?></option>
            <?php endforeach; ?>
            </select>
        <?php else: ?>
            <p><?php _e('No seats found.', 'rrze-rsvp'); ?></p>
        <?php endif; ?>
        <?php
    }    
}
