<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

class Actions
{
	protected $email;

	protected $template;

	public function __construct()
	{
		$this->email = new Email;
		$this->template = new Template;
        $this->settings = new Settings(plugin()->getFile());
        $this->options = (object) $this->settings->getOptions();
	}

	public function onLoaded()
	{
		add_action('admin_init', [$this, 'handleActions']);
		add_action('wp_ajax_booking_action', [$this, 'ajaxBookingAction']);
		add_filter('post_row_actions', [$this, 'bookingRowActions'], 10, 2);
		add_filter('post_row_actions', [$this, 'rowActions'], 10, 2);
		add_filter('bulk_actions-edit-booking', [$this, 'bookingBulkActions']);
		add_filter('bulk_actions-edit-room', [$this, 'bulkActions']);
		add_filter('bulk_actions-edit-seat', [$this, 'bulkActions']);
		add_filter('handle_bulk_actions-edit-booking', [$this, 'bookingBulkActionsHandler'], 10, 3);
		add_filter('handle_bulk_actions-edit-room', [$this, 'bulkActionsHandler'], 10, 3);
		add_filter('handle_bulk_actions-edit-seat', [$this, 'bulkActionsHandler'], 10, 3);
		add_action('admin_init', [$this, 'bookingBulkActionsHandlerSubmitted']);
		add_action('admin_init', [$this, 'bulkActionsHandlerSubmitted']);
		add_action('admin_notices', [$this, 'bulkActionsHandlerAdminNotice']);
		add_action('pre_post_update', [$this, 'preBookingUpdate']);
		// add_action('pre_post_update', [$this, 'preBookingUpdate'], 10, 2);
		add_action('pre_post_update', [$this, 'prePostUpdate'], 10, 2);
		add_action('transition_post_status', [$this, 'transitionBookingStatus'], 10, 3);
		add_action('transition_post_status', [$this, 'transitionPostStatus'], 10, 3);
		add_action('wp', [$this, 'bookingReply']);
	}

