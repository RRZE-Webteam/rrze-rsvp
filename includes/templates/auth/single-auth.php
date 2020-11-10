<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

$idm = new IdM;
// $ldapInstance = new LDAP;
$template = new Template;

$roomId = isset($_GET['room_id']) ? absint($_GET['room_id']) : null;
$room = $roomId ? sprintf('?room_id=%d', $roomId) : '';
$seat = isset($_GET['seat_id']) ? sprintf('&seat_id=%d', absint($_GET['seat_id'])) : '';
$bookingDate = isset($_GET['bookingdate']) ? sprintf('&bookingdate=%s', sanitize_text_field($_GET['bookingdate'])) : '';
$timeslot = isset($_GET['timeslot']) ? sprintf('&timeslot=%s', sanitize_text_field($_GET['timeslot'])) : '';
$nonce = isset($_GET['nonce']) ? sprintf('&nonce=%s', sanitize_text_field($_GET['nonce'])) : '';        

$bookingId = isset($_GET['id']) && !$roomId ? sprintf('?id=%s', absint($_GET['id'])) : '';
$action = isset($_GET['action']) && !$roomId ? sprintf('&action=%s', sanitize_text_field($_GET['action'])) : '';

$loginDenied = '';
// if (isset($_POST['submit_ldap'])){
//     $mail = $ldapInstance->getEmail();
//     if ($mail){
//         $mail = '&mail=' . $mail;
//         $redirectUrl = sprintf('%s%s%s%s%s%s%s%s%s', trailingslashit(get_permalink()), $bookingId, $action, $room, $seat, $bookingDate, $timeslot, $nonce, $mail);
//         wp_redirect($redirectUrl);
//         exit;
//     }else{
//         $loginDenied = '<br><p class="error-message">' . __('Login denied', 'rrze-rsvp') . '</p>';
//     }
// }

if ($idm->simplesamlAuth() && $idm->simplesamlAuth->isAuthenticated()) {
    $redirectUrl = sprintf('%s%s%s%s%s%s%s%s', trailingslashit(get_permalink()), $bookingId, $action, $room, $seat, $bookingDate, $timeslot, $nonce);
    wp_redirect($redirectUrl);
    exit;
}



$data = [];

if ($idm->simplesamlAuth()) {
    $loginUrl = $idm->simplesamlAuth->getLoginURL();
    $idmLogin = sprintf(__('<a href="%s">Please login with your IdM username</a>.', 'rrze-rsvp'), $loginUrl);
}else {
    // header('HTTP/1.0 403 Forbidden');
    // wp_redirect(get_site_url());
    // exit;
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
DIVEND;

$sOr = '';
if (isset($_GET['require-sso-auth'])){
    echo "<p>$idmLogin</p>";
    $sOr = '<br><strong>' . __('Oder', 'rrze-rsvp') . '</strong><br>&nbsp;<br>';
}

$roomID = filter_input(INPUT_GET, 'room_id', FILTER_VALIDATE_INT, ['min_range' => 1]);

// if ($roomID){
//     $ldapRequired = Functions::getBoolValueFromAtt(get_post_meta($roomID, 'rrze-rsvp-room-ldap-required', true));
//     if ($ldapRequired){
//         $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
//         $password = filter_input(INPUT_POST, 'password', FILTER_SANITIZE_STRING);
//         $headline = $sOr . __('Please login with your UB-AD username', 'rrze-rsvp') . ':' . $loginDenied;
//         echo <<<FORMEND
//         $headline
//             <form action="#" method="POST">
//     			<label for="username">Username: </label><input id="username" type="text" name="username" value="$username" />
//     			<label for="password">Password: </label><input id="password" type="password" name="password"  value="$password" />
//     			<input type="submit" name="submit_ldap" value="Submit" />
//             </form>
// FORMEND;
//     }
// }

echo $divClose;

wp_enqueue_style('rrze-rsvp-shortcode');

get_footer();
