<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use RRZE\RSVP\Functions;

class Actions
{
	protected $email;

	protected $template;

	public function __construct()
	{
		$this->email = new Email;
		$this->template = new Template;
	}

	public function onLoaded()
	{
		add_action('admin_init', [$this, 'handleActions']);
		add_action('wp_ajax_booking_action', [$this, 'ajaxBookingAction']);
		add_action('transition_post_status', [$this, 'transitionPostStatus'], 10, 3);
		add_action('template_include', [$this, 'bookingReplyTemplate']);
	}

	public function ajaxBookingAction()
	{
		$bookingId = absint($_POST['id']);
		$action = sanitize_text_field($_POST['type']);

		$booking = Functions::getBooking($bookingId);
		if (!$booking) {
			$this->ajaxResult(['result' => false]);
		}
		$forceToConfirm = get_post_meta($booking['room'], 'rrze-rsvp-room-force-to-confirm', true);

		if ($action == 'confirm') {
			update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'confirmed');
			update_post_meta($bookingId, 'rrze-rsvp-customer-status', '');
			if ($forceToConfirm) {
				update_post_meta($bookingId, 'rrze-rsvp-customer-status', 'booked');
				$this->email->bookingRequestedCustomer($bookingId);
			} else {
				$this->email->bookingConfirmedCustomer($bookingId);
			}
		} else if ($action == 'cancel') {
			update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'cancelled');
			$this->email->bookingCancelledCustomer($bookingId);
		} else {
			$this->ajaxResult(['result' => false]);
		}

		$this->ajaxResult(['result' => true]);
	}

	public function handleActions()
	{
		if (isset($_GET['action']) && isset($_GET['id']) && wp_verify_nonce($_REQUEST['_wpnonce'], 'status')) {
			$bookingId = absint($_GET['id']);
			$action = sanitize_text_field($_GET['action']);

			$booking = Functions::getBooking($bookingId);
			if (!$booking) {
				return;
			}
			$forceToConfirm = get_post_meta($booking['room'], 'rrze-rsvp-room-force-to-confirm', true);

			if ($action == 'confirm') {
				update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'confirmed');
				update_post_meta($bookingId, 'rrze-rsvp-customer-status', '');
				if ($forceToConfirm) {
					update_post_meta($bookingId, 'rrze-rsvp-customer-status', 'booked');
				}
			} elseif ($action == 'cancel') {
				update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'cancelled');
			} elseif ($action == 'delete') {
				wp_delete_post($bookingId);
			} elseif ($action == 'restore') {
				update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'booked');
			}

			wp_redirect(get_admin_url() . 'edit.php?post_type=booking');
			exit;
		}
	}

	public function transitionPostStatus($newStatus, $oldStatus, $post)
	{
		if (get_post_type($post) != 'booking') {
			return;
		}

		if ('publish' != $newStatus || 'publish' != $oldStatus) {
			return;
		}

		$bookingId = $post->ID;

		$bookingStatus = isset($_POST['rrze-rsvp-booking-status']) ? $_POST['rrze-rsvp-booking-status'] : '';

		$booking = Functions::getBooking($bookingId);
		$bookingBooked = ($booking['status'] == 'booked');
		$bookingConfirmed = ($booking['status'] == 'confirmed');
		$bookingCancelled = ($booking['status'] == 'cancelled');
		$forceToConfirm = get_post_meta($booking['room'], 'rrze-rsvp-room-force-to-confirm', true);

		if (($bookingBooked || $bookingCancelled) && $bookingStatus == 'confirmed') {
			update_post_meta($bookingId, 'rrze-rsvp-booking-status', $bookingStatus);
			update_post_meta($bookingId, 'rrze-rsvp-customer-status', '');
			if ($forceToConfirm) {
				update_post_meta($bookingId, 'rrze-rsvp-customer-status', 'booked');
				$this->email->bookingRequestedCustomer($bookingId);
			} else {
				$this->email->bookingConfirmedCustomer($bookingId);
			}
		} else if (($bookingBooked || $bookingConfirmed) && $bookingStatus == 'cancelled') {
			update_post_meta($bookingId, 'rrze-rsvp-booking-status', $bookingStatus);
			$this->email->bookingCancelledCustomer($bookingId);
		}
	}

	public function bookingReplyTemplate($template)
	{
		$bookingId = isset($_GET['id']) ? absint($_GET['id']) : false;
		$action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : false;
		$hash = isset($_GET['rrze-rsvp-booking-reply']) ? sanitize_text_field($_GET['rrze-rsvp-booking-reply']) : false;

		if ($hash !== false && $bookingId !== false && $action !== false) {
			$booking = Functions::getBooking($bookingId);
			$nonce = $booking ? sprintf('%s-%s', $bookingId, $booking['start']) : '';
			$decryptedHash = Functions::decrypt($hash);
			$isAdmin =  $decryptedHash == $nonce ? true : false;
			$isCustomer =  $decryptedHash == $nonce . '-customer' ? true : false;

			$bookingCancelled = ($booking['status'] == 'cancelled');

			if (($action == 'confirm' || $action == 'cancel') && $isAdmin) {
				return $this->bookingReplyAdminTemplate($bookingId, $booking, $action);
			} elseif (($action == 'confirm' || $action == 'checkin' || $action == 'checkout' || $action == 'cancel' || $action == 'ics') && $isCustomer) {
				if ($bookingCancelled) {
					$action = 'cancel';
				} elseif ($action == 'ics') {
					ICS::generate($bookingId);
					exit;
				}
				return $this->bookingReplyCustomer($bookingId, $booking, $action);
			}

			header('HTTP/1.0 403 Forbidden');
			wp_redirect(get_site_url());
			exit;
		}

		return $template;
	}

	protected function bookingReplyAdminTemplate(int $bookingId, array $booking, string $action)
	{
		$forceToConfirm = get_post_meta($booking['room'], 'rrze-rsvp-room-force-to-confirm', true);

		if ($booking['status'] == 'booked') {
			if ($action == 'confirm') {
				update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'confirmed');
				$action = __('confirmed', 'rrze-rsvp');
				if ($forceToConfirm) {
					$this->email->bookingRequestedCustomer($bookingId);
				} else {
					$this->email->bookingConfirmedCustomer($bookingId);
				}
			} else if ($action == 'cancel') {
				update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'cancelled');
				$action = __('cancelled', 'rrze-rsvp');
				$this->email->bookingCancelledCustomer($bookingId);
			}
			$processed = false;
		} else {
			$processed = true;
			$action = ($booking['status'] == 'confirmed') ? __('confirmed', 'rrze-rsvp') : __('cancelled', 'rrze-rsvp');
		}

		$customerName = sprintf('%s: %s %s', __('Name', 'rrze-rsvp'), $booking['guest_firstname'], $booking['guest_lastname']);
		$customerEmail = sprintf('%s: %s', __('Email', 'rrze-rsvp'), $booking['guest_email']);
		$customerPhone = sprintf('%s: %s', __('Phone', 'rrze-rsvp'), $booking['guest_phone']);

		$data = [];
		if ($processed) {
			$data['processed'] = true;
		}

		$data['booking_title'] = __('Booking', 'rrze-rsvp');

		$data['booking_has_already_been'] = sprintf(__('The booking has already been %s.', 'rrze-rsvp'), $action);
		$data['booking_has_been'] = sprintf(__('The booking has been %s.', 'rrze-rsvp'), $action);
		$data['customer_has_received_an_email'] = __('Your customer has received an email confirmation.', 'rrze-rsvp');

		$data['class_cancelled'] = ($action == 'canncel') ? 'cancelled' : '';

		$data['room_name'] = $booking['room_name'];

		$data['date'] = $booking['date'];
		$data['time'] = $booking['time'];

		$data['customer']['name'] = $customerName;
		$data['customer']['email'] = $customerEmail;
		$data['customer']['phone'] = $customerPhone;

		$data['backlink'] = sprintf('<a class="backlink" href="%s">&larr; %s</a>', get_bloginfo('url'), get_bloginfo('name'));

		wp_enqueue_style('rrze-rsvp-booking-reply', plugins_url('assets/css/rrze-rsvp.css', plugin()->getBasename(), [], plugin()->getVersion()));

		get_header();
		echo $this->template->getContent('reply/booking-reply-admin', $data);
		get_footer();
	}

	protected function bookingReplyCustomer(int $bookingId, array $booking, string $action)
	{
		$start = $booking['start'];
		$end = $booking['end'];
		$now = current_time('timestamp');
		$userConfirmed = (get_post_meta($bookingId, 'rrze-rsvp-customer-status', true) == 'confirmed');
		$bookingConfirmed = ($booking['status'] == 'confirmed');
		$bookingCkeckedIn = ($booking['status'] == 'checked-in');
		$bookingCkeckedOut = ($booking['status'] == 'checked-out');
		$bookingCancelled = ($booking['status'] == 'cancelled');

		if (!$bookingCancelled && !$userConfirmed && $action == 'confirm') {
			update_post_meta($bookingId, 'rrze-rsvp-customer-status', 'confirmed');
			$this->email->bookingConfirmedCustomer($bookingId);
			$userConfirmed = true;
		} elseif (!$bookingCancelled && !$bookingCkeckedIn && $action == 'cancel') {
			update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'cancelled');
			$this->email->bookingCancelledAdmin($bookingId);
			$bookingCancelled = true;
		} elseif (!$bookingCancelled && !$bookingCkeckedIn && $bookingConfirmed && $action == 'checkin') {
			if ($start <= $now && $end >= $now) {
				update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'checked-in');
				$bookingCkeckedIn = true;
			}
		} elseif (!$bookingCancelled && !$bookingCkeckedOut && $bookingCkeckedIn && $action == 'checkout') {
			if ($start <= $now && $end >= $now) {
				update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'checked-out');
				$bookingCkeckedOut = true;
			}
		}

		$siteUrl = site_url();
		$siteName = get_bloginfo('name');

		$data = [];
		$isLocaleEnglish = Functions::isLocaleEnglish();
		if (!$isLocaleEnglish) $data['is_locale_not_english'] = true;

		$data['booking_title'] = __('Booking', 'rrze-rsvp');
		$data['booking_title_en'] = 'Booking';

		$data['backlink'] = sprintf('<a class="backlink" href="%s">&larr; %s</a>', $siteUrl, $siteName);

		if (!$bookingCancelled && !$bookingCkeckedIn && $action == 'cancel') {
			$data['really_want_to_cancel_the_booking'] = __('Do you really want to cancel your booking?', 'rrze-rsvp');
			$data['class_cancelled'] = ($action == 'canncel') ? 'cancelled' : '';

			$data['room_name'] = $booking['room_name'];

			$data['date'] = $booking['date'];
			$data['time'] = $booking['time'];
			$data['date_en'] = $booking['date_en'];
			$data['time_en'] = $booking['time_en'];

			$cancelUrl = Functions::bookingReplyUrl('cancel', sprintf('%s-%s-customer', $bookingId, $booking['start']), $bookingId);
			$data['cancel_booking'] = sprintf(__('<a href="%s" class="button button-cancel">Cancel Your Booking</a>.', 'rrze-rsvp'), $cancelUrl);
			$data['cancel_booking_en'] = sprintf('<a href="%s" class="button button-cancel">Cancel Your Booking</a>.', $cancelUrl);
		} elseif ($bookingCancelled && $action == 'cancel') {
			$data['booking_has_been_cancelled'] = __('Your booking has been cancelled. Please contact us to find a different arrangement.', 'rrze-rsvp');
			$data['booking_has_been_cancelled_en'] = 'Your booking has been cancelled. Please contact us to find a different arrangement.';
		} elseif ($userConfirmed && $action == 'confirm') {
			$data['thank_for_confirming'] = __('Thank you for confirming your booking.', 'rrze-rsvp');
			$data['thank_for_confirming_en'] = 'Thank you for confirming your booking.';
		} elseif (!$bookingCkeckedIn && $action == 'checkin') {
			$data['checkin_is_not_possible'] = __('Check-in is not possible at this time.', 'rrze-rsvp');
			$data['checkin_is_not_possible_en'] = 'Check-in is not possible at this time.';
		} elseif ($bookingCkeckedIn && $action == 'checkin') {
			$data['checkin_has_been_completed'] = __('Check-in has been completed.', 'rrze-rsvp');
			$data['checkin_has_been_completed_en'] = 'Check-in has been completed.';
		} elseif (!$bookingCkeckedOut && $action == 'checkout') {
			$data['checkout_is_not_possible'] = __('Check-out is not possible at this time.', 'rrze-rsvp');
			$data['checkout_is_not_possible_en'] = 'Check-out is not possible at this time.';
		} elseif ($bookingCkeckedOut && $action == 'checkout') {
			$data['checkout_has_been_completed'] = __('Check-out has been completed.', 'rrze-rsvp');
			$data['checkout_has_been_completed_en'] = 'Check-out has been completed.';
		} else {
			$data['no_action_was_taken'] = __('No action was taken.', 'rrze-rsvp');
			$data['no_action_was_taken_en'] = 'No action was taken.';
		}

		wp_enqueue_style('rrze-rsvp-booking-reply', plugins_url('assets/css/rrze-rsvp.css', plugin()->getBasename(), [], plugin()->getVersion()));

		get_header();
		echo $this->template->getContent('reply/booking-reply-customer', $data);
		get_footer();
	}

	protected function ajaxResult(array $returnAry)
	{
		echo json_encode($returnAry);
		exit;
	}
}
