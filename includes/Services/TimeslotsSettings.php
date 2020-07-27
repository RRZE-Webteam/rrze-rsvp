<?php

namespace RRZE\RSVP\Services;

defined('ABSPATH') || exit;

use RRZE\RSVP\CPT;
use RRZE\RSVP\Options;
use RRZE\RSVP\Settings;
use RRZE\RSVP\Functions;

class TimeslotsSettings extends Settings
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
        $defaultOptionsionPage = Functions::requestVar('option_page');

        if ($defaultOptionsionPage == 'rrze-rsvp-services-edit-timeslots') {
            $this->validate();
        }
    }

    protected function validate()
    {
        $input = (array) Functions::requestVar($this->optionName);
        $nonce = Functions::requestVar('_wpnonce');

        if (!wp_verify_nonce($nonce, 'rrze-rsvp-services-edit-timeslots-options')) {
            wp_die(__('Something went wrong.', 'rrze-rsvp'));
        }

        $item = absint(Functions::requestVar('item'));
        $this->wpTerm = get_term_by('id', $item, CPT::getTaxonomyServiceName());
        if ($this->wpTerm === false) {
            wp_die(__('Something went wrong.', 'rrze-rsvp'));
        }

        $this->serviceOptions = Options::getServiceOptions($this->wpTerm->term_id);

        $this->serviceOptions->weekdays_timeslots = isset($input['weekdays_timeslots']) ? $input['weekdays_timeslots'] : $this->serviceOptions->weekdays_timeslots;
        $this->addSettingsError('weekdays_timeslots', $this->serviceOptions->weekdays_timeslots, '', false);

        $this->serviceOptions->event_duration = isset($input['event_duration']) ? $input['event_duration'] : $this->serviceOptions->event_duration;
        $this->addSettingsError('event_duration', $this->serviceOptions->event_duration, '', false);

        $this->serviceOptions->event_gap = isset($input['event_gap']) ? $input['event_gap'] : $this->serviceOptions->event_gap;
        $this->addSettingsError('event_gap', $this->serviceOptions->event_gap, '', false);

        if (!$this->settingsErrors()) {
            update_term_meta($this->wpTerm->term_id, Options::getServiceOptionName(), $this->serviceOptions);
        } else {
            foreach ($this->settingsErrors() as $error) {
                if ($error['message']) {
                    $this->addAdminNotice($error['message'], 'error');
                }
            }
            wp_redirect(Functions::actionUrl(['page' => 'rrze-rsvp-services', 'action' => 'edit', 'item' => $this->wpTerm->term_id,  'tab' => 'timeslots']));
            exit();
        }

        $this->addAdminNotice(__('The service has been updated.', 'rrze-rspv'));
        wp_redirect(Functions::actionUrl(['page' => 'rrze-rsvp-services', 'action' => 'edit', 'item' => $this->wpTerm->term_id,  'tab' => 'timeslots']));
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
            'rrze-rsvp-services-edit-timeslots-section',
            false,
            '__return_false',
            'rrze-rsvp-services-edit-timeslots'
        );

        add_settings_field(
            'weekdays_timeslots',
            __('Week days timeslots', 'rrze-rsvp'),
            [$this, 'weekdaysSlotsField'],
            'rrze-rsvp-services-edit-timeslots',
            'rrze-rsvp-services-edit-timeslots-section'
        );

        add_settings_field(
            'event_duration',
            __('Duration of an event (hh:mm)', 'rrze-rsvp'),
            [$this, 'eventDurationField'],
            'rrze-rsvp-services-edit-timeslots',
            'rrze-rsvp-services-edit-timeslots-section'
        );
        
        add_settings_field(
            'event_gap',
            __('Break between events (hh:mm)', 'rrze-rsvp'),
            [$this, 'eventGapField'],
            'rrze-rsvp-services-edit-timeslots',
            'rrze-rsvp-services-edit-timeslots-section'
        );        
    }

    public function weekdaysSlotsField()
    {
        $settingsErrors = $this->settingsErrors();
        $weekdaysSlots = isset($settingsErrors['weekdays_timeslots']['value']) ? esc_html($settingsErrors['weekdays_timeslots']['value']) : $this->serviceOptions->weekdays_timeslots;

        $weekDays = array(
            __('Monday', 'rrze-rsvp'),
            __('Tuesday', 'rrze-rsvp'),
            __('Wednesday', 'rrze-rsvp'),
            __('Thursday', 'rrze-rsvp'),
            __('Friday', 'rrze-rsvp'),
            __('Saturday', 'rrze-rsvp'),
            __('Sunday', 'rrze-rsvp')
        )
        ?>
        <table>
            <tr>
                <td></td>
                <td><?php _e('Start', 'rrze-rsvp'); ?></td>
                <td><?php _e('End', 'rrze-rsvp'); ?></td>
            </tr>
            <?php for ($i = 0; $i < 7; $i++) { ?>
                <tr>
                    <td><input type="checkbox" data-target="<?php echo $i; ?>" class="rrze_rsvp_check_weekdays_timeslots" <?php $this->isInactiveWeekday($weekdaysSlots[$i], '', 'checked'); ?>> <?php echo $weekDays[$i]; ?></td>
                    <td><input type="time" name="<?php printf('%s[weekdays_timeslots][%s][start]', $this->optionName, $i); ?>" id="rrze_rsvp_weekdays_timeslots[<?php echo $i; ?>][start]" value="<?php echo $weekdaysSlots[$i]['start'] ?>" <?php $this->isInactiveWeekday($weekdaysSlots[$i], 'readonly'); ?>></td>
                    <td><input type="time" name="<?php printf('%s[weekdays_timeslots][%s][end]', $this->optionName, $i); ?>" id="rrze_rsvp_weekdays_timeslots[<?php echo $i; ?>][end]" value="<?php echo $weekdaysSlots[$i]['end'] ?>" <?php $this->isInactiveWeekday($weekdaysSlots[$i], 'readonly'); ?>></td>
                </tr>
            <?php } ?>
        </table>
        <?php
    }

    protected function isInactiveWeekday($option, string $str1, string $str2 = '')
    {
        echo ($option['start'] == '00:00' && $option['end'] == '00:00') ? $str1 : $str2;
    }

    public function eventDurationField()
    {
        $settingsErrors = $this->settingsErrors();
        $eventDuration = isset($settingsErrors['event_duration']['value']) ? esc_html($settingsErrors['event_duration']['value']) : $this->serviceOptions->event_duration;
        ?>
        <input type="time" name="<?php printf('%s[event_duration]', $this->optionName); ?>" id="rrze_rsvp_event_duration" value="<?php echo $eventDuration; ?>">
        <p class="description"><?php _e('The duration of a single event in hours and minutes.'); ?></p>
        <?php
    }

    public function eventGapField()
    {
        $settingsErrors = $this->settingsErrors();
        $eventGap = isset($settingsErrors['event_gap']['value']) ? esc_html($settingsErrors['event_gap']['value']) : $this->serviceOptions->event_gap;
        ?>
        <input type="time" name="<?php printf('%s[event_gap]', $this->optionName); ?>" id="rrze_rsvp_event_gap" value="<?php echo $eventGap; ?>">
        <p class="description"><?php _e('The duration between two consecutive events in hours and minutes.'); ?></p>
        <?php
    }
}
