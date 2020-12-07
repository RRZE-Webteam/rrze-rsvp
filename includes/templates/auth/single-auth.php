<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use RRZE\RSVP\Auth\{IdM, LDAP};

$idm = new IdM;
$ldapInstance = new LDAP;
$template = new Template;
$settings = new Settings(plugin()->getFile());

$email_error = filter_input(INPUT_GET, 'email_error', FILTER_VALIDATE_INT);
$email_error = ($email_error ? '<p class="error-message">' . __('Please login to the account you have used to book this seat.', 'rrze-rsvp') . '</p><br><br>' : '');


$roomID = isset($_GET['room_id']) ? absint($_GET['room_id']) : 0;
if (!$roomID && isset($_GET['id'])){
    // get room ID from booking via seat
    $bookingID = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $seatID = get_post_meta($bookingID, 'rrze-rsvp-booking-seat', true);
    $roomID = get_post_meta($seatID, 'rrze-rsvp-seat-room', true);
}

$ssoRequired = Functions::getBoolValueFromAtt(get_post_meta($roomID, 'rrze-rsvp-room-sso-required', true));
$ldapRequired = Functions::getBoolValueFromAtt(get_post_meta($roomID, 'rrze-rsvp-room-ldap-required', true));
$ldapRequired = $ldapRequired && $settings->getOption('ldap', 'server') ? true : false;

$loginDenied = '';
if ($ldapRequired && isset($_POST['submit_ldap'])) {
    $ldapInstance->login();
    if ($ldapInstance->isAuthenticated()) {
        $queryStr = Functions::getQueryStr([], ['require-auth']);
        $redirectUrl = trailingslashit(get_permalink()) . ($queryStr ? '?' . $queryStr : '');
        wp_redirect($redirectUrl);
        exit;
    } else {
        $loginDenied = '<br><p class="error-message">' . __('Login denied', 'rrze-rsvp') . '</p>';
    }
}

if ($ssoRequired && $idm->simplesamlAuth) {
    $loginUrl = $idm->getLoginURL();
    $idmLogin = sprintf(__('<a href="%s">Please login with your IdM username</a>.', 'rrze-rsvp'), $loginUrl);
}

get_header();

/*
 * div-/Seitenstruktur für FAU- und andere Themes
 */
if (Helper::isFauTheme()) {
    get_template_part('template-parts/hero', 'small');
    $divOpen = '<div id="content">
        <div class="container">
            <div class="row">
                <div class="col-xs-12">
                    <main id="droppoint">
                        <h1 class="screen-reader-text">' . get_the_title() . '</h1>
                        <div class="inline-box">
                            <div class="content-inline">';
    $divClose = '</div>
                        </div>
                    </main>
                </div>
            </div>
        </div>
    </div>';
} else {
    $divOpen = '<div id="content">
        <div class="container">
            <div class="row">
                <div class="col-xs-12">
                <h1 class="entry-title">' . get_the_title() . '</h1>';
    $divClose = '</div>
            </div>
        </div>
    </div>';
}


/*
 * Eigentlicher Content
 */
$title = __('Authentication Required', 'rrze-rsvp');
echo $divOpen;

echo <<<DIVEND
<div class="rrze-rsvp-booking-reply rrze-rsvp">
    <div class="container">    
        <h2>$title</h2>
        $email_error
DIVEND;

$sOr = '';
if ($ssoRequired) {
    echo "<p>$idmLogin</p>";
    $sOr = '<br><strong>' . __('Oder', 'rrze-rsvp') . '</strong><br>&nbsp;<br>';
}

if ($ldapRequired) {
    $headline = $sOr . __('Please login with your UB-AD username', 'rrze-rsvp') . ':' . $loginDenied;
    echo <<<FORMEND
    $headline
        <form action="#" method="POST">
            <label for="username">Username: </label><input id="username" type="text" name="username" value="" />
            <label for="password">Password: </label><input id="password" type="password" name="password"  value="" />
            <input type="submit" name="submit_ldap" value="Submit" />
        </form>
FORMEND;
}

echo $divClose;

wp_enqueue_style('rrze-rsvp-shortcode');

get_footer();
