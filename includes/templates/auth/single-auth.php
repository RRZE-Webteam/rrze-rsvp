<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use RRZE\RSVP\Auth\{IdM, LDAP};

$idmAuthenticator = new IdM;
$ldapAuthenticator = new LDAP;
$settings = new Settings(plugin()->getFile());

$emailErrorHtml = '';
$ldapLoginErrorHtml = '';
$idmLoginLinkHtml = '';
$authenticationSeparatorHtml = '';
$pageWrapperOpeningHtml = '';
$pageWrapperClosingHtml = '';
$pageTitle = __('Authentication Required', 'rrze-rsvp');

$emailErrorCode = filter_input(INPUT_GET, 'email_error', FILTER_VALIDATE_INT);
if ($emailErrorCode) {
    $emailErrorHtml = '<p class="error-message">' . __('Please login to the account you have used to book this seat.', 'rrze-rsvp') . '</p><br><br>';
}

$roomId = isset($_GET['room_id']) ? absint($_GET['room_id']) : 0;
if (!$roomId && isset($_GET['id'])) {
    // get room ID from booking via seat
    $bookingId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $seatId = get_post_meta($bookingId, 'rrze-rsvp-booking-seat', true);
    $roomId = get_post_meta($seatId, 'rrze-rsvp-seat-room', true);
}

$isSsoRequired = Functions::getBoolValueFromAtt(get_post_meta($roomId, 'rrze-rsvp-room-sso-required', true));
$isLdapRequired = Functions::getBoolValueFromAtt(get_post_meta($roomId, 'rrze-rsvp-room-ldap-required', true));
$isLdapLoginAvailable = $isLdapRequired && (bool) $settings->getOption('ldap', 'server');

if ($isLdapLoginAvailable && isset($_POST['submit_ldap'])) {
    $ldapAuthenticator->login();
    if ($ldapAuthenticator->isAuthenticated()) {
        $queryString = Functions::getQueryStr([], ['require-auth']);
        $redirectUrl = trailingslashit(get_permalink()) . ($queryString ? '?' . $queryString : '');
        wp_redirect($redirectUrl);
        exit;
    } else {
        $ldapLoginErrorHtml = '<br><p class="error-message">' . __('Login denied', 'rrze-rsvp') . '</p>';
    }
}

if ($isSsoRequired && $idmAuthenticator->simplesamlAuth) {
    $idmLoginUrl = $idmAuthenticator->getLoginURL();
    $idmLoginLinkHtml = sprintf(__('<a href="%s">Please login with your IdM username</a>.', 'rrze-rsvp'), $idmLoginUrl);
}

get_header();

/*
 * div-/Seitenstruktur für FAU- und andere Themes
 */
if (Helper::isFauTheme()) {
    get_template_part('template-parts/hero', 'small');
    $pageWrapperOpeningHtml = '<div id="content">
        <div class="container">
            <div class="row">
                <div class="col-xs-12">
                    <main id="droppoint">
                        <h1 class="screen-reader-text">' . get_the_title() . '</h1>
                        <div class="inline-box">
                            <div class="content-inline">';
    $pageWrapperClosingHtml = '</div>
                        </div>
                    </main>
                </div>
            </div>
        </div>
    </div>';
} else {
    $pageWrapperOpeningHtml = '<div id="content">
        <div class="container">
            <div class="row">
                <div class="col-xs-12">
                <h1 class="entry-title">' . get_the_title() . '</h1>';
    $pageWrapperClosingHtml = '</div>
            </div>
        </div>
    </div>';
}


/*
 * Eigentlicher Content
 */
echo $pageWrapperOpeningHtml;

echo <<<DIVEND
<div class="rrze-rsvp-booking-reply rrze-rsvp">
    <div class="container">    
        <h2>$pageTitle</h2>
        $emailErrorHtml
DIVEND;

if ($idmLoginLinkHtml !== '') {
    echo "<p>$idmLoginLinkHtml</p>";
    $authenticationSeparatorHtml = '<br><strong>' . __('Oder', 'rrze-rsvp') . '</strong><br>&nbsp;<br>';
}

if ($isLdapLoginAvailable) {
    $ldapLoginHeadingHtml = $authenticationSeparatorHtml . __('Please login with your UB-AD username', 'rrze-rsvp') . ':' . $ldapLoginErrorHtml;
    echo <<<FORMEND
    $ldapLoginHeadingHtml
        <form action="#" method="POST">
            <label for="username">Username: </label><input id="username" type="text" name="username" value="" />
            <label for="password">Password: </label><input id="password" type="password" name="password"  value="" />
            <input type="submit" name="submit_ldap" value="Submit" />
        </form>
FORMEND;
}

echo $pageWrapperClosingHtml;

wp_enqueue_style('rrze-rsvp-shortcode');

get_footer();
