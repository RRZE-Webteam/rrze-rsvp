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

        $receiverSubject = isset($input['receiver_subject']) ? trim($input['receiver_subject']) : '';
        if (empty($receiverSubject)) {
            $this->addSettingsError('receiver_subject', '', __('The receiver subject is required.', 'rrze-rsvp'));
        } else {
            $this->addSettingsError('receiver_subject', $receiverSubject, '', false);
            $this->options->receiver_subject = $receiverSubject;
        }

        $receiverText = isset($input['receiver_text']) ? sanitize_text_field($input['receiver_text']) : '';
        if (empty($receiverText)) {
            $this->addSettingsError('receiver_text', '', __('The receiver text is required.', 'rrze-rsvp'));
        } else {
            $this->addSettingsError('receiver_text', $receiverText, '', false);
            $this->options->receiver_text = $receiverText;
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
            'rrze-rsvp-settings-email-receiver-section',
            __('Receiver notification', 'rrze-rsvp'),
            '__return_false',
            'rrze-rsvp-settings-email'
        );

        add_settings_field(
            'receiver_subject',
            __('Subject', 'rrze-rsvp'),
            [$this, 'receiverSubjectField'],
            'rrze-rsvp-settings-email',
            'rrze-rsvp-settings-email-receiver-section'
        );

        add_settings_field(
            'receiver_text',
            __('Text', 'rrze-rsvp'),
            [$this, 'receiverTextField'],
            'rrze-rsvp-settings-email',
            'rrze-rsvp-settings-email-receiver-section'
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
        $notificationEmail = isset($settingsErrors['sender_email']['value']) ? esc_html($settingsErrors['sender_email']['value']) : $this->options->sender_email;
    ?>
        <input type="text" name="<?php printf('%s[sender_email]', $this->optionName); ?>" value="<?php echo $notificationEmail; ?>" id="rrze_rsvp_sender_email" class="regular-text">
    <?php
    }

    public function receiverSubjectField()
    {
        $settingsErrors = $this->settingsErrors();
        $receiverSubject = isset($settingsErrors['receiver_subject']['value']) ? esc_html($settingsErrors['receiver_subject']['value']) : $this->options->receiver_subject;
        ?>
        <input type="text" name="<?php printf('%s[receiver_subject]', $this->optionName); ?>" value="<?php echo $receiverSubject; ?>" id="rrze_rsvp_receiver_subject" class="regular-text">
    <?php
    }

    public function receiverTextField()
    {
        $settingsErrors = $this->settingsErrors();
        $receiverText = isset($settingsErrors['receiver_text']['value']) ? esc_textarea($settingsErrors['receiver_text']['value']) : $this->options->receiver_text;
        ?>
        <textarea cols="50" rows="5" name="<?php printf('%s[receiver_text]', $this->optionName); ?>" id="rrze_rsvp_receiver_text"><?php echo $receiverText; ?></textarea>
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
