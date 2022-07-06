<?php

namespace RRZE\RSVP\Shortcodes;

defined('ABSPATH') || exit;

use RRZE\RSVP\Auth\{IdM, LDAP};

/**
 * Laden und definieren der Shortcodes
 */
class Shortcodes
{
    protected $idm;

    protected $ldap;

    public function __construct()
    {
        $this->idm = new IdM;
        $this->ldap = new LDAP;
    }

    public function shortcodeAtts($atts, $tag, $settings)
    {
        // Merge given attributes with default ones.
        $defaultAtts = [];
        foreach ($settings as $tagname => $settings) {
            foreach ($settings as $k => $v) {
                if ($k != 'block') {
                    $defaultAtts[$tagname][$k] = $v['default'];
                }
            }
        }
        return shortcode_atts($defaultAtts[$tag], $atts);
    }

    protected function authForm($ssoRequired = false, $ldapRequired = false)
    {
        $emailError = filter_input(INPUT_GET, 'email_error', FILTER_VALIDATE_INT);
        $emailError = ($emailError ? '<p class="error-message">' . __('Please login to the account you have used to book this seat.', 'rrze-rsvp') . '</p><br><br>' : '');

        $ldapError = '';
        if ($ldapRequired && isset($_POST['submit_ldap'])) {
            $this->ldap->login();
            if (!$this->ldap->isAuthenticated()) {
                $ldapError = '<br><p class="error-message">' . __('Login denied', 'rrze-rsvp') . '</p>';
            }
        }

        if ($ssoRequired && $this->idm->simplesamlAuth) {
            $loginUrl = $this->idm->getLoginURL();
            $idmLogin = sprintf(__('<a href="%s">Please login with your IdM username</a>.', 'rrze-rsvp'), $loginUrl);
        }

        $title = __('Authentication Required', 'rrze-rsvp');

        $output = '<div class="rrze-rsvp">';
        $output .= '<h2>' . $title . '</h2>';
        $output .= $emailError;

        $sOr = '';
        if ($ssoRequired) {
            $output .= '<p>' . $idmLogin . '</p>';
            $sOr = '<br><strong>' . __('Oder', 'rrze-rsvp') . '</strong><br>&nbsp;<br>';
        }

        if ($ldapRequired) {
            $output .= $sOr . __('Please login with your UB-AD username', 'rrze-rsvp') . ':' . $ldapError;

            $output .= '<form action="#" method="POST">';
            $output .= '<label for="username">Username: </label><input id="username" type="text" name="username" value="" />';
            $output .= '<label for="password">Password: </label><input id="password" type="password" name="password"  value="" />';
            $output .= '<input type="submit" name="submit_ldap" value="Submit" />';
            $output .= '</form>';
        }

        $output .= '</div>';
        return $output;
    }
}
