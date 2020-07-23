<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

class Settings
{
    public $settingsErrorTransient = '';
    protected $settingsErrorTransientExpiration = 30;

    public $noticeTransient = '';
    protected $noticeTransientExpiration = 30;    

    public function __construct()
    {
        add_action('admin_notices', [$this, 'adminNotices']);
    }

    public function adminNotices()
    {
        $this->displayAdminNotices();
    }

    protected function addAdminNotice($message, $class = 'updated')
    {
        $allowed_classes = array('error', 'updated');
        if (!in_array($class, $allowed_classes)) {
            $class = 'updated';
        }

        $transient = $this->noticeTransient . get_current_user_id();
        $transientValue = get_transient($transient);
        $notices = maybe_unserialize($transientValue ? $transientValue : []);
        $notices[$class][] = $message;

        set_transient($transient, $notices, $this->noticeTransientExpiration);
    }

    protected function displayAdminNotices()
    {
        $transient = $this->noticeTransient . get_current_user_id();
        $transientValue = get_transient($transient);
        $notices = maybe_unserialize($transientValue ? $transientValue : '');

        if (is_array($notices)) {
            foreach ($notices as $class => $messages) {
                foreach ($messages as $message) :
                    ?>
                    <div class="<?php echo $class; ?>">
                        <p><?php echo $message; ?></p>
                    </div>
                    <?php
                endforeach;
            }
        }

        delete_transient($transient);
    }

    protected function addSettingsError($field, $value = '', $message = '', $error = true)
    {
        $transient = $this->settingsErrorTransient . get_current_user_id();
        $transientValue = get_transient($transient);
        $errors = maybe_unserialize($transientValue ? $transientValue : []);
        $errors[$field] = array('value' => $value, 'message' => $message, 'error' => $error);

        set_transient($transient, $errors, $this->settingsErrorTransientExpiration);
    }

    protected function settingsErrors()
    {
        $transient = $this->settingsErrorTransient . get_current_user_id();
        $transientValue = get_transient($transient);
        $errors = (array) maybe_unserialize($transientValue ? $transientValue : '');

        foreach ($errors as $error) {
            if (!empty($error['error'])) {
                return $errors;
            }
        }

        return false;
    }

    public function deleteSettingsErrors()
    {
        delete_transient($this->settingsErrorTransient . get_current_user_id());
    }
}
