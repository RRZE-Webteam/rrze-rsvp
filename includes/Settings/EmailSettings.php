<?php

namespace RRZE\RSVP\Settings;

defined('ABSPATH') || exit;

use RRZE\RSVP\CPT;
use RRZE\RSVP\Options;
use RRZE\RSVP\Settings;
use RRZE\RSVP\Functions;

class EmailSettings extends Settings
{
    protected $options;

    protected $optionName;

    protected $wpTerm;

    public function __construct()
    {
        $this->options = Options::getOptions();
        $this->optionName = Options::getOptionName();

        $this->settingsErrorTransient = 'rrze-rsvp-settings-error-';
        $this->noticeTransient = 'rrze-rsvp-settings-notice-';
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

        if ($defaultOptionsionPage == 'rrze-rsvp-settings-email') {
            $this->validate();
        }
    }

    protected function validate()
    {
        $input = (array) Functions::requestVar($this->optionName);
        $nonce = Functions::requestVar('_wpnonce');

        if (!wp_verify_nonce($nonce, 'rrze-rsvp-settings-email-options')) {
            wp_die(__('Something went wrong.', 'rrze-rsvp'));
        }

        $notificationEmail = isset($input['notification_email']) ? trim($input['notification_email']) : '';
        if (! filter_var($notificationEmail, FILTER_VALIDATE_EMAIL)) {
            $this->addSettingsError('notification_email', '', __('The email address for notifications is not valid.', 'rrze-rsvp'));
        } else {
            $this->addSettingsError('notification_email', $this->serviceOptions->notification_email, '', false);
            $this->serviceOptions->notification_email = $notificationEmail;
        }

        $notificationIfNew = empty($input['notification_if_new']) ? 0 : 1;
        $this->addSettingsError('notification_if_new', $notificationIfNew, '', false);
        $this->options->notification_if_new = $notificationIfNew;

        $notificationIfCancel = empty($input['notification_if_cancel']) ? 0 : 1;
        $this->addSettingsError('notification_if_cancel', $notificationIfCancel, '', false);
        $this->options->notification_if_cancel = $notificationIfCancel;


        $senderName = isset($input['sender_name']) ? trim($input['sender_name']) : '';
        if (empty($senderName)) {
            $this->addSettingsError('sender_name', '', __('The sender name is required.', 'rrze-rsvp'));
        } else {
            $this->addSettingsError('sender_name', $senderName, '', false);
            $this->options->sender_name = $senderName;
        }

        $senderEmail = isset($input['sender_email']) ? trim($input['sender_email']) : '';
        if (! filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
            $this->addSettingsError('sender_email', '', __('The sender email address is not valid.', 'rrze-rsvp'));
        } else {
            $this->addSettingsError('sender_email', $senderEmail, '', false);
            $this->options->sender_email = $senderEmail;
        }

        $receivedSubject = isset($input['received_subject']) ? trim($input['received_subject']) : '';
        if (empty($receivedSubject)) {
            $this->addSettingsError('received_subject', '', __('The received subject is required.', 'rrze-rsvp'));
        } else {
            $this->addSettingsError('received_subject', $receivedSubject, '', false);
            $this->options->received_subject = $receivedSubject;
        }

        $receivedText = isset($input['received_text']) ? sanitize_text_field($input['received_text']) : '';
        if (empty($receivedText)) {
            $this->addSettingsError('received_text', '', __('The received text is required.', 'rrze-rsvp'));
        } else {
            $this->addSettingsError('received_text', $receivedText, '', false);
            $this->options->received_text = $receivedText;
        }

        $confirmSubject = isset($input['confirm_subject']) ? trim($input['confirm_subject']) : '';
        if (empty($confirmSubject)) {
            $this->addSettingsError('confirm_subject', '', __('The confirm subject is required.', 'rrze-rsvp'));
        } else {
            $this->addSettingsError('confirm_subject', $confirmSubject, '', false);
            $this->options->confirm_subject = $confirmSubject;
        }

        $confirmText = isset($input['confirm_text']) ? sanitize_text_field($input['confirm_text']) : '';
        if (empty($confirmText)) {
            $this->addSettingsError('confirm_text', '', __('The confirm text is required.', 'rrze-rsvp'));
        } else {
            $this->addSettingsError('confirm_text', $confirmText, '', false);
            $this->options->confirm_text = $confirmText;
        }
        
        $cancelSubject = isset($input['cancel_subject']) ? trim($input['cancel_subject']) : '';
        if (empty($cancelSubject)) {
            $this->addSettingsError('cancel_subject', '', __('The cancel subject is required.', 'rrze-rsvp'));
        } else {
            $this->addSettingsError('cancel_subject', $cancelSubject, '', false);
            $this->options->cancel_subject = $cancelSubject;
        }

        $cancelText = isset($input['cancel_text']) ? sanitize_text_field($input['cancel_text']) : '';
        if (empty($cancelText)) {
            $this->addSettingsError('cancel_text', '', __('The cancel text is required.', 'rrze-rsvp'));
        } else {
            $this->addSettingsError('cancel_text', $cancelText, '', false);
            $this->options->cancel_text = $cancelText;
        }

        if (!$this->settingsErrors()) {
            update_option($this->optionName, $this->options);
        } else {
            foreach ($this->settingsErrors() as $error) {
                if ($error['message']) {
                    $this->addAdminNotice($error['message'], 'error');
                }
            }
            wp_redirect(Functions::actionUrl(['page' => 'rrze-rsvp-settings', 'action' => 'edit', 'item' => $this->wpTerm->term_id,  'tab' => 'email']));
            exit();
        }

        $this->addAdminNotice(__('The setting has been updated.', 'rrze-rspv'));
        wp_redirect(Functions::actionUrl(['page' => 'rrze-rsvp-settings', 'action' => 'edit', 'item' => $this->wpTerm->term_id,  'tab' => 'email']));
        exit();
    }

