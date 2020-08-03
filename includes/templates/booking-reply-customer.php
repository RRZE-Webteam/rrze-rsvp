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

$bookingCancelled = ($booking['status'] === 'cancelled');

$replyUrl = Functions::bookingReplyUrl($action, sprintf('%s-%s-customer', $bookingId, $booking['start']), $bookingId);

if (! $bookingCancelled && $action == 'cancel') {
	update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'cancelled');
	$email->bookingCancelledCustomer($bookingId);
	$bookingCancelled = true;
}

get_header();
?>

<div class="rrze-rsvp-booking-reply">
	<div class="container">
		<h1><?php _e('Booking', 'rrze-rsvp'); ?></h1>

		<?php if (!$bookingCancelled) { ?>

			<p><?php _e('Do you really want to cancel your booking?', 'rrze-rsvp'); ?></p>

			<p class="date <?php if ($_GET['action'] == "cancel") echo 'cancelled'; ?>">
				<?php echo get_the_title($booking['room']); ?><br>
				<?php echo $booking['date']; ?><br>
				<?php echo $booking['time']; ?>
			</p>

			<a href="<?php echo $replyUrl; ?>" class="button button-delete"><?php _e('Cancel booking', 'rrze-rsvp'); ?></a>

		<?php
		} else {
			_e('Your booking has been cancelled. Please contact us to find a different arrangement.', 'rrze-rsvp');
		}
		?>

	</div>
	<?php echo '<a class="backlink" href="' . get_bloginfo('url') . '">&larr; ' . __('Go to', 'rrze-rsvp') . ' ' . get_bloginfo('name') . '</a>'; ?>
</div>

<?php get_footer();
