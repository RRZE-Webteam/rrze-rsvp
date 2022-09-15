<?php

namespace RRZE\RSVP\Shortcodes;

defined('ABSPATH') || exit;

use RRZE\RSVP\Auth\{IdM, LDAP};

/**
 * Laden und definieren der Shortcodes
 */
class Shortcodes
{
    public function __construct()
    {
        //
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
        $idm = new IdM;
        $ldap = new LDAP;

        $emailError = filter_input(INPUT_GET, 'email_error', FILTER_VALIDATE_INT);
        $emailError = ($emailError ? '<p class="error-message">' . __('Please login to the account you have used to book this seat.', 'rrze-rsvp') . '</p><br><br>' : '');

        $ldapError = '';
        if ($ldapRequired && isset($_POST['submit_ldap'])) {
            $ldap->login();
            if (!$ldap->isAuthenticated()) {
                $ldapError = '<p class="error-message">' . __('Login denied', 'rrze-rsvp') . '</p>';
            }
        }

        if ($ssoRequired && $idm->simplesamlAuth) {
            $loginUrl = $idm->getLoginURL();
            $idmLogin = sprintf(__('<a href="%s">Please login with your IdM username</a>.', 'rrze-rsvp'), $loginUrl);
        }

        $title = __('Authentication Required', 'rrze-rsvp');

        $output = '<div class="rrze-rsvp">';
        $output .= '<h2>' . $title . '</h2>';
        $output .= $emailError;

        $sOr = '';
        if ($ssoRequired) {
            $output .= '<p>' . $idmLogin . '</p>';
            $sOr = '<p><strong>' . __('Oder', 'rrze-rsvp') . '</strong></p>';
        }

        if ($ldapRequired) {
            $output .= $sOr . '<p>' . __('Please login with your UB-AD username and password:', 'rrze-rsvp') . '</p>';
            $output .= $ldapError;
            $output .= '<form action="#" method="POST">';
            $output .= '<label for="username">' . __('Username:', 'rrze-rsvp') . '</label><input id="username" type="text" name="username" value="" /><br />';
            $output .= '<label for="password">' . __('Password:', 'rrze-rsvp') . '</label><input id="password" type="password" name="password"  value="" />';
            $output .= '<input type="submit" name="submit_ldap" value="' . __('Submit', 'rrze-rsvp') . '" />';
            $output .= '</form>';
        }

        $output .= '</div>';
        return $output;
    }
}
