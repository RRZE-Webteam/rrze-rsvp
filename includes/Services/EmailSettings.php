<?php

namespace RRZE\RSVP\Services;

defined('ABSPATH') || exit;

use RRZE\RSVP\CPT;
use RRZE\RSVP\Options;
use RRZE\RSVP\Settings;
use RRZE\RSVP\Functions;

class EmailSettings extends Settings
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

        if ($defaultOptionsionPage == 'rrze-rsvp-services-edit-email') {
            $this->validate();
        }
    }

    protected function validate()
    {
        $input = (array) Functions::requestVar($this->optionName);
        $nonce = Functions::requestVar('_wpnonce');

        if (!wp_verify_nonce($nonce, 'rrze-rsvp-services-edit-email-options')) {
            wp_die(__('Something went wrong.', 'rrze-rsvp'));
        }

        $item = absint(Functions::requestVar('item'));
        $this->wpTerm = get_term_by('id', $item, CPT::getTaxonomyServiceName());
        if ($this->wpTerm === false) {
            wp_die(__('Something went wrong.', 'rrze-rsvp'));
        }

        $this->serviceOptions = Options::getServiceOptions($this->wpTerm->term_id);

        $senderName = isset($input['sender_name']) ? trim($input['sender_name']) : '';
        if (empty($senderName)) {
            $this->addSettingsError('sender_name', '', __('The sender name is required.', 'rrze-rsvp'));
        } else {
            $this->addSettingsError('sender_name', $senderName, '', false);
            $this->serviceOptions->sender_name = $senderName;
        }

        $senderEmail = isset($input['sender_email']) ? trim($input['sender_email']) : '';
        if (! filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
            $this->addSettingsError('sender_email', '', __('The sender email address is not valid.', 'rrze-rsvp'));
        } else {
            $this->addSettingsError('sender_email', $senderEmail, '', false);
            $this->serviceOptions->sender_email = $senderEmail;
        }

        $receiverSubject = isset($input['receiver_subject']) ? trim($input['receiver_subject']) : '';
        if (empty($receiverSubject)) {
            $this->addSettingsError('receiver_subject', '', __('The receiver subject is required.', 'rrze-rsvp'));
        } else {
            $this->addSettingsError('receiver_subject', $receiverSubject, '', false);
            $this->serviceOptions->receiver_subject = $receiverSubject;
        }

        $receiverText = isset($input['receiver_text']) ? sanitize_text_field($input['receiver_text']) : '';
        if (empty($receiverText)) {
            $this->addSettingsError('receiver_text', '', __('The receiver text is required.', 'rrze-rsvp'));
        } else {
            $this->addSettingsError('receiver_text', $receiverText, '', false);
            $this->serviceOptions->receiver_text = $receiverText;
        }

        $confirmSubject = isset($input['confirm_subject']) ? trim($input['confirm_subject']) : '';
        if (empty($confirmSubject)) {
            $this->addSettingsError('confirm_subject', '', __('The confirm subject is required.', 'rrze-rsvp'));
        } else {
            $this->addSettingsError('confirm_subject', $confirmSubject, '', false);
            $this->serviceOptions->confirm_subject = $confirmSubject;
        }

        $confirmText = isset($input['confirm_text']) ? sanitize_text_field($input['confirm_text']) : '';
        if (empty($confirmText)) {
            $this->addSettingsError('confirm_text', '', __('The confirm text is required.', 'rrze-rsvp'));
        } else {
            $this->addSettingsError('confirm_text', $confirmText, '', false);
            $this->serviceOptions->confirm_text = $confirmText;
        }
        
        $cancelSubject = isset($input['cancel_subject']) ? trim($input['cancel_subject']) : '';
        if (empty($cancelSubject)) {
            $this->addSettingsError('cancel_subject', '', __('The cancel subject is required.', 'rrze-rsvp'));
        } else {
            $this->addSettingsError('cancel_subject', $cancelSubject, '', false);
            $this->serviceOptions->cancel_subject = $cancelSubject;
        }

        $cancelText = isset($input['cancel_text']) ? sanitize_text_field($input['cancel_text']) : '';
        if (empty($cancelText)) {
            $this->addSettingsError('cancel_text', '', __('The cancel text is required.', 'rrze-rsvp'));
        } else {
            $this->addSettingsError('cancel_text', $cancelText, '', false);
            $this->serviceOptions->cancel_text = $cancelText;
        }

        if (!$this->settingsErrors()) {
            update_term_meta($this->wpTerm->term_id, Options::getServiceOptionName(), $this->serviceOptions);
        } else {
            foreach ($this->settingsErrors() as $error) {
                if ($error['message']) {
                    $this->addAdminNotice($error['message'], 'error');
                }
            }
            wp_redirect(Functions::actionUrl(['page' => 'rrze-rsvp-services', 'action' => 'edit', 'item' => $this->wpTerm->term_id,  'tab' => 'email']));
            exit();
        }

        $this->addAdminNotice(__('The service has been updated.', 'rrze-rspv'));
        wp_redirect(Functions::actionUrl(['page' => 'rrze-rsvp-services', 'action' => 'edit', 'item' => $this->wpTerm->term_id,  'tab' => 'email']));
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
            'rrze-rsvp-services-edit-email-sender-section',
            __('Sender information', 'rrze-rsvp'),
            '__return_false',
            'rrze-rsvp-services-edit-email'
        );

        add_settings_field(
            'sender_name',
            __('Sender name', 'rrze-rsvp'),
            [$this, 'senderNameField'],
            'rrze-rsvp-services-edit-email',
            'rrze-rsvp-services-edit-email-sender-section'
        );

        add_settings_field(
            'sender_email',
            __('Sender email address', 'rrze-rsvp'),
            [$this, 'senderEmailField'],
            'rrze-rsvp-services-edit-email',
            'rrze-rsvp-services-edit-email-sender-section'
        );

        add_settings_section(
            'rrze-rsvp-services-edit-email-receiver-section',
            __('Receiver notification', 'rrze-rsvp'),
            '__return_false',
            'rrze-rsvp-services-edit-email'
        );

        add_settings_field(
            'receiver_subject',
            __('Subject', 'rrze-rsvp'),
            [$this, 'receiverSubjectField'],
            'rrze-rsvp-services-edit-email',
            'rrze-rsvp-services-edit-email-receiver-section'
        );

        add_settings_field(
            'receiver_text',
            __('Text', 'rrze-rsvp'),
            [$this, 'receiverTextField'],
            'rrze-rsvp-services-edit-email',
            'rrze-rsvp-services-edit-email-receiver-section'
        );

        add_settings_section(
            'rrze-rsvp-services-edit-email-confirm-section',
            __('Confirm notification', 'rrze-rsvp'),
            '__return_false',
            'rrze-rsvp-services-edit-email'
        );

        add_settings_field(
            'confirm_subject',
            __('Subject', 'rrze-rsvp'),
            [$this, 'confirmSubjectField'],
            'rrze-rsvp-services-edit-email',
            'rrze-rsvp-services-edit-email-confirm-section'
        );

        add_settings_field(
            'confirm_text',
            __('Text', 'rrze-rsvp'),
            [$this, 'confirmTextField'],
            'rrze-rsvp-services-edit-email',
            'rrze-rsvp-services-edit-email-confirm-section'
        );

        add_settings_section(
            'rrze-rsvp-services-edit-email-cancel-section',
            __('Cancel notification', 'rrze-rsvp'),
            '__return_false',
            'rrze-rsvp-services-edit-email'
        );        

        add_settings_field(
            'cancel_subject',
            __('Subject', 'rrze-rsvp'),
            [$this, 'cancelSubjectField'],
            'rrze-rsvp-services-edit-email',
            'rrze-rsvp-services-edit-email-cancel-section'
        );

        add_settings_field(
            'cancel_text',
            __('Text', 'rrze-rsvp'),
            [$this, 'cancelTextField'],
            'rrze-rsvp-services-edit-email',
            'rrze-rsvp-services-edit-email-cancel-section'
        );
    }

    public function senderNameField()
    {
        $settingsErrors = $this->settingsErrors();
        $senderName = isset($settingsErrors['sender_name']['value']) ? esc_html($settingsErrors['sender_name']['value']) : $this->serviceOptions->sender_name;
        ?>
        <input type="text" name="<?php printf('%s[sender_name]', $this->optionName); ?>" value="<?php echo $senderName; ?>" id="rrze_rsvp_sender_name" class="regular-text">
        <?php
    }

    public function senderEmailField()
    {
        $settingsErrors = $this->settingsErrors();
        $notificationEmail = isset($settingsErrors['sender_email']['value']) ? esc_html($settingsErrors['sender_email']['value']) : $this->serviceOptions->sender_email;
    ?>
        <input type="text" name="<?php printf('%s[sender_email]', $this->optionName); ?>" value="<?php echo $notificationEmail; ?>" id="rrze_rsvp_sender_email" class="regular-text">
    <?php
    }

    public function receiverSubjectField()
    {
        $settingsErrors = $this->settingsErrors();
        $receiverSubject = isset($settingsErrors['receiver_subject']['value']) ? esc_html($settingsErrors['receiver_subject']['value']) : $this->serviceOptions->receiver_subject;
        ?>
        <input type="text" name="<?php printf('%s[receiver_subject]', $this->optionName); ?>" value="<?php echo $receiverSubject; ?>" id="rrze_rsvp_receiver_subject" class="regular-text">
    <?php
    }

    public function receiverTextField()
    {
        $settingsErrors = $this->settingsErrors();
        $receiverText = isset($settingsErrors['receiver_text']['value']) ? esc_textarea($settingsErrors['receiver_text']['value']) : $this->serviceOptions->receiver_text;
        ?>
        <textarea cols="50" rows="5" name="<?php printf('%s[receiver_text]', $this->optionName); ?>" id="rrze_rsvp_receiver_text"><?php echo $receiverText; ?></textarea>
    <?php
    }

    public function confirmSubjectField()
    {
        $settingsErrors = $this->settingsErrors();
        $confirmSubject = isset($settingsErrors['confirm_subject']['value']) ? esc_html($settingsErrors['confirm_subject']['value']) : $this->serviceOptions->confirm_subject;
        ?>
        <input type="text" name="<?php printf('%s[confirm_subject]', $this->optionName); ?>" value="<?php echo $confirmSubject; ?>" id="rrze_rsvp_confirm_subject" class="regular-text">
        <?php
    }

    public function confirmTextField()
    {
        $settingsErrors = $this->settingsErrors();
        $confirmText = isset($settingsErrors['confirm_text']['value']) ? esc_textarea($settingsErrors['confirm_text']['value']) : $this->serviceOptions->confirm_text;
        ?>
        <textarea cols="50" rows="5" name="<?php printf('%s[confirm_text]', $this->optionName); ?>" id="rrze_rsvp_confirm_text"><?php echo $confirmText; ?></textarea>
        <?php
    }

    public function cancelSubjectField()
    {
        $settingsErrors = $this->settingsErrors();
        $cancelSubject = isset($settingsErrors['cancel_subject']['value']) ? esc_html($settingsErrors['cancel_subject']['value']) : $this->serviceOptions->cancel_subject;
        ?>
        <input type="text" name="<?php printf('%s[cancel_subject]', $this->optionName); ?>" value="<?php echo $cancelSubject; ?>" id="rrze_rsvp_cancel_subject" class="regular-text">
        <?php
    }

    public function cancelTextField()
    {
        $settingsErrors = $this->settingsErrors();
        $cancelText = isset($settingsErrors['cancel_text']['value']) ? esc_textarea($settingsErrors['cancel_text']['value']) : $this->serviceOptions->cancel_text;
        ?>
        <textarea cols="50" rows="5" name="<?php printf('%s[cancel_text]', $this->optionName); ?>" id="rrze_rsvp_cancel_text"><?php echo $cancelText; ?></textarea>
        <?php
    }    
}
