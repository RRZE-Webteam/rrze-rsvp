<?php

namespace RRZE\RSVP\Access;

defined('ABSPATH') || exit;

use WP_Error;

/**
 * Admin-only room permission editor with request-local validation feedback.
 *
 * Email lists never pass through the general settings option or a transient.
 */
final class Admin
{
    /** @var true|WP_Error|null Outcome of the submission handled in this request. */
    private true|WP_Error|null $result = null;

    /** @var int Room selected by a validated submission. */
    private int $submittedRoom = 0;

    /** @var string|null Unsaved input retained only for this request's error response. */
    private ?string $submittedEmails = null;

    /**
     * Register the separate handler before the WordPress admin page is rendered.
     *
     * @return void
     */
    public function onLoaded(): void
    {
        add_action('admin_init', [$this, 'handlePost']);
    }

    /**
     * Handle this editor's POST without storing plaintext feedback between requests.
     *
     * @return void
     */
    public function handlePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
            || ($_POST['rrze_rsvp_room_access_action'] ?? null) !== 'save'
            || ($_GET['page'] ?? null) !== 'rrze-rsvp'
            || ($_GET['current-tab'] ?? null) !== 'rrze-rsvp-room_access') {
            return;
        }

        nocache_headers();
        $this->result = $this->submit(wp_unslash($_POST));
    }

    /**
     * Validate the management boundary before sending input to encrypted storage.
     *
     * @param array<string, mixed> $request Unslashed editor submission.
     * @return true|WP_Error Success or an error without submitted addresses.
     */
    public function submit(#[\SensitiveParameter] array $request): true|WP_Error
    {
        if (!current_user_can('manage_options') || !RoomAccess::isEnabled()) {
            return new WP_Error('room_access_admin_forbidden', __('You cannot manage booking permissions.', 'rrze-rsvp'), ['status' => 403]);
        }

        $roomId = $this->roomId($request['room_id'] ?? null);
        if (!$roomId || !$this->isRoom($roomId)) {
            return new WP_Error('room_access_invalid_room', __('Select a room on this website.', 'rrze-rsvp'), ['status' => 400]);
        }
        if (!is_string($request['rrze_rsvp_room_access_nonce'] ?? null)
            || !wp_verify_nonce($request['rrze_rsvp_room_access_nonce'], 'rrze-rsvp-room-access-' . $roomId)) {
            return new WP_Error('room_access_admin_nonce', __('The form has expired. Reload the page and try again.', 'rrze-rsvp'), ['status' => 403]);
        }
        if (!is_string($request['emails'] ?? null)) {
            return new WP_Error('room_access_invalid_emails', __('Enter one valid email address per line.', 'rrze-rsvp'), ['status' => 422]);
        }

        $this->submittedRoom = $roomId;
        $this->submittedEmails = $request['emails'];
        $emails = preg_split('/\R/u', $request['emails']);
        if ($emails === false) {
            return new WP_Error('room_access_invalid_emails', __('Enter one valid email address per line.', 'rrze-rsvp'), ['status' => 422]);
        }
        $addresses = [];
        foreach ($emails as $email) {
            if (trim($email) !== '') {
                $addresses[] = $email;
            }
        }

        return RoomAccess::save($roomId, $addresses);
    }

    /**
     * Render the room selector and editor without exposing lists to non-admins.
     *
     * @return void
     */
    public function render(): void
    {
        if (!current_user_can('manage_options') || !RoomAccess::isEnabled()) {
            $this->notice(__('You cannot manage booking permissions.', 'rrze-rsvp'), 'error');
            return;
        }

        echo '<h2>' . esc_html__('Booking permissions', 'rrze-rsvp') . '</h2>';
        echo '<p>' . esc_html__('Assign allowed email addresses to a room. For rooms with a list, only the email address supplied by SSO or LDAP can be used to book.', 'rrze-rsvp') . '</p>';
        echo '<p>' . esc_html__('Rooms without a list remain bookable under their existing rules. These lists apply to public room booking forms, not to manual bookings or course registrations.', 'rrze-rsvp') . '</p>';

        if ($this->result === true) {
            $this->notice(__('Booking permissions saved.', 'rrze-rsvp'), 'success');
        } elseif (is_wp_error($this->result)) {
            $this->notice($this->result->get_error_message(), 'error');
        }

        $roomId = $this->submittedRoom ?: $this->roomId($_GET['room_id'] ?? null);
        $rooms = get_posts([
            'post_type' => 'room',
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        if (!$rooms) {
            echo '<p>' . esc_html__('Create a room before assigning booking permissions.', 'rrze-rsvp') . '</p>';
            return;
        }

        echo '<form method="get" action="' . esc_url(admin_url('options-general.php')) . '">';
        echo '<input type="hidden" name="page" value="rrze-rsvp">';
        echo '<input type="hidden" name="current-tab" value="rrze-rsvp-room_access">';
        echo '<label for="rrze-rsvp-access-room">' . esc_html__('Room', 'rrze-rsvp') . '</label> ';
        echo '<select id="rrze-rsvp-access-room" name="room_id">';
        echo '<option value="">' . esc_html__('Select a room', 'rrze-rsvp') . '</option>';
        foreach ($rooms as $room) {
            echo '<option value="' . esc_attr((string) $room->ID) . '"' . selected($roomId, $room->ID, false) . '>'
                . esc_html($room->post_title . ' (#' . $room->ID . ')') . '</option>';
        }
        echo '</select> ';
        submit_button(__('Select room', 'rrze-rsvp'), 'secondary', 'select_room', false);
        echo '</form>';

        if (!$roomId) {
            return;
        }
        if (!$this->isRoom($roomId)) {
            $this->notice(__('Select a room on this website.', 'rrze-rsvp'), 'error');
            return;
        }

        $emails = RoomAccess::read($roomId);
        if (is_wp_error($emails)) {
            $this->notice(__('The stored permission list cannot be read. Its restriction remains active. Restore the encryption key or contact the website administrator before editing it.', 'rrze-rsvp'), 'error');
            return;
        }

        $loginConfigured = $this->hasLoginRequirement($roomId);
        if (!$loginConfigured) {
            $this->notice(__('Enable SSO or LDAP for this room before adding allowed email addresses. An existing list keeps the room restricted until login is configured or the list is explicitly cleared.', 'rrze-rsvp'), 'warning');
            echo '<p><a href="' . esc_url(get_edit_post_link($roomId, 'raw')) . '">' . esc_html__('Edit room login settings', 'rrze-rsvp') . '</a></p>';
            if (!$emails) {
                return;
            }
        }

        $value = is_wp_error($this->result) && $roomId === $this->submittedRoom && $this->submittedEmails !== null
            ? $this->submittedEmails : implode("\n", $emails);
        echo '<form method="post" action="' . esc_url($this->editorUrl($roomId)) . '">';
        echo '<input type="hidden" name="rrze_rsvp_room_access_action" value="save">';
        echo '<input type="hidden" name="room_id" value="' . esc_attr((string) $roomId) . '">';
        wp_nonce_field('rrze-rsvp-room-access-' . $roomId, 'rrze_rsvp_room_access_nonce');
        echo '<table class="form-table" role="presentation"><tr><th scope="row"><label for="rrze-rsvp-access-emails">'
            . esc_html__('Allowed email addresses', 'rrze-rsvp') . '</label></th><td>';
        echo '<textarea id="rrze-rsvp-access-emails" name="emails" rows="12" cols="55" class="large-text code" spellcheck="false" autocomplete="off" aria-describedby="rrze-rsvp-access-help">'
            . esc_textarea($value) . '</textarea>';
        echo '<p class="description" id="rrze-rsvp-access-help">' . esc_html__('Enter one email address per line. Duplicates and surrounding spaces are removed. Saving an empty list removes the restriction and makes the room bookable under its existing rules.', 'rrze-rsvp') . '</p>';
        echo '</td></tr></table>';
        submit_button(__('Save booking permissions', 'rrze-rsvp'));
        echo '</form>';
    }

    /**
     * Parse a positive room identifier without coercing arrays or negative values.
     *
     * @param mixed $value Submitted identifier.
     * @return int Positive identifier, or zero for invalid input.
     */
    private function roomId($value): int
    {
        if (!is_string($value) && !is_int($value)) {
            return 0;
        }
        return (int) filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    }

    /**
     * Verify that the object belongs to this website and can be managed.
     *
     * @param int $roomId Room identifier.
     * @return bool Whether this is a non-trashed editable room.
     */
    private function isRoom(int $roomId): bool
    {
        $room = get_post($roomId);
        return $room && $room->post_type === 'room'
            && !in_array($room->post_status, ['trash', 'auto-draft'], true)
            && current_user_can('edit_post', $roomId);
    }

    /**
     * Explain the existing room login prerequisite before users submit a list.
     *
     * @param int $roomId Room identifier.
     * @return bool Whether SSO or a configured LDAP login is required.
     */
    private function hasLoginRequirement(int $roomId): bool
    {
        $enabledValues = ['on', 'yes', 'true', '1', 1, true];
        $sso = get_post_meta($roomId, 'rrze-rsvp-room-sso-required', true);
        $ldap = get_post_meta($roomId, 'rrze-rsvp-room-ldap-required', true);
        $options = get_option('rrze_rsvp', []);
        return in_array($sso, $enabledValues, true)
            || (in_array($ldap, $enabledValues, true) && is_array($options) && !empty($options['ldap_server']));
    }

    /**
     * Build the local editor URL without including addresses or encryption data.
     *
     * @param int $roomId Selected room identifier.
     * @return string Administrative editor URL.
     */
    private function editorUrl(int $roomId): string
    {
        return add_query_arg(['page' => 'rrze-rsvp', 'current-tab' => 'rrze-rsvp-room_access', 'room_id' => $roomId], admin_url('options-general.php'));
    }

    /**
     * Display escaped, non-sensitive feedback using native WordPress notices.
     *
     * @param string $message Human-readable feedback.
     * @param string $type Notice severity chosen by this controller.
     * @return void
     */
    private function notice(string $message, string $type): void
    {
        echo '<div class="notice notice-' . esc_attr($type) . ' inline"><p>' . esc_html($message) . '</p></div>';
    }
}