    public function settings()
    {
        add_settings_section(
            'rrze-rsvp-settings-email-sender-section',
            __('Sender information', 'rrze-rsvp'),
            '__return_false',
            'rrze-rsvp-settings-email'
        );

        add_settings_field(
            'notification_email',
            __('Email address for notifications', 'rrze-rsvp'),
            [$this, 'notificationEmailField'],
            'rrze-rsvp-settings-email',
            'rrze-rsvp-settings-email-sender-section'
        );

        add_settings_field(
            'notification_if_new',
            __('New booking notification', 'rrze-rsvp'),
            [$this, 'notificationIfNewField'],
            'rrze-rsvp-settings-email',
            'rrze-rsvp-settings-email-sender-section'
        );

        add_settings_field( 
            'notification_if_cancel',
            __('Notification of booking cancellation', 'rrze-rsvp'),
            [$this, 'notificationIfCancelField'],
            'rrze-rsvp-settings-email',
            'rrze-rsvp-settings-email-sender-section'
        );

        add_settings_field(
            'sender_name',
            __('Sender name', 'rrze-rsvp'),
            [$this, 'senderNameField'],
            'rrze-rsvp-settings-email',
            'rrze-rsvp-settings-email-sender-section'
        );

        add_settings_field(
            'sender_email',
            __('Sender email address', 'rrze-rsvp'),
            [$this, 'senderEmailField'],
            'rrze-rsvp-settings-email',
            'rrze-rsvp-settings-email-sender-section'
        );

        add_settings_section(
            'rrze-rsvp-settings-email-received-section',
            __('Receiver notification', 'rrze-rsvp'),
            '__return_false',
            'rrze-rsvp-settings-email'
        );

        add_settings_field(
            'received_subject',
            __('Subject', 'rrze-rsvp'),
            [$this, 'receivedSubjectField'],
            'rrze-rsvp-settings-email',
            'rrze-rsvp-settings-email-received-section'
        );

        add_settings_field(
            'received_text',
            __('Text', 'rrze-rsvp'),
            [$this, 'receivedTextField'],
            'rrze-rsvp-settings-email',
            'rrze-rsvp-settings-email-received-section'
        );

        add_settings_section(
            'rrze-rsvp-settings-email-confirm-section',
            __('Confirm notification', 'rrze-rsvp'),
            '__return_false',
            'rrze-rsvp-settings-email'
        );

        add_settings_field(
            'confirm_subject',
            __('Subject', 'rrze-rsvp'),
            [$this, 'confirmSubjectField'],
            'rrze-rsvp-settings-email',
            'rrze-rsvp-settings-email-confirm-section'
        );

        add_settings_field(
            'confirm_text',
            __('Text', 'rrze-rsvp'),
            [$this, 'confirmTextField'],
            'rrze-rsvp-settings-email',
            'rrze-rsvp-settings-email-confirm-section'
        );

        add_settings_section(
            'rrze-rsvp-settings-email-cancel-section',
            __('Cancel notification', 'rrze-rsvp'),
            '__return_false',
            'rrze-rsvp-settings-email'
        );        

        add_settings_field(
            'cancel_subject',
            __('Subject', 'rrze-rsvp'),
            [$this, 'cancelSubjectField'],
            'rrze-rsvp-settings-email',
            'rrze-rsvp-settings-email-cancel-section'
        );

        add_settings_field(
            'cancel_text',
            __('Text', 'rrze-rsvp'),
            [$this, 'cancelTextField'],
            'rrze-rsvp-settings-email',
            'rrze-rsvp-settings-email-cancel-section'
        );
    }

    public function notificationEmailField()
    {
        $settingsErrors = $this->settingsErrors();
        $notificationEmail = isset($settingsErrors['notification_email']['value']) ? esc_html($settingsErrors['notification_email']['value']) : $this->options->notification_email;
        ?>
        <input type="text" name="<?php printf('%s[notification_email]', $this->optionName); ?>" value="<?php echo $notificationEmail; ?>" id="rrze_rsvp_notification_email" class="regular-text">
        <p class="description"><?php _e('Notifications are sent to this email address when a new booking is made or a booking is canceled.'); ?></p>
        <?php 
    }

