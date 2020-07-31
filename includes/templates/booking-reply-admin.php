<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use RRZE\RSVP\Functions;

$email = new Email;
$bookingId = intval($_GET['id']);
$action = sanitize_text_field($_GET['action']);

$bookingData = Functions::getBooking($bookingId);

if (!$bookingData || !password_verify($bookingData['booking_date'], $_GET['rrze-rsvp-booking-reply'])) {
	header('HTTP/1.0 403 Forbidden');
	wp_redirect(get_site_url());
	exit;
}

if ($bookingData['status'] == 'notconfirmed') {
	if ($_GET['action'] == "confirm") {
		update_post_meta($bookingId, 'rrze_rsvp_status', 'confirmed');
		$action = __('confirmed', 'rrze-rsvp');
		$email->bookingCancelled($bookingId);
	} else if ($_GET['action'] == "cancel") {
		update_post_meta($bookingId, 'rrze_rsvp_status', 'cancelled');
		$action = __('cancelled', 'rrze-rsvp');
		$email->bookingCancelled($bookingId);
	}
	$bookingProcessed = false;
} else {
	$bookingProcessed = true;
	$action = ($bookingData['status'] == 'confirmed') ? __('confirmed', 'rrze-rsvp') : __('cancelled', 'rrze-rsvp');
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
				<?php _e('Your customer has received an e-mail confirmation', 'rrze-rsvp'); ?>
			</p>

			<p class="date <?php if ($_GET['action'] == "cancel") echo 'cancelled'; ?>">
				<?php echo ($bookingData['serviceName']) ? $bookingData['serviceName'] . '<br>' : ''; ?>
				<?php echo $bookingData['date']; ?><br />
				<?php echo $bookingData['time']; ?>
			</p>
			<p class="user-info">
				<?php
				$bookingFields = Functions::getBookingFields($bookingId);
				echo Functions::dataToStr($bookingFields);
				?>
			</p>

			<?php if ($_GET['action'] == "confirm") { ?>
				<form>
					<input type="hidden" name="rrze-rsvp-booking-reply" value="<?php echo esc_attr($_GET['rrze-rsvp-booking-reply']); ?>" />
					<input type="hidden" name="id" value="<?php echo esc_attr($bookingId); ?>" />
					<input type="hidden" name="action" value="<?php echo esc_attr($_GET['action']); ?>" />
					<input type="hidden" name="ics" value="true" />
					<input type="submit" class="button button-accept" value="<?php _e('Download Calendar (ics) File', 'rrze-rsvp'); ?>">
				</form>
			<?php } ?>

		<?php } ?>

	</div>
	<?php echo '<a class="backlink" href="' . get_bloginfo('url') . '">&larr; ' . __('Go to', 'rrze-rsvp') . ' ' . get_bloginfo('name') . '</a>'; ?>
</div>

<?php get_footer();