	public function ajaxBookingAction()
	{
		$bookingId = absint($_POST['id']);
		$action = sanitize_text_field($_POST['type']);

		$post = get_post($bookingId);
		if ($post->post_status != 'publish') {
			$this->ajaxResult(['result' => false]);
		}

		$booking = Functions::getBooking($bookingId);
		if (!$booking) {
			$this->ajaxResult(['result' => false]);
		}

		$autoConfirmation = Functions::getBoolValueFromAtt(get_post_meta($booking['room'], 'rrze-rsvp-room-auto-confirmation', true));
        $adminConfirmationRequired = $autoConfirmation ? false : true; // Verwirrende Post-Meta-Bezeichnung vereinfacht
		$status = get_post_meta($bookingId, 'rrze-rsvp-booking-status', true);

        if (in_array($status, ['booked', 'customer-confirmed']) && $action == 'confirm') {
            update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'confirmed');
            $bookingConfirmed = true;
            $this->email->doEmail('adminConfirmed', 'customer', $bookingId);
        } elseif ($status == 'booked' && $action == 'custom-confirm') {
            update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'customer-confirmed');
            $bookingConfirmed = true;
            $this->email->doEmail('customerConfirmed', 'customer', $bookingId,'customer-confirmed');
            if ($adminConfirmationRequired) {
                $this->email->doEmail('customerConfirmed', 'admin', $bookingId, 'customer-confirmed');
            }
        } elseif (in_array($status, ['booked', 'confirmed']) && $action == 'cancel') {
			update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'cancelled');
            $this->email->doEmail('bookingCancelled', 'customer', $bookingId, 'cancelled');
		} else {
			$this->ajaxResult(['result' => false]);
		}

		do_action('rrze-rsvp-tracking', get_current_blog_id(), $bookingId);

		$this->ajaxResult(['result' => true]);
	}

	public function handleActions()
	{
		if (isset($_GET['action']) && isset($_GET['id']) && isset($_REQUEST['_wpnonce']) && wp_verify_nonce($_REQUEST['_wpnonce'], 'status')) {
			$bookingId = absint($_GET['id']);
			$action = sanitize_text_field($_GET['action']);

			$post = get_post($bookingId);
			if ($post->post_status != 'publish') {
				return;
			}

			$status = get_post_meta($bookingId, 'rrze-rsvp-booking-status', true);

			$booking = Functions::getBooking($bookingId);
			if (!$booking) {
				return;
			}

            $forceToConfirm = Functions::getBoolValueFromAtt(get_post_meta($booking['room'], 'rrze-rsvp-room-force-to-confirm', true));
            $autoConfirmation = Functions::getBoolValueFromAtt(get_post_meta($booking['room'], 'rrze-rsvp-room-auto-confirmation', true));
            $adminConfirmationRequired = $autoConfirmation ? false : true; // Verwirrende Post-Meta-Bezeichnung vereinfacht

            if (in_array($status, ['booked', 'customer-confirmed']) && $action == 'confirm') {
                update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'confirmed');
                $bookingConfirmed = true;
                $this->email->doEmail('adminConfirmed', 'customer', $bookingId);
            } elseif ($status == 'booked' && $action == 'custom-confirm') {
                update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'customer-confirmed');
                $bookingConfirmed = true;
                $this->email->doEmail('customerConfirmed', 'customer', $bookingId, 'customer-confirmed');
                if ($adminConfirmationRequired) {
                    $this->email->doEmail('customerConfirmed', 'admin', $bookingId, 'customer-confirmed');
                }
            } elseif (in_array($status, ['booked', 'confirmed']) && $action == 'cancel') {
				update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'cancelled');
				$this->email->doEmail('bookingCancelled', 'customer', $bookingId, 'cancelled');
			} elseif ($status == 'cancelled' && $action == 'restore') {
                if ($forceToConfirm) {
                    update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'customer-confirmed');
                } else {
                    update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'booked');
                }
			} elseif (in_array($status, ['checked-out', 'confirmed']) && $action == 'checkin') {
			    $now = current_time('timestamp');
                $offset = 15 * MINUTE_IN_SECONDS;
                if ($now < ($booking['start'] - $offset) || $now > $booking['end']) {
                    wp_die(
                        __('Booking can only be checked in between 15 minutes before the start of the timeslot and the end of the timeslot.', 'rrze-rsvp'),
                        __('Update Error', 'rrze-rsvp'),
                        ['back_link' => true]
                    );
                } else {
                    update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'checked-in');
                }
			} elseif ($status == 'checked-in' && $action == 'checkout') {
			    update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'checked-out');
			}

			do_action('rrze-rsvp-tracking', get_current_blog_id(), $bookingId);

			wp_redirect(get_admin_url() . 'edit.php?post_type=booking');
			exit;
		}
	}

	/**
	 * Filters the array of row action links on the booking list table.
	 * The filter is evaluated only for non-hierarchical post types.
	 * @param array $actions An array of row action links.
	 * @param object $post The post object (WP_Post).
	 * @return array $actions
	 */
	public function bookingRowActions($actions, $post)
	{
		if ($post->post_type != 'booking' || $post->post_status != 'publish') {
			return $actions;
		}

		$actions = [];
		$title = _draft_or_post_title();		
		$canEdit = current_user_can('edit_post', $post->ID);
		$isArchive = Functions::isBookingArchived($post->ID);
		$canDelete = Functions::canDeleteBooking($post->ID);

		if (!$isArchive && $canEdit) {
			$actions['edit'] = sprintf(
				'<a href="%s" aria-label="%s">%s</a>',
				get_edit_post_link($post->ID),
				/* translators: %s: Post title. */
				esc_attr(sprintf(__('Edit &#8220;%s&#8221;'), $title)),
				__('Edit')
			);

			if ('wp_block' !== $post->post_type) {
				unset($actions['inline hide-if-no-js']);
			}
		}

		if ($canDelete) {
			if (current_user_can('delete_post', $post->ID)) {
				if (EMPTY_TRASH_DAYS) {
					$actions['trash'] = sprintf(
						'<a href="%s" class="submitdelete" aria-label="%s">%s</a>',
						get_delete_post_link($post->ID),
						/* translators: %s: Post title. */
						esc_attr(sprintf(__('Move &#8220;%s&#8221; to the Trash'), $title)),
						_x('Delete', 'Booking', 'rrze-rsvp')
					);
				} else {
					$actions['delete'] = sprintf(
						'<a href="%s" class="submitdelete" aria-label="%s">%s</a>',
						get_delete_post_link($post->ID, '', true),
						/* translators: %s: Post title. */
						esc_attr(sprintf(__('Delete &#8220;%s&#8221; permanently'), $title)),
						__('Delete Permanently')
					);
				}
			}
		}

		return $actions;
	}

	/**
	 * Filters the array of row action links on the room|seat list table.
	 * The filter is evaluated only for non-hierarchical post types.
	 * @param array $actions An array of row action links.
	 * @param object $post The post object (WP_Post).
	 * @return array $actions
	 */
	public function rowActions($actions, $post)
	{
		if (!in_array($post->post_type, ['room', 'seat']) || $post->post_status != 'publish') {
			return $actions;
		}

		$generatePdfLink = !empty($actions['generate-pdf']) ? $actions['generate-pdf'] : '';
        $view = $actions['view'];
		$actions = [];
		$title = _draft_or_post_title();		
		$canEdit = current_user_can('edit_post', $post->ID);
		$canDelete = $post->post_type == 'room' ? Functions::canDeleteRoom($post->ID) : Functions::canDeleteSeat($post->ID);

		if ($canEdit) {
			$actions['edit'] = sprintf(
				'<a href="%s" aria-label="%s">%s</a>',
				get_edit_post_link($post->ID),
				/* translators: %s: Post title. */
				esc_attr(sprintf(__('Edit &#8220;%s&#8221;'), $title)),
				__('Edit')
			);
		}

		if ($canDelete) {
			if (current_user_can('delete_post', $post->ID)) {
				if (EMPTY_TRASH_DAYS) {
					$actions['trash'] = sprintf(
						'<a href="%s" class="submitdelete" aria-label="%s">%s</a>',
						get_delete_post_link($post->ID),
						/* translators: %s: Post title. */
						esc_attr(sprintf(__('Move &#8220;%s&#8221; to the Trash'), $title)),
						_x('Delete', 'Room|Seat', 'rrze-rsvp')
					);
				} else {
					$actions['delete'] = sprintf(
						'<a href="%s" class="submitdelete" aria-label="%s">%s</a>',
						get_delete_post_link($post->ID, '', true),
						/* translators: %s: Post title. */
						esc_attr(sprintf(__('Delete &#8220;%s&#8221; permanently'), $title)),
						__('Delete Permanently')
					);
				}
			}
        }

        $actions['view'] = $view;
        
		if ($generatePdfLink) {
			$actions['generate-pdf'] = $generatePdfLink;
		}
		return $actions;
	}

	public function bookingBulkActions($actions)
	{
		$actions = [];
		$actions['cancel_booking'] = _x('Cancel', 'Booking', 'rrze-rsvp');
		if (EMPTY_TRASH_DAYS) {
			$actions['trash_booking'] = _x('Delete', 'Booking', 'rrze-rsvp');
		} else {
			$actions['delete_booking'] = __('Delete Permanently');
		}
		return $actions;
	}

	public function bulkActions($actions)
	{
		global $post_type;
		if (!in_array($post_type, ['room', 'seat'])) {
			return;
		}		
		$generatePdfAction= !empty($actions['generate-pdf']) ? $actions['generate-pdf'] : '';
		$actions = [];
		if (EMPTY_TRASH_DAYS) {
			$actions["trash_{$post_type}"] = _x('Delete', 'Booking', 'rrze-rsvp');
		} else {
			$actions["delete_{$post_type}"] = __('Delete Permanently');
		}
		if ($generatePdfAction) {
			$actions['generate-pdf'] = $generatePdfAction;
		}
		return $actions;
	}

	public function bookingBulkActionsHandler($redirectTo, $doaction, $postIds)
	{
		switch ($doaction) {
			case 'cancel_booking':
				$cancelled = 0;
				$locked  = 0;
				foreach ((array) $postIds as $key => $postId) {
					$post = get_post($postId);
					if ($post->post_status != 'publish') {
						unset($postIds[$key]);
						continue;
					}
					$status = get_post_meta($postId, 'rrze-rsvp-booking-status', true);
					if (wp_check_post_lock($postId)) {
						$locked++;
						unset($postIds[$key]);
						continue;
					}
					if (!Functions::isBookingArchived($postId) && in_array($status, ['booked', 'confirmed'])) {
						update_post_meta($postId, 'rrze-rsvp-booking-status', 'cancelled');
						$this->email->doEmail('bookingCancelled', 'customer', $postId);
						do_action('rrze-rsvp-tracking', get_current_blog_id(), $postId);
						$cancelled++;
					} else {
						unset($postIds[$key]);
					}
				}
				$redirectTo = add_query_arg(
					[
						'booking_cancelled' => $cancelled,
						'booking_ids' => join(',', $postIds),
						'booking_locked'  => $locked,
					],
					$redirectTo
				);
				break;
			case 'trash_booking':
				$trashed = 0;
				$locked  = 0;
				foreach ((array) $postIds as $key => $postId) {
					$post = get_post($postId);
					if ($post->post_status != 'publish') {
						unset($postIds[$key]);
						continue;
					}
					if (Functions::canDeleteBooking($postId)) {
						if (!current_user_can('delete_post', $postId)) {
							wp_die(__('Sorry, you are not allowed to move this item to the Trash.'));
						}
						if (wp_check_post_lock($postId)) {
							$locked++;
							unset($postIds[$key]);
							continue;
						}
						if (!wp_trash_post($postId)) {
							wp_die(__('Error in moving the item to Trash.'));
						}
						$trashed++;
					} else {
						unset($postIds[$key]);
					}				
				}
				$redirectTo = add_query_arg(
					[
						'booking_trashed' => $trashed,
						'booking_ids' => join(',', $postIds),
						'booking_locked'  => $locked,
					],
					$redirectTo
				);
				break;
			case 'delete_booking':
				$deleted = 0;
				foreach ((array) $postIds as $postId) {
					if (Functions::canDeleteBooking($postId)) {
						if (!current_user_can('delete_post', $postId)) {
							wp_die(__('Sorry, you are not allowed to delete this item.'));
						}
						if (!wp_delete_post($postId)) {
							wp_die(__('Error in deleting the item.'));
						}
						$deleted++;						
					}
				}
				$redirectTo = add_query_arg(
					[
						'booking_deleted' => $deleted
					],
					$redirectTo
				);
				break;
			default:
				//
		}
		return $redirectTo;
	}

	public function bulkActionsHandler($redirectTo, $doaction, $postIds)
	{
		global $post_type;
		if (!in_array($post_type, ['room', 'seat'])) {
			return;
		}
		switch ($doaction) {
			case "trash_{$post_type}":
				$trashed = 0;
				$locked  = 0;
				foreach ((array) $postIds as $key => $postId) {
					$post = get_post($postId);
					if ($post->post_status != 'publish') {
						continue;
					}
					$canDelete = $post_type == 'room' ? Functions::canDeleteRoom($postId) : Functions::canDeleteSeat($postId);
					if ($canDelete) {
						if (!current_user_can('delete_post', $postId)) {
							wp_die(__('Sorry, you are not allowed to move this item to the Trash.'));
						}
						if (wp_check_post_lock($postId)) {
							$locked++;
							continue;
						}
						if (!wp_trash_post($postId)) {
							wp_die(__('Error in moving the item to Trash.'));
						}
						$trashed++;
					} else {
						unset($postIds[$key]);
					}				
				}
				$redirectTo = add_query_arg(
					[
						"{$post_type}_trashed" => $trashed,
						"{$post_type}_ids" => join(',', $postIds),
						"{$post_type}_locked"  => $locked,
					],
					$redirectTo
				);
				break;
			case "delete_{$post_type}":
				$deleted = 0;
				foreach ((array) $postIds as $key => $postId) {
					$canDelete = $post_type == 'room' ? Functions::canDeleteRoom($postId) : Functions::canDeleteSeat($postId);
					if ($canDelete) {
						if (!current_user_can('delete_post', $postId)) {
							wp_die(__('Sorry, you are not allowed to delete this item.'));
						}
						if (!wp_delete_post($postId)) {
							wp_die(__('Error in deleting the item.'));
						}
						$deleted++;					
					} else {
						unset($postIds[$key]);
					}
				}
				$redirectTo = add_query_arg(
					[
						"{$post_type}_deleted" => $deleted
					],
					$redirectTo
				);
				break;
			default:
				//
		}
		return $redirectTo;
	}

	public function bookingBulkActionsHandlerSubmitted()
	{
		if (!isset($_REQUEST['booking_cancelled']) && !isset($_REQUEST['booking_trashed']) && !isset($_REQUEST['booking_deleted'])) {
			return;
		}
		$bulkCounts = [
			'cancelled' => isset($_REQUEST['booking_cancelled']) ? absint($_REQUEST['booking_cancelled']) : 0,
			'trashed' => isset($_REQUEST['booking_trashed']) ? absint($_REQUEST['booking_trashed']) : 0,
			'deleted' => isset($_REQUEST['booking_deleted']) ? absint($_REQUEST['booking_deleted']) : 0,
			'locked' => isset($_REQUEST['booking_locked']) ? absint($_REQUEST['booking_locked']) : 0
		];
		$bulkMessages = [
			'cancelled' => _n('%s post cancelled.', '%s post cancelled.', $bulkCounts['cancelled']),
			'trashed' => _n('%s post moved to the Trash.', '%s posts moved to the Trash.', $bulkCounts['trashed']),
			'deleted' => _n('%s post permanently deleted.', '%s posts permanently deleted.', $bulkCounts['deleted']),
			'locked' => ($bulkCounts['locked'] === 1) ? __('1 post not updated, somebody is editing it.') :
				_n('%s post not updated, somebody is editing it.', '%s posts not updated, somebody is editing them.', $bulkCounts['locked'])
		];
		$messages = [];
		foreach ($bulkCounts as $message => $count) {
			if (isset($bulkMessages[$message]) && $count) {
				$messages[] = sprintf($bulkMessages[$message], number_format_i18n($count));
			}
			if ($message == 'trashed' && $count && isset($_REQUEST['booking_ids'])) {
				$ids = preg_replace('/[^0-9,]/', '', $_REQUEST['booking_ids']);
				$messages[] = '<a href="' . esc_url(wp_nonce_url("edit.php?post_type=booking&doaction=undo&action=untrash&ids={$ids}", 'bulk-posts')) . '">' . __('Undo') . '</a>';
			}
		}
		if ($messages) {
			$transientData = new TransientData(bin2hex(random_bytes(8)));
			$transientData->addData('messages', $messages);
			$redirectUrl = add_query_arg(
				[
					'transient-data-nonce' => wp_create_nonce('transient-data'),
					'transient-data' => $transientData->getTransient()
				],
				remove_query_arg(['booking_cancelled', 'booking_locked', 'booking_trashed', 'booking_deleted', 'booking_ids'], wp_get_referer())
			);
			wp_redirect($redirectUrl);
			exit;
		}
	}

	public function bulkActionsHandlerSubmitted()
	{
		if (!isset($_GET['post_type']) || !in_array($_GET['post_type'], ['room', 'seat'])) {
			return;
		}
		$postType = $_GET['post_type'];
		if (!isset($_REQUEST["{$postType}_trashed"]) && !isset($_REQUEST["{$postType}_deleted"])) {
			return;
		}
		$bulkCounts = [
			'trashed' => isset($_REQUEST["{$postType}_trashed"]) ? absint($_REQUEST["{$postType}_trashed"]) : 0,
			'deleted' => isset($_REQUEST["{$postType}_deleted"]) ? absint($_REQUEST["{$postType}_deleted"]) : 0,
			'locked' => isset($_REQUEST["{$postType}_locked"]) ? absint($_REQUEST["{$postType}_locked"]) : 0
		];
		$bulkMessages = [
			'trashed' => _n('%s post moved to the Trash.', '%s posts moved to the Trash.', $bulkCounts['trashed']),
			'deleted' => _n('%s post permanently deleted.', '%s posts permanently deleted.', $bulkCounts['deleted']),
			'locked' => ($bulkCounts['locked'] === 1) ? __('1 post not updated, somebody is editing it.') :
				_n('%s post not updated, somebody is editing it.', '%s posts not updated, somebody is editing them.', $bulkCounts['locked'])
		];
		$messages = [];
		foreach ($bulkCounts as $message => $count) {
			if (isset($bulkMessages[$message]) && $count) {
				$messages[] = sprintf($bulkMessages[$message], number_format_i18n($count));
			}
			if ($message == 'trashed' && $count && isset($_REQUEST["{$postType}_ids"])) {
				$ids = preg_replace('/[^0-9,]/', '', $_REQUEST["{$postType}_ids"]);
				$messages[] = '<a href="' . esc_url(wp_nonce_url("edit.php?post_type={$postType}&doaction=undo&action=untrash&ids={$ids}", 'bulk-posts')) . '">' . __('Undo') . '</a>';
			}
		}
		if ($messages) {
			$transientData = new TransientData(bin2hex(random_bytes(8)));
			$transientData->addData('messages', $messages);
			$redirectUrl = add_query_arg(
				[
					'transient-data-nonce' => wp_create_nonce('transient-data'),
					'transient-data' => $transientData->getTransient()
				],
				remove_query_arg(["{$postType}_locked", "{$postType}_trashed", "{$postType}_deleted", "{$postType}_ids"], wp_get_referer())
			);
			wp_redirect($redirectUrl);
			exit;
		}
	}

	public function bulkActionsHandlerAdminNotice()
	{
		if (!isset($_GET['transient-data']) || !isset($_GET['transient-data-nonce']) || !wp_verify_nonce($_GET['transient-data-nonce'], 'transient-data')) {
			return;
		}
		$transient = $_GET['transient-data'];
		$transientData = new TransientData($transient);
		$data = $transientData->getData();
		if (!empty($data['messages'])) {
			echo '<div id="message" class="updated notice is-dismissible"><p>' . implode(' ', $data['messages']) . '</p></div>';
		}
	}

	public function preBookingUpdate($postId)
	{
		$post = get_post($postId);
		if ($post->post_type != 'booking') {
			return;
		}
		
		$errorMessage = '';

		if ($post->post_status != 'publish') {
			$errorMessage = $this->isSeatAvailable();
		} else {
			$trash = isset($_REQUEST['trash']) ? $_REQUEST['trash'] : '';
			$delete = isset($_REQUEST['delete']) ? $_REQUEST['delete'] : '';
	
			//$requestStatus = isset($_REQUEST['rrze-rsvp-booking-status']) ? $_REQUEST['rrze-rsvp-booking-status'] : '';
			$requestSeat = isset($_REQUEST['rrze-rsvp-booking-seat']) ? $_REQUEST['rrze-rsvp-booking-seat'] : '';
	
			//$status = get_post_meta($postId, 'rrze-rsvp-booking-status', true);
			$seat = get_post_meta($postId, 'rrze-rsvp-booking-seat', true);
	
			$isArchive = Functions::isBookingArchived($postId);
			$canDelete = Functions::canDeleteBooking($postId);
	
			if ($trash || $delete) {
				if (!$canDelete) {
					$errorMessage = __('This item cannot be deleted.', 'rrze-rsvp');
				}
			} elseif (
				$isArchive 
				|| ($requestSeat != $seat)
			) {
				$errorMessage = __('This item cannot be updated.', 'rrze-rsvp');
            }
		}

		if ($errorMessage) {
			wp_die(
				$errorMessage,
				__('Update Error', 'rrze-rsvp'),
				['back_link' => true]
			);
		}
	}

	// public function preBookingUpdate($post_id, $post_data) {
	// 	if ($post_data['post_type'] != 'booking') {
	// 		return;
	// 	}
		
	// 	$errorMessage = $this->isSeatAvailable();

	// 	if (!$errorMessage) {
	// 		$trash = isset($_REQUEST['trash']) ? $_REQUEST['trash'] : '';
	// 		$delete = isset($_REQUEST['delete']) ? $_REQUEST['delete'] : '';
	
	// 		//$requestStatus = isset($_REQUEST['rrze-rsvp-booking-status']) ? $_REQUEST['rrze-rsvp-booking-status'] : '';
	// 		$requestSeat = isset($_REQUEST['rrze-rsvp-booking-seat']) ? $_REQUEST['rrze-rsvp-booking-seat'] : '';
	
	// 		//$status = get_post_meta($postId, 'rrze-rsvp-booking-status', true);
	// 		$seat = get_post_meta($post_id, 'rrze-rsvp-booking-seat', true);
	
	// 		$isArchive = Functions::isBookingArchived($post_id);
	// 		$canDelete = Functions::canDeleteBooking($post_id);
	
	// 		if ($trash || $delete) {
	// 			if (!$canDelete) {
	// 				$errorMessage = __('This item cannot be deleted.', 'rrze-rsvp');
	// 			}
	// 		} elseif ( $isArchive  || ($requestSeat != $seat) ) {
	// 			$errorMessage = __('This item cannot be updated.', 'rrze-rsvp');
	// 		}			
	// 	}

	// 	if ($errorMessage) {
	// 		wp_die(
	// 			$errorMessage,
	// 			__('Update Error', 'rrze-rsvp'),
	// 			['back_link' => true]
	// 		);
	// 	}
	// }

	protected function isSeatAvailable()
	{
		$errorMessage = '';
		$seatId = isset($_REQUEST['rrze-rsvp-booking-seat']) ? $_REQUEST['rrze-rsvp-booking-seat'] : '';
		$bookingStart = isset($_REQUEST['rrze-rsvp-booking-start']) ? $_REQUEST['rrze-rsvp-booking-start'] : '';
		$bookingStart = is_array($bookingStart) ? $bookingStart['date'] . ' ' . $bookingStart['time'] : '';
		$bookingEnd = isset($_REQUEST['rrze-rsvp-booking-end']) ? $_REQUEST['rrze-rsvp-booking-end'] : '';
		$bookingEnd = is_array($bookingEnd) ? $bookingEnd['date'] . ' ' . $bookingEnd['time'] : '';

        $args = [
			'fields' => 'ids',
            'post_type' => 'booking',
            'post_status' => 'publish',
            'nopaging' => true,
            'meta_query' => [
                [
                    'key' => 'rrze-rsvp-booking-seat',
                    'value' => $seatId,
                ],
                [
                    'key' => 'rrze-rsvp-booking-status',
                    'value' => ['booked', 'confirmed', 'checked-in'],
                    'compare' => 'IN'
                ],
                [
                    'key' => 'rrze-rsvp-booking-start',
                    'value' => [strtotime($bookingStart), strtotime($bookingEnd)],
                    'compare' => 'BETWEEN',
                    'type' => 'numeric'
                ],
            ],
        ];		
        $query = new \WP_Query($args);

        if ($query->have_posts()) {
            $errorMessage = __('Seat unavailable.', 'rrze-rsvp'); 
            wp_reset_postdata();
		}
						
		return $errorMessage;		
	}


    /*
	*  prePostUpdate() prevents a seat or a room if it is used in a booking
	* 		a) to be deleted 
	* 		b) to be set to draft, private or future (= scheduled to be published in a future date)
	* 		c) to be password protected 
    */ 
	public function prePostUpdate($post_id, $post_data) {
        global $wpdb;
		$errorMessage = '';
		
		if (!in_array($post_data['post_type'], ['room', 'seat'])) {
			return;
		}

        $canDelete = ( $post_data['post_type'] == 'room' ? Functions::canDeleteRoom($post_id) : Functions::canDeleteSeat($post_id) ); // false if there is a booking with this room or seat

        if ( !$canDelete ){
			// prevent delete
            if ( isset($_REQUEST['trash']) || isset($_REQUEST['delete']) || ( isset($_REQUEST['action']) && $_REQUEST['action'] == 'trash' ) ){
				$errorMessage = __('This item is used in a booking and cannot be deleted.', 'rrze-rsvp');
			}

			// prevent status change
            if (in_array($post_data['post_status'], ['private', 'draft', 'future'])){
				$errorMessage = __('This item is used in a booking and cannot be set to draft, to private or scheduled to be published in a future date.', 'rrze-rsvp');
			}

			// prevent password protection
            if ($post_data['post_password']){
				$errorMessage = __('This item is used in a booking and cannot be password protected.', 'rrze-rsvp');
			}
        }

        if ($post_data['post_type'] == 'room') {
            $oldTimeslots = get_post_meta($post_id, 'rrze-rsvp-room-timeslots', true);
            $newTimeslots = isset($_POST['rrze-rsvp-room-timeslots']) ? $_POST['rrze-rsvp-room-timeslots'] : [];

            if (!empty($newTimeslots)) {
                foreach ($newTimeslots as $k => $newTimeslot) {
                    if ($newTimeslot['rrze-rsvp-room-starttime'] > $newTimeslot['rrze-rsvp-room-endtime']) {
                        $errorTimeslots['invalid'][] = $k + 1;
                    }
                }
                if (isset($errorTimeslots['invalid'])) {
                    $_POST['rrze-rsvp-room-timeslots'] = $oldTimeslots;
                    $sTimeslots = implode(' and ', $errorTimeslots['invalid']);
                    $errorMessage = sprintf(_n('Unable to save post: End time must be greater than start time in timeslot no. %s.', 'Unable to save post: End time must be greater than start time in timeslots no. %s.', count($errorTimeslots['invalid']), 'rrze-rsvp'), $sTimeslots);
                }
            }
        }


		if ($errorMessage) {
            $wpdb->update( $wpdb->posts, array( 'post_name' => $post_data['post_title'] ), array( 'ID' => $post_id ) );

			wp_die(
				$errorMessage,
				__('Update Error', 'rrze-rsvp'),
				['back_link' => true]
            );
        }
	}	

	public function transitionBookingStatus($newStatus, $oldStatus, $post)
	{
		if ($post->post_type != 'booking') {
			return;
		}

		if ('publish' != $newStatus || 'publish' != $oldStatus) {
			return;
		}

		if (!isset($_POST['rrze-rsvp-booking-status'])) {
			return;
		}

		$bookingId = $post->ID;
		$booking = Functions::getBooking($bookingId);

		$bookingStatus = sanitize_text_field($_POST['rrze-rsvp-booking-status']);

		$bookingBooked = ($bookingStatus == 'booked');
		$bookingConfirmed = ($bookingStatus == 'confirmed');
        $bookingCancelled = ($bookingStatus == 'cancelled');
		$bookingCheckedIn = ($bookingStatus == 'checked-in');
		$bookingCheckedOut = ($bookingStatus == 'checked-out');

		$bookingMode = get_post_meta($booking['room'], 'rrze-rsvp-room-bookingmode', true);
		$forceToConfirm = get_post_meta($booking['room'], 'rrze-rsvp-room-force-to-confirm', true);

		if (($bookingBooked || $bookingCancelled) && $bookingStatus == 'confirmed') {
			update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'confirmed');
			if ($forceToConfirm) {
				update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'booked');
				$this->email->doEmail('adminConfirmed', 'customer', $bookingId);
			} else {
				$this->email->doEmail('adminConfirmed', 'customer', $bookingId);
			}
		} elseif (($bookingBooked || $bookingConfirmed) && $bookingStatus == 'cancelled') {
			$this->email->doEmail('bookingCancelled', 'customer', $bookingId);
		} elseif ($bookingCheckedIn) {
			//
		} elseif ($bookingCheckedOut) {
			//
		} else {
			return;
		}

		do_action('rrze-rsvp-tracking', get_current_blog_id(), $bookingId);
	}


    /*
    * transitionPostStatus() prevents that a booked seat is assigned to a different room
    */
	public function transitionPostStatus($newStatus, $oldStatus, $post) {
		$errorMessage = '';

		if ($post->post_type == 'seat') {

		    $canDelete = Functions::canDeleteSeat($post->ID);
            if ( !$canDelete ){
                $roomId = get_post_meta($post->ID, 'rrze-rsvp-seat-room', true);
                if (isset($_POST['rrze-rsvp-seat-room'])  && $_POST['rrze-rsvp-seat-room'] != $roomId){
                    // roomId is about to be changed -> set old roomId
                    $_POST['rrze-rsvp-seat-room'] = $roomId;
                    $errorMessage = __('This seat is used in a booking and cannot be assigned to a different room.', 'rrze-rsvp');
                }
            }
        }

        if ($errorMessage) {
            wp_die(
                $errorMessage,
                __('Update Error', 'rrze-rsvp'),
                ['back_link' => true]
            );
        }
	}


	public function bookingReply(){
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
		} elseif (($action == 'confirm' || $action == 'checkin' || $action == 'checkout' || $action == 'cancel' || $action == 'maybe-cancel') && $isCustomer) {
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
		$bookingCustomerConfirmed = ($booking['status'] == 'customer-confirmed');
		$bookingConfirmed = ($booking['status'] == 'confirmed');
		$bookingCancelled = ($booking['status'] == 'cancelled');
		$alreadyDone = false;

		$autoConfirmation = Functions::getBoolValueFromAtt(get_post_meta($booking['room'], 'rrze-rsvp-room-auto-confirmation', true));
        $adminConfirmationRequired = $autoConfirmation ? false : true; // Verwirrende Post-Meta-Bezeichnung vereinfacht

        if (($bookingBooked || $bookingCustomerConfirmed) && $action == 'confirm') {
			update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'confirmed');
			$bookingConfirmed = true;
			$this->email->doEmail('adminConfirmed', 'customer', $bookingId);
        } elseif ($bookingBooked && $action == 'custom-confirm') {
            update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'customer-confirmed');
            $bookingConfirmed = true;
            $this->email->doEmail('customerConfirmed', 'customer', $bookingId,'customer-confirmed');
            if ($adminConfirmationRequired) {
                $this->email->doEmail('customerConfirmed', 'admin', $bookingId, 'customer-confirmed');
            }
		} elseif (($bookingBooked || $bookingCustomerConfirmed) && $action == 'cancel') {
			update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'cancelled');
			$bookingCancelled = true;
			$this->email->doEmail('bookingCancelled', 'customer', $bookingId, 'cancelled');
		} else {
			$alreadyDone = true;
		}

		$data = [];

		if (!$alreadyDone && $bookingConfirmed) {
			$data['title'] = __('Booking Confirmed', 'rrze-rsvp');
			$data['text'] = sprintf(__('The booking has been %s', 'rrze-rsvp'), _x('confirmed', 'Booking', 'rrze-rsvp'));
			$data['customer_has_received_an_email'] = __('Your customer has received an email confirmation.', 'rrze-rsvp');
			$response = 'confirmed';
		} elseif (!$alreadyDone && $bookingCancelled) {
			$data['title'] = __('Booking Cancelled', 'rrze-rsvp');
			$data['text'] = sprintf(__('The booking has been %s', 'rrze-rsvp'), _x('cancelled', 'Booking', 'rrze-rsvp'));
			$data['customer_has_received_an_email'] = __('Your customer has received an email cancellation.', 'rrze-rsvp');
			$response = 'cancelled';
		} elseif ($alreadyDone && $bookingConfirmed) {
			$data['title'] = __('Booking Confirmed', 'rrze-rsvp');
			$data['text'] = sprintf(__('The booking has already been %s.', 'rrze-rsvp'), _x('confirmed', 'Booking', 'rrze-rsvp'));
			$response = 'confirmed';
		} elseif ($alreadyDone && $bookingCancelled) {
			$data['title'] = __('Booking Cancelled', 'rrze-rsvp');
			$data['text'] = sprintf(__('The booking has already been %s.', 'rrze-rsvp'), _x('cancelled', 'Booking', 'rrze-rsvp'));
			$response = 'cancelled';
		} else {
			$data['title'] = __('Action not available', 'rrze-rsvp');
			$data['text'] = __('No action was taken.', 'rrze-rsvp');
			$response = 'no-action';
		}

		$customerName = sprintf('%s: %s %s', __('Name', 'rrze-rsvp'), $booking['guest_firstname'], $booking['guest_lastname']);
		$customerEmail = sprintf('%s: %s', __('Email', 'rrze-rsvp'), $booking['guest_email']);

		$data['already_done'] = $alreadyDone;
		$data['no-action'] = ($response == 'no-action');
		$data['class_cancelled'] = in_array($response, ['cancelled', 'already-cancelled']) ? 'cancelled' : '';

		$data['room_name'] = $booking['room_name'];

		$data['date'] = $booking['date'];
		$data['time'] = $booking['time'];

		$data['customer']['name'] = $customerName;
		$data['customer']['email'] = $customerEmail;

		add_filter('the_content', function ($content) use ($data) {
			return $this->template->getContent('reply/booking-admin', $data);
		});

		do_action('rrze-rsvp-tracking', get_current_blog_id(), $bookingId);
	}

	protected function bookingReplyCustomer(int $bookingId, array $booking, string $action)
	{
		$start = $booking['start'];
		$end = $booking['end'];
		$now = current_time('timestamp');

		$bookingMode = get_post_meta($booking['room'], 'rrze-rsvp-room-bookingmode', true);
		$sendCheckoutNotification = (get_post_meta($booking['room'], 'rrze-rsvp-room-checkout-notification', true) == 'on');
        $autoConfirmation = Functions::getBoolValueFromAtt(get_post_meta($booking['room'], 'rrze-rsvp-room-auto-confirmation', true));
        $adminConfirmationRequired = $autoConfirmation ? false : true; // Verwirrende Post-Meta-Bezeichnung vereinfacht

		$userConfirmed = (get_post_meta($bookingId, 'rrze-rsvp-customer-status', true) == 'confirmed' || $booking['status'] == 'customer-confirmed'); // post-meta 'rrze-rsvp-customer-status' wegen Abwärtskompatibilität vor 12/2020
		$bookingBooked = ($booking['status'] == 'booked');
		$bookingConfirmed = ($booking['status'] == 'confirmed');
		$bookingCheckedIn = ($booking['status'] == 'checked-in');
		$bookingCheckedOut = ($booking['status'] == 'checked-out');
		$bookingCancelled = ($booking['status'] == 'cancelled');

		if ($bookingBooked && $action == 'confirm') {			
			$this->email->doEmail('customerConfirmed', 'customer', $bookingId);
			if ($adminConfirmationRequired) {
                $this->email->doEmail('adminConfirmationRequired', 'admin', $bookingId);
                update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'customer-confirmed');
            } else {
                update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'confirmed');
            }
			$userConfirmed = true;
		} elseif (!$bookingCancelled && !$bookingCheckedOut && $action == 'maybe-cancel') {
			update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'cancelled');
			if (Functions::getBoolValueFromAtt($this->options->email_notification_if_cancel) == true) {
                $this->email->doEmail('bookingCancelled', 'admin', $bookingId);
            }
			$bookingCancelled = true;
		} elseif (!$bookingCancelled && !$bookingCheckedIn && ($bookingConfirmed || $bookingCheckedOut) && $action == 'checkin') {
			$offset = 15 * MINUTE_IN_SECONDS;
			if (($start - $offset) <= $now && $end >= $now) {
				update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'checked-in');
				$bookingCheckedIn = true;
			}
		} elseif (!$bookingCancelled && !$bookingCheckedOut && $bookingCheckedIn && $action == 'checkout') {
            $offset = 15 * MINUTE_IN_SECONDS;
            if (($start - $offset) <= $now && $end >= $now) {
				update_post_meta($bookingId, 'rrze-rsvp-booking-status', 'checked-out');
                if ($sendCheckoutNotification) {
                    $this->email->doEmail('bookingCheckedOut', 'admin', $bookingId);
                }
				$bookingCheckedOut = true;
			}
		}

		if (!$bookingCancelled && !$bookingCheckedOut && $action == 'cancel') {
			$response = 'maybe-cancel';
		} elseif ($bookingCancelled && $action == 'maybe-cancel') {
			$response = 'cancelled';
		} elseif ($bookingCancelled && $action == 'cancel') {
			$response = 'already-cancelled';
		} elseif ($userConfirmed && $action == 'confirm') {
			$response = 'confirmed';
		} elseif (!$bookingCheckedIn && $action == 'checkin') {
			$response = 'cannot-checked-in';
		} elseif ($bookingCheckedIn && $action == 'checkin') {
			$response = 'already-checked-in';
		} elseif (!$bookingCheckedOut && $action == 'checkout') {
			$response = 'cannot-checked-out';
		} elseif ($bookingCheckedOut && $action == 'checkout') {
			$response = 'already-checked-out';
		} else {
			$response = 'no-action';
		}

		$data = [];
		// Is locale not english?
		$data['is_locale_not_english'] = !Functions::isLocaleEnglish() ? true : false;

		$data['room_name'] = $booking['room_name'];
		$data['seat_name'] = ($bookingMode != 'consultation') ? $booking['seat_name'] : '';

		$data['date'] = $booking['date'];
		$data['time'] = $booking['time'];
		$data['date_en'] = $booking['date_en'];
		$data['time_en'] = $booking['time_en'];

		$cancelUrl = Functions::bookingReplyUrl('maybe-cancel', sprintf('%s-%s-customer', $bookingId, $booking['start']), $bookingId);
		$data['cancel_btn'] = sprintf('<a href="%s" class="button button-cancel">%s</a>', $cancelUrl, _x('Cancel', 'Booking', 'rrze-rsvp'));
		$data['cancel_btn_en'] = sprintf('<a href="%s" class="button button-cancel">Cancel</a>', $cancelUrl);

		switch ($response) {
			case 'maybe-cancel':
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
			case 'already-cancelled':
				$data['booking_cancelled'] = __('Booking Cancelled', 'rrze-rsvp');
				$data['booking_has_been_cancelled'] = __('The booking is already canceled.', 'rrze-rsvp');
				$data['booking_cancelled_en'] = 'Booking Cancelled';
				$data['booking_has_been_cancelled_en'] = 'The booking is already canceled.';
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

		add_filter('the_content', function ($content) use ($data) {
			return $this->template->getContent('reply/booking-customer', $data);
		});

		do_action('rrze-rsvp-tracking', get_current_blog_id(), $bookingId);
	}

	protected function ajaxResult(array $returnAry)
	{
		echo json_encode($returnAry);
		exit;
	}

	protected function isBookingArchived(int $postId): bool
	{
		$now = current_time('timestamp');
		$start = absint(get_post_meta($postId, 'rrze-rsvp-booking-start', true));
		$start = new Carbon(date('Y-m-d H:i:s', $start), wp_timezone());
		$end = absint(get_post_meta($postId, 'rrze-rsvp-booking-end', true));
		$end = $end ? $end : $start->endOfDay()->getTimestamp();
		$status = get_post_meta($postId, 'rrze-rsvp-booking-status', true);
		return (($status == 'cancelled') || ($end < $now));
	}

	protected function canDeleteBooking(int $postId): bool
	{
		$start = absint(get_post_meta($postId, 'rrze-rsvp-booking-start', true));
		$start = new Carbon(date('Y-m-d H:i:s', $start), wp_timezone());
		$status = get_post_meta($postId, 'rrze-rsvp-booking-status', true);
		if (
			$this->isBookingArchived($postId)
			&& !(in_array($status, ['checked-in', 'checked-out']) || $start->endOfDay()->gt(new Carbon('now')))
		) {
			return true;
		} else {
			return false;
		}
	}
}
