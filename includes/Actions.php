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

		if ($type == 'confirm') {
			update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'confirmed');
			$this->email->bookingConfirmed($bookingId);
		} else if ($type == 'cancel') {
			update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'cancelled');
			$this->email->bookingCancelled($bookingId);
		}

		echo json_encode([
			'email_send' => true
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
				wp_delete_post($bookingId, true);
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

			if ($action == 'confirm' && $booking && password_verify($booking['booking_date'] . '_guest', $hash)) {
				wp_enqueue_style('rrze-rsvp-booking-reply', plugins_url('assets/css/rrze-rsvp.css', plugin()->getBasename(), [], plugin()->getVersion()));
				$template = $this->loadBookingReplyTemplate('booking-reply-user', true);
				return $template;
			} elseif (($action == 'confirm' || $action == 'cancel') && $booking && password_verify($booking['booking_date'], $hash)) {
				if (isset($_GET['ics'])) {
					$filename = 'booking_' . date('Y-m-d-H-i', strtotime($booking['start'])) . '.ics';
					header('Content-type: text/calendar; charset=utf-8');
					header('Content-Disposition: attachment; filename=' . $filename);
					echo "BEGIN:VCALENDAR\r\n";
					echo "VERSION:2.0\r\n";
					echo "PRODID:-//rrze//rsvp//EN\r\n";
					$this->generateBookingIcs($bookingId);
					echo "END:VCALENDAR\r\n";
					die();
				}

				wp_enqueue_style('rrze-rsvp-booking-reply', plugins_url('assets/css/rrze-rsvp.css', plugin()->getBasename(), [], plugin()->getVersion()));
				$template = $this->loadBookingReplyTemplate('booking-reply-admin', true);
				return $template;
			}

			header('HTTP/1.0 403 Forbidden');
			wp_redirect(get_site_url());
			exit();
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

	protected function generateBookingIcs(int $bookingId)
	{
		$booking = Functions::getBooking($bookingId);
		if (empty($booking)) {
			return;
		}

		$timezoneString = get_option('timezone_string');
		$dtstamp = date("Ymd\THis");
		$dtstampReadable = Functions::dateFormat('now') . ' ' . Functions::timeFormat('now');

		$timestamp = date('ymdHi', strtotime($booking['start']));
		$uid = md5($timestamp . date('ymdHi')) . "@rrze-rsvp";
		$dtstamp = date("Ymd\THis");
		$dtstart = date("Ymd\THis", strtotime($booking['start']));
		$dtend = date("Ymd\THis", strtotime($booking['end']));

		$summary = $booking['service_name'];
		if ($booking['confirmed'] == 'confirmed') $summary .= ' [' . __('Confirmed', 'rrze-rsvp') . ']';

		$confirmUrl = Functions::bookingReplyUrl('confirm', $booking['booking_date'], $booking['id']);
		$cancelUrl = Functions::bookingReplyUrl('cancel', $booking['booking_date'], $booking['id']);

		$description = Functions::dataToStr($booking['fields'], '\\n');
		if ($booking['status'] != 'confirmed') $description .= "\\n\\n" . __('Confirm Booking', 'rrze-rsvp') . ':\\n' . $confirmUrl;
		$description .= "\\n\\n" . __('Cancel Booking', 'rrze-rsvp') . ':\\n' . $cancelUrl;
		$description .= "\\n\\n" . __('Generated', 'rrze-rsvp') . ': ' . $dtstampReadable;


		echo "BEGIN:VEVENT\r\n";
		echo "UID:" . $uid . "\r\n";
		echo "DTSTAMP:" . $dtstamp . "\r\n";
		echo "DTSTART;TZID=" . $timezoneString . ":" . $dtstart . "\r\n";
		echo "DTEND;TZID=" . $timezoneString . ":" . $dtend . "\r\n";
		echo "SUMMARY:" . $summary . "\r\n";
		echo "DESCRIPTION:" . $description . "\r\n";
		echo "END:VEVENT\r\n";
	}
}
