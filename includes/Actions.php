<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use RRZE\RSVP\Carbon;

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
		add_action('wp', [$this, 'bookingReply']);
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

	public function bookingReply()
	{
        global $post;
        if (!is_a($post, '\WP_Post') || !is_page() || $post->post_name != "rsvp-booking") {
            return;
		}

		$bookingId = isset($_GET['id']) ? absint($_GET['id']) : false;
		$action = isset($_GET['action']) ? sanitize_text_field($_GET['action']) : false;
		$hash = isset($_GET['booking-reply']) ? sanitize_text_field($_GET['booking-reply']) : false;

		if (!$hash || !$bookingId || !$action) {
			return;
		}
		
		wp_enqueue_style(
			'rrze-rsvp-booking-reply', 
			plugins_url('assets/css/rrze-rsvp.css', plugin()->getBasename()), 
			[], 
			plugin()->getVersion()
		);

		$booking = Functions::getBooking($bookingId);
		$nonce = $booking ? sprintf('%s-%s', $bookingId, $booking['start']) : '';
		$decryptedHash = Functions::decrypt($hash);
		$isAdmin =  $decryptedHash == $nonce ? true : false;
		$isCustomer =  $decryptedHash == $nonce . '-customer' ? true : false;

		$bookingCancelled = ($booking['status'] == 'cancelled');

		if (($action == 'confirm' || $action == 'cancel') && $isAdmin) {
			$this->bookingReplyAdmin($bookingId, $booking, $action);
		} elseif (($action == 'confirm' || $action == 'checkin' || $action == 'checkout' || $action == 'cancel') && $isCustomer) {
			if ($bookingCancelled) {
				$action = 'cancel';
			}
			$this->bookingReplyCustomer($bookingId, $booking, $action);
		} else {
			header('HTTP/1.0 403 Forbidden');
			wp_redirect(get_site_url());
			exit;
		}
	}

	protected function bookingReplyAdmin(int $bookingId, array $booking, string $action)
	{
		$bookingBooked = ($booking['status'] == 'booked');
		$bookingConfirmed = ($booking['status'] == 'confirmed');
		$bookingCancelled = ($booking['status'] == 'cancelled');
		$alreadyDone = false;

		$forceToConfirm = get_post_meta($booking['room'], 'rrze-rsvp-room-force-to-confirm', true);

		if ($bookingBooked && $action == 'confirm') {
			update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'confirmed');
			$bookingConfirmed = true;
			if ($forceToConfirm) {
				$this->email->bookingRequestedCustomer($bookingId);
			} else {
				$this->email->bookingConfirmedCustomer($bookingId);
			}			
		} elseif ($bookingBooked && $action == 'cancel') {
			update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'cancelled');
			$bookingCancelled = true;
			$this->email->bookingCancelledCustomer($bookingId);
		} else {
			$alreadyDone = true;
		}

		if (!$alreadyDone && $bookingConfirmed) {
			$response = 'confirmed';
		} elseif (!$alreadyDone && $bookingCancelled) {
			$response = 'cancelled';
		} elseif ($alreadyDone && $bookingConfirmed) {
			$response = 'already-confirmed';
		} elseif ($alreadyDone && $bookingCancelled) {
			$response = 'already-cancelled';
		} else {
			$response = 'no-action';
		}

		$customerName = sprintf('%s: %s %s', __('Name', 'rrze-rsvp'), $booking['guest_firstname'], $booking['guest_lastname']);
		$customerEmail = sprintf('%s: %s', __('Email', 'rrze-rsvp'), $booking['guest_email']);

		$data = [];
		$data['booking_title'] = __('Booking', 'rrze-rsvp');

		$data['already_done'] = $alreadyDone;
		$responseText = in_array($response, ['confirmed', 'already-confirmed']) ? _x('confirmed', 'Booking', 'rrze-rsvp') : _x('cancelled', 'Booking', 'rrze-rsvp');
		$data['booking_has_already_been'] = sprintf(__('The booking has already been %s', 'rrze-rsvp'), $responseText);
		$data['booking_has_been'] = sprintf(__('The booking has been %s', 'rrze-rsvp'), $responseText);
		$data['customer_has_received_an_email'] = __('Your customer has received an email confirmation.', 'rrze-rsvp');

		$data['class_cancelled'] = in_array($response, ['cancelled', 'already-cancelled']) ? 'cancelled' : '';

		$data['room_name'] = $booking['room_name'];

		$data['date'] = $booking['date'];
		$data['time'] = $booking['time'];

		$data['customer']['name'] = $customerName;
		$data['customer']['email'] = $customerEmail;

		add_filter('the_title', function($title) {
			return __('Booking', 'rrze-rsvp');
		});		
		add_filter('the_content', function($content) use ($data) {
			return $this->template->getContent('reply/booking-admin', $data);
		});
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
			$offset = 15 * MINUTE_IN_SECONDS;
			if (($start - $offset) <= $now && ($end - $offset) >= $now) {
				update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'checked-in');
				$bookingCkeckedIn = true;
				do_action('rrze-rsvp-checked-in', get_current_blog_id(), $bookingId);
			}
		} elseif (!$bookingCancelled && !$bookingCkeckedOut && $bookingCkeckedIn && $action == 'checkout') {
			if ($start <= $now && $end >= $now) {
				update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'checked-out');
				$bookingCkeckedOut = true;
			}
		}

		if (!$bookingCancelled && !$bookingCkeckedIn && $action == 'cancel') {
			$response = 'maybe-cancelled';
		} elseif ($bookingCancelled && $action == 'cancel') {
			$response = 'cancelled';
		} elseif ($userConfirmed && $action == 'confirm') {
			$response = 'confirmed';
		} elseif (!$bookingCkeckedIn && $action == 'checkin') {
			$response = 'cannot-checked-in';
		} elseif ($bookingCkeckedIn && $action == 'checkin') {
			$response = 'already-checked-in';
		} elseif (!$bookingCkeckedOut && $action == 'checkout') {
			$response = 'cannot-checked-out';
		} elseif ($bookingCkeckedOut && $action == 'checkout') {
			$response = 'already-checked-out';
		} else {
			$response = 'no-action';
		}

		$data = [];
        // Is locale not english?
		$data['is_locale_not_english'] = !Functions::isLocaleEnglish() ? true : false;
		
		$data['room_name'] = $booking['room_name'];

		$data['date'] = $booking['date'];
		$data['time'] = $booking['time'];
		$data['date_en'] = $booking['date_en'];
		$data['time_en'] = $booking['time_en'];

		$cancelUrl = Functions::bookingReplyUrl('cancel', sprintf('%s-%s-customer', $bookingId, $booking['start']), $bookingId);
		$data['cancel_btn'] = sprintf(__('<a href="%s" class="button button-cancel">Cancel Your Booking</a>', 'rrze-rsvp'), $cancelUrl);
		$data['cancel_btn_en'] = sprintf('<a href="%s" class="button button-cancel">Cancel Your Booking</a>', $cancelUrl);

		switch ($response) {
			case 'maybe-cancelled':
				$data['booking_cancel'] = __('Cancel Booking', 'rrze-rsvp');
				$data['really_want_to_cancel_the_booking'] = __('Do you really want to cancel your booking?', 'rrze-rsvp');
				$data['booking_cancel_en'] = 'Cancel Booking';
				$data['really_want_to_cancel_the_booking_en'] = 'Do you really want to cancel your booking?';
				$data['class_cancelled'] = ($action == 'cancel') ? 'cancelled' : '';
				break;
			case 'cancelled':
				$data['booking_cancelled'] = __('Booking Cancelled', 'rrze-rsvp');
				$data['booking_has_been_cancelled'] = __('Your booking has been cancelled. Please contact us to find a different arrangement.', 'rrze-rsvp');
				$data['booking_cancelled_en'] = 'Booking Cancelled';
				$data['booking_has_been_cancelled_en'] = 'Your booking has been cancelled. Please contact us to find a different arrangement.';
				$data['class_cancelled'] = ($action == 'cancel') ? 'cancelled' : '';
				break;
			case 'confirmed':
				$data['booking_confirmed'] = __('Booking Confirmed', 'rrze-rsvp');
				$data['thank_for_confirming'] = __('Thank you for confirming your booking.', 'rrze-rsvp');
				$data['booking_confirmed_en'] = 'Booking Confirmed';
				$data['thank_for_confirming_en'] = 'Thank you for confirming your booking.';
				break;
			case 'cannot-checked-in':
				$data['booking_check_in'] = __('Booking Check In', 'rrze-rsvp');
				$data['checkin_is_not_possible'] = __('Check in is not possible at this time.', 'rrze-rsvp');
				$data['booking_check_in_en'] = 'Booking Check In';
				$data['checkin_is_not_possible_en'] = 'Check in is not possible at this time.';
				break;
			case 'already-checked-in':
				$data['booking_checked_in'] = __('Booking Checked In', 'rrze-rsvp');
				$data['checkin_has_been_completed'] = __('Check in has been completed.', 'rrze-rsvp');
				$data['booking_checked_in_en'] = 'Booking Checked In';
				$data['checkin_has_been_completed_en'] = 'Check in has been completed.';
				break;
			case 'cannot-checked-out':
				$data['booking_check_out'] = __('Booking Check Out', 'rrze-rsvp');
				$data['checkout_is_not_possible'] = __('Check-out is not possible at this time.', 'rrze-rsvp');
				$data['booking_check_out_en'] = 'Booking Check Out';
				$data['checkout_is_not_possible_en'] = 'Check-out is not possible at this time.';
				break;
			case 'already-checked-out':
				$data['booking_checked_out'] = __('Booking Checked Out', 'rrze-rsvp');
				$data['checkout_has_been_completed'] = __('Check-out has been completed.', 'rrze-rsvp');
				$data['booking_checked_out_en'] = 'Booking Checked Out';
				$data['checkout_has_been_completed_en'] = 'Check-out has been completed.';
				break;
			default:
				$data['action_not_available'] = __('Action not available', 'rrze-rsvp');
				$data['no_action_was_taken'] = __('No action was taken.', 'rrze-rsvp');
				$data['action_not_available_en'] = 'Action not available';
				$data['no_action_was_taken_en'] = 'No action was taken.';
		}		

		add_filter('the_title', function($title) {
			return __('Booking', 'rrze-rsvp');
		});
		add_filter('the_content', function($content) use ($data) {
			return $this->template->getContent('reply/booking-customer', $data);
		});
	}

	protected function ajaxResult(array $returnAry)
	{
		echo json_encode($returnAry);
		exit;
	}

}
