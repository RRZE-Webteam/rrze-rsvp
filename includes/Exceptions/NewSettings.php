<?php

namespace RRZE\RSVP\Exceptions;

defined('ABSPATH') || exit;

use RRZE\RSVP\CPT;
use RRZE\RSVP\Settings;
use RRZE\RSVP\Functions;

class NewSettings extends Settings
{
    protected $optionName = 'rrze_rsvp_exceptions';

    public function __construct()
    {
        $this->settingsErrorTransient = 'rrze-rsvp-exceptions-settings-new-error-';
        $this->noticeTransient = 'rrze-rsvp-exceptions-settings-new-notice-';
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

        if ($optionPage == 'rrze-rsvp-exceptions-new') {
            $this->validate();
        }
    }

    protected function validate()
    {
        $input = (array) Functions::requestVar($this->optionName);
        $nonce = Functions::requestVar('_wpnonce');

        if (!wp_verify_nonce($nonce, 'rrze-rsvp-exceptions-new-options')) {
            wp_die(__('Something went wrong.', 'rrze-rsvp'));
        }

        $fullDay = !empty($input['exception_fullday']) ? '1' : '0';

        $start = isset($input['exception_start']) ? trim($input['exception_start']) : '';
        $start = $this->validateDate($start, 'Y-m-d');
        if (!$start) {
            $this->addSettingsError('exception_start', '', __('The start date is required.', 'rsvp'));
        }
        $startTime = isset($input['exception_start_time']) ? trim($input['exception_start_time']) : '00:00';

        $end = isset($input['exception_end']) ? trim($input['exception_end']) : '';
        $end = $this->validateDate($end, 'Y-m-d');
        if (!$end) {
            $this->addSettingsError('exception_end', '', __('The end date is required.', 'rsvp'));
        }
        $endTime = isset($input['exception_end_time']) ? trim($input['exception_end_time']) : '00:00';

        if (! $fullDay) {
            $start .= ' ' . $this->validateTime($startTime);
            $end .= ' ' . $this->validateTime($endTime);
        } else {
            $start .= ' 00:00';
            $end .= ' 00:00';
        }

        $description = isset($input['exception_description']) ? sanitize_text_field($input['exception_description']) : '';
        $this->addSettingsError('exception_description', $description, '', false);

        $service = isset($input['exception_service']) ? absint($input['exception_service']) : '';
        if (!get_term_by('id', $service, CPT::getTaxonomyServiceName())) {
            $this->addSettingsError('exception_service', '', __('The service is required.', 'rsvp'));
        } else {
            $this->addSettingsError('exception_service', $service, '', false);
        }

        if (!$this->settingsErrors()) {
            $args = [
                'post_type' => CPT::getCptExceptionsName(),
                'post_title' => bin2hex(random_bytes(8)),
                'post_content' => $description,
                'post_status' => 'publish',
                'post_author' => 1,
                'tax_input' => [
                    'rrze_rsvp_service' => $service
                ],
				'meta_input' => [
					'rrze_rsvp_exception_start' => $start,
					'rrze_rsvp_exception_end' => $end
				]
            ];

            $postId = wp_insert_post($args);

            if (is_wp_error($postId)) {
                $this->addAdminNotice(__('The exception could not be added.', 'rrze-rsvp'), 'error');
                wp_redirect(Functions::actionUrl(['page' => 'rrze-rsvp-exceptions', 'action' => 'new']));
                exit();
            }
        }

        if ($this->settingsErrors()) {
            foreach ($this->settingsErrors() as $error) {
                if ($error['message']) {
                    $this->addAdminNotice($error['message'], 'error');
                }
            }
            wp_redirect(Functions::actionUrl(['page' => 'rrze-rsvp-exceptions', 'action' => 'new']));
            exit();
        }

        $this->addAdminNotice(__('The exception has been added.', 'rrze-rspv'));
        wp_redirect(Functions::actionUrl(['page' => 'rrze-rsvp-exceptions', 'action' => 'edit', 'item' => $postId]));
        exit();
    }