    public function notificationIfNewField()
    {
        $settingsErrors = $this->settingsErrors();
        $notificationIfNew = isset($settingsErrors['notification_if_new']['value']) ? esc_html($settingsErrors['notification_if_new']['value']) : $this->options->notification_if_new;
        ?>
        <input type="checkbox" name="<?php printf('%s[notification_if_new]', $this->optionName); ?>" value="1" id="rrze_rsvp_notification_if_new" <?php checked($notificationIfNew); ?>>
        <?php _e('Send notification if a new booking is made.'); ?>
        <?php 
    }

    public function notificationIfCancelField()
    {
        $settingsErrors = $this->settingsErrors();
        $notificationIfCancel = isset($settingsErrors['notification_if_cancel']['value']) ? esc_html($settingsErrors['notification_if_cancel']['value']) : $this->options->notification_if_cancel;
        ?>
        <input type="checkbox" name="<?php printf('%s[notification_if_cancel]', $this->optionName); ?>" value="1" id="rrze_rsvp_notification_if_cancel" <?php checked($notificationIfCancel); ?>>
        <?php _e('Send notification if a booking is canceled.'); ?>
        <?php 
    }

    public function senderNameField()
    {
        $settingsErrors = $this->settingsErrors();
        $senderName = isset($settingsErrors['sender_name']['value']) ? esc_html($settingsErrors['sender_name']['value']) : $this->options->sender_name;
        ?>
        <input type="text" name="<?php printf('%s[sender_name]', $this->optionName); ?>" value="<?php echo $senderName; ?>" id="rrze_rsvp_sender_name" class="regular-text">
        <?php
    }

    public function senderEmailField()
    {
        $settingsErrors = $this->settingsErrors();
        $senderEmail = isset($settingsErrors['sender_email']['value']) ? esc_html($settingsErrors['sender_email']['value']) : $this->options->sender_email;
        ?>
        <input type="text" name="<?php printf('%s[sender_email]', $this->optionName); ?>" value="<?php echo $senderEmail; ?>" id="rrze_rsvp_sender_email" class="regular-text">
    <?php
    }

    public function receivedSubjectField()
    {
        $settingsErrors = $this->settingsErrors();
        $receivedSubject = isset($settingsErrors['received_subject']['value']) ? esc_html($settingsErrors['received_subject']['value']) : $this->options->received_subject;
        ?>
        <input type="text" name="<?php printf('%s[received_subject]', $this->optionName); ?>" value="<?php echo $receivedSubject; ?>" id="rrze_rsvp_received_subject" class="regular-text">
    <?php
    }

    public function receivedTextField()
    {
        $settingsErrors = $this->settingsErrors();
        $receivedText = isset($settingsErrors['received_text']['value']) ? esc_textarea($settingsErrors['received_text']['value']) : $this->options->received_text;
        ?>
        <textarea cols="50" rows="5" name="<?php printf('%s[received_text]', $this->optionName); ?>" id="rrze_rsvp_received_text"><?php echo $receivedText; ?></textarea>
    <?php
    }

    public function confirmSubjectField()
    {
        $settingsErrors = $this->settingsErrors();
        $confirmSubject = isset($settingsErrors['confirm_subject']['value']) ? esc_html($settingsErrors['confirm_subject']['value']) : $this->options->confirm_subject;
        ?>
        <input type="text" name="<?php printf('%s[confirm_subject]', $this->optionName); ?>" value="<?php echo $confirmSubject; ?>" id="rrze_rsvp_confirm_subject" class="regular-text">
        <?php
    }

    public function confirmTextField()
    {
        $settingsErrors = $this->settingsErrors();
        $confirmText = isset($settingsErrors['confirm_text']['value']) ? esc_textarea($settingsErrors['confirm_text']['value']) : $this->options->confirm_text;
        ?>
        <textarea cols="50" rows="5" name="<?php printf('%s[confirm_text]', $this->optionName); ?>" id="rrze_rsvp_confirm_text"><?php echo $confirmText; ?></textarea>
        <?php
    }

    public function cancelSubjectField()
    {
        $settingsErrors = $this->settingsErrors();
        $cancelSubject = isset($settingsErrors['cancel_subject']['value']) ? esc_html($settingsErrors['cancel_subject']['value']) : $this->options->cancel_subject;
        ?>
        <input type="text" name="<?php printf('%s[cancel_subject]', $this->optionName); ?>" value="<?php echo $cancelSubject; ?>" id="rrze_rsvp_cancel_subject" class="regular-text">
        <?php
    }

    public function cancelTextField()
    {
        $settingsErrors = $this->settingsErrors();
        $cancelText = isset($settingsErrors['cancel_text']['value']) ? esc_textarea($settingsErrors['cancel_text']['value']) : $this->options->cancel_text;
        ?>
        <textarea cols="50" rows="5" name="<?php printf('%s[cancel_text]', $this->optionName); ?>" id="rrze_rsvp_cancel_text"><?php echo $cancelText; ?></textarea>
        <?php
    }    
}
