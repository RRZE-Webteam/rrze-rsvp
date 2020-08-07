<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use RRZE\RSVP\Functions;

class Actions
{
	protected $email;

	public function __construct()
	{
		$this->email = new Email;
	}

	public function onLoaded()
	{
		add_action('admin_init', [$this, 'handleActions']);
		add_action('wp_ajax_booking_action', [$this, 'ajaxBookingAction']);
		add_action('template_include', [$this, 'bookingReplyTemplate']);
	}

	public function ajaxBookingAction()
	{
		$bookingId = absint($_POST['id']);
		$type = sanitize_text_field($_POST['type']);
		$booking = Functions::getBooking($bookingId);
		if (!$booking) {
			echo json_encode([
				'result' => false
			]);
			exit;			
		}
		$forceToConfirm = get_post_meta($booking['room'], 'rrze-rsvp-room-force-to-confirm', true);

		if ($type == 'confirm') {
			update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'confirmed');
			update_post_meta($bookingId, 'rrze-rsvp-customer-status', '');
			if ($forceToConfirm) {
				$this->email->bookingRequestedCustomer($bookingId);
			} else {
				$this->email->bookingConfirmedCustomer($bookingId);
			}
		} else if ($type == 'cancel') {
			update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'cancelled');
			$this->email->bookingCancelledCustomer($bookingId);
		}

		echo json_encode([
			'result' => true
		]);
		exit;
	}

	public function handleActions()
	{
		if (isset($_GET['action']) && isset($_GET['id']) && wp_verify_nonce($_REQUEST['_wpnonce'], 'status')) {
			$bookingId = absint($_GET['id']);
			$action = sanitize_text_field($_GET['action']);
			if ($action == 'confirm') {
				update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'confirmed');
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
			
			if (($action == 'confirm' || $action == 'cancel') && $isAdmin) {
				wp_enqueue_style('rrze-rsvp-booking-reply', plugins_url('assets/css/rrze-rsvp.css', plugin()->getBasename(), [], plugin()->getVersion()));
				$template = $this->loadBookingReplyTemplate('booking-reply-admin', true);
				return $template;
			} elseif (($action == 'confirm' || $action == 'checkin' || $action == 'checkout' || $action == 'cancel' || $action == 'ics') && $isCustomer) {
				if ($action == 'ics') {
					ICS::generate($bookingId);
					exit;
				}
				wp_enqueue_style('rrze-rsvp-booking-reply', plugins_url('assets/css/rrze-rsvp.css', plugin()->getBasename(), [], plugin()->getVersion()));
				$template = $this->loadBookingReplyTemplate('booking-reply-customer', true);
				return $template;
			}

			header('HTTP/1.0 403 Forbidden');
			wp_redirect(get_site_url());
			exit;
		}

		return $template;
	}

	protected function loadBookingReplyTemplate($filename)
	{
		$templatePath = plugin()->getPath('includes/templates') . $filename . '.php';
		if (!file_exists($templatePath)) {
			$templatePath = false;
		}
		require_once($templatePath);
	}
}
