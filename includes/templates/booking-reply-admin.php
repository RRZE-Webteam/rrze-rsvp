<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use RRZE\RSVP\Functions;

$email = new Email;
$bookingId = absint($_GET['id']);
$action = sanitize_text_field($_GET['action']);
$hash = isset($_GET['rrze-rsvp-booking-reply']) ? sanitize_text_field($_GET['rrze-rsvp-booking-reply']) : false;

$booking = Functions::getBooking($bookingId);

if (! $booking || ! Functions::decrypt($hash)) {
	header('HTTP/1.0 403 Forbidden');
	wp_redirect(get_site_url());
	exit;
}

if ($booking['status'] == 'booked') {
	if ($_GET['action'] == 'confirm') {
		update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'confirmed');
		$action = __('confirmed', 'rrze-rsvp');
		$email->bookingCancelledAdmin($bookingId);
	} else if ($_GET['action'] == 'cancel') {
		update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'cancelled');
		$action = __('cancelled', 'rrze-rsvp');
		$email->bookingCancelledAdmin($bookingId);
	}
	$bookingProcessed = false;
} else {
	$bookingProcessed = true;
	$action = ($booking['status'] == 'confirmed') ? __('confirmed', 'rrze-rsvp') : __('cancelled', 'rrze-rsvp');
}

get_header();
?>

<div class="rrze-rsvp-booking-reply">
	<div class="container">
		<h1><?php _e('Booking', 'rrze-rsvp'); ?></h1>

		<?php if ($bookingProcessed) { ?>

			<p><?php echo sprintf(__('The booking has already been %s.', 'rrze-rsvp'), $action); ?></p>

		<?php } else { ?>

			<p><?php echo sprintf(__('The booking has been %s.', 'rrze-rsvp'), $action); ?></p>

			<p>
				<?php _e('Your customer has received an email confirmation.', 'rrze-rsvp'); ?>
			</p>

			<p class="date <?php if ($_GET['action'] == 'cancel') echo 'cancelled'; ?>">
				<?php echo get_the_title($booking['room']); ?><br>
				<?php echo $booking['date']; ?><br />
				<?php echo $booking['time']; ?>
			</p>
			<p class="user-info">
				<?php
				printf('%s: %s %s<br>', __('Name', 'rrze-rsvp'), $booking['guest_firstname'], $booking['guest_lastname']);
				printf('%s: %s<br>', __('Email', 'rrze-rsvp'), $booking['guest_email']);
				printf('%s: %s<br>', __('Phone', 'rrze-rsvp'), $booking['guest_phone']);
				?>
			</p>

		<?php } ?>

	</div>
	<?php echo '<a class="backlink" href="' . get_bloginfo('url') . '">&larr; ' . __('Go to', 'rrze-rsvp') . ' ' . get_bloginfo('name') . '</a>'; ?>
</div>

<?php get_footer();