    public function settings()
    {
        add_settings_section(
            'rrze-rsvp-exceptions-new-section',
            false,
            '__return_false',
            'rrze-rsvp-exceptions-new'
        );

        add_settings_field(
            'exception_allday',
            __('All Day', 'rrze-ac'),
            [$this, 'allDayField'],
            'rrze-rsvp-exceptions-new',
            'rrze-rsvp-exceptions-new-section'
        );

        add_settings_field(
            'exception_start',
            __('Start', 'rrze-ac'),
            [$this, 'startField'],
            'rrze-rsvp-exceptions-new',
            'rrze-rsvp-exceptions-new-section'
        );

        add_settings_field(
            'exception_end',
            __('End', 'rrze-ac'),
            [$this, 'endField'],
            'rrze-rsvp-exceptions-new',
            'rrze-rsvp-exceptions-new-section'
        );

        add_settings_field(
            'exception_description',
            __('Description', 'rrze-ac'),
            [$this, 'descriptionField'],
            'rrze-rsvp-exceptions-new',
            'rrze-rsvp-exceptions-new-section'
        );        

        add_settings_field(
            'exception_service',
            __('Service', 'rrze-ac'),
            [$this, 'serviceField'],
            'rrze-rsvp-exceptions-new',
            'rrze-rsvp-exceptions-new-section'
        );
    }

    public function allDayField()
    {
        $settingsErrors = $this->settingsErrors();
        $allDay = isset($settingsErrors['exception_allday']['value']) ? esc_html($settingsErrors['exception_allday']['value']) : true;
        ?>
        <input type="checkbox" id="rrze_rsvp_exception_allday" name="<?php printf('%s[exception_allday]', $this->optionName); ?>" <?php checked($allDay); ?>>
        <?php
    }

    public function startField()
    {
        ?>
        <input type="text" name="exception_start_datepicker" data-target="rrze_rsvp_exception_start" class="rrze_rsvp_datepicker">
        <input type="text" name="<?php printf('%s[exception_start]', $this->optionName); ?>" id="rrze_rsvp_exception_start" class="rrze_rsvp_datepicker_value">
        <input type="time" name="<?php printf('%s[exception_start_time]', $this->optionName); ?>" class="exception_hide" placeholder="00:00">
        <?php
    }

    public function endField()
    {
        ?>
        <input type="text" name="exception_end_datepicker" data-target="rrze_rsvp_exception_end" class="rrze_rsvp_datepicker">
        <input type="text" name="<?php printf('%s[exception_end]', $this->optionName); ?>" id="rrze_rsvp_exception_end" class="rrze_rsvp_datepicker_value">
        <input type="time" name="<?php printf('%s[exception_end_time]', $this->optionName); ?>" class="exception_hide" placeholder="00:00">
        <?php
    }

    public function descriptionField()
    {   $settingsErrors = $this->settingsErrors();
        $description = isset($settingsErrors['exception_description']['value']) ? esc_html($settingsErrors['exception_description']['value']) : '';
        ?>
        <input type="text" value="<?php echo $description; ?>" name="<?php printf('%s[exception_description]', $this->optionName); ?>" class="regular-text">
        <?php       
    }

    public function serviceField()
    {
        $services = Functions::getServices();
        $settingsErrors = $this->settingsErrors();
        $exceptionService = isset($settingsErrors['exception_service']['value']) ? esc_textarea($settingsErrors['exception_service']['value']) : '';
        ?>
        <?php if ($services) : ?>
            <select name="<?php printf('%s[exception_service]', $this->optionName); ?>">
                <option value="0"><?php _e('&mdash; Please select &mdash;', 'rrze-rsvp'); ?></option>
                <?php foreach ($services as $service) : ?>
                    <option value="<?php echo $service->term_id; ?>" <?php $exceptionService ? selected($service->term_id, $exceptionService) : ''; ?>><?php echo $service->name; ?></option>
                <?php endforeach; ?>
            </select>
        <?php else : ?>
            <p><?php _e('No exceptions found.', 'rrze-rsvp'); ?></p>
        <?php endif; ?>
        <?php
    }

    protected function validateDate(string $date, string $format = 'Y-m-d\TH:i:s\Z')
    {
        return Functions::validateDate($date, $format);
    }

    protected function validateTime(string $time) : string
    {
        return Functions::validateTime($time);
    }    
}
