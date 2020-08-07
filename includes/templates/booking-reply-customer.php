<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use RRZE\RSVP\Email;
use RRZE\RSVP\Functions;

$email = new Email;
$bookingId = absint($_GET['id']);
$action = sanitize_text_field($_GET['action']);
$hash = isset($_GET['rrze-rsvp-booking-reply']) ? sanitize_text_field($_GET['rrze-rsvp-booking-reply']) : false;

$booking = Functions::getBooking($bookingId);

if (!$booking || ! Functions::decrypt($hash)) {
	header('HTTP/1.0 403 Forbidden');
	wp_redirect(get_site_url());
	exit;
}

$start = $booking['start'];
$end = $booking['end'];
$now = current_time('timestamp');
$userConfirmed = (get_post_meta($bookingId, 'rrze-rsvp-customer-status', true) == 'confirmed');
$bookingConfirmed = ($booking['status'] == 'confirmed');
$bookingCkeckedIn = ($booking['status'] == 'checked-in');
$bookingCkeckedOut = ($booking['status'] == 'checked-out');
$bookingCancelled = ($booking['status'] == 'cancelled');

$replyUrl = Functions::bookingReplyUrl($action, sprintf('%s-%s-customer', $bookingId, $booking['start']), $bookingId);

if (! $userConfirmed && $action == 'confirm') {
	update_post_meta($bookingId, 'rrze-rsvp-customer-status', 'confirmed');
	$userConfirmed = true;
} elseif (! $bookingCancelled && ! $bookingCkeckedIn && $action == 'cancel') {
	update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'cancelled');
	$email->bookingCancelledAdmin($bookingId);
	$bookingCancelled = true;
} elseif (! $bookingCkeckedIn && $bookingConfirmed && $action == 'checkin') {
	if ($start <= $now && $end >= $now) {
		update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'checked-in');
		$bookingCkeckedIn = true;
	}
} elseif (! $bookingCkeckedOut && $bookingCkeckedIn && $action == 'checkout') {
	if ($start <= $now && $end >= $now) {
		update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'checked-out');
		$bookingCkeckedOut = true;
	}
}

$isLocaleEnglish = Functions::isLocaleEnglish();

get_header();
?>

<div class="rrze-rsvp-booking-reply">
	<div class="container">
		<h1><?php _e('Booking', 'rrze-rsvp'); ?></h1>

		<?php if (! $bookingCancelled && ! $bookingCkeckedIn && $action == 'cancel') { ?>

			<p><?php _e('Do you really want to cancel your booking?', 'rrze-rsvp'); ?></p>
			<?php if (! $isLocaleEnglish) { ?>
				<p><?php echo 'Do you really want to cancel your booking?'; ?></p>
			<?php
			}
			?>
			<p class="date <?php if ($_GET['action'] == "cancel") echo 'cancelled'; ?>">
				<?php echo get_the_title($booking['room']); ?><br>
				<?php echo $booking['date']; ?><br>
				<?php echo $booking['time']; ?>
			</p>

			<a href="<?php echo $replyUrl; ?>" class="button button-delete"><?php _e('Cancel Booking', 'rrze-rsvp'); ?></a>

		<?php
		} elseif ($bookingCancelled && $action == 'cancel') {
			_e('Your booking has been cancelled. Please contact us to find a different arrangement.', 'rrze-rsvp');
		} elseif ($userConfirmed && $action == 'confirm') {
			_e('Thank you for confirming your booking.', 'rrze-rsvp');
		} elseif (! $bookingCkeckedIn && $action == 'checkin') {
			_e('Check-in is not possible at this time.', 'rrze-rsvp');
		} elseif ($bookingCkeckedIn && $action == 'checkin') {
			_e('Check-in has been completed.', 'rrze-rsvp');
		} elseif (! $bookingCkeckedOut && $action == 'checkout') {
			_e('Check-out is not possible at this time.', 'rrze-rsvp');
		} elseif ($bookingCkeckedOut && $action == 'checkout') {
			_e('Check-out has been completed.', 'rrze-rsvp');
		} else {
			_e('No action was taken.', 'rrze-rsvp');
		}
		?>

	</div>
	<?php echo '<a class="backlink" href="' . get_bloginfo('url') . '">&larr; ' . __('Go to', 'rrze-rsvp') . ' ' . get_bloginfo('name') . '</a>'; ?>
</div>

<?php get_footer();
