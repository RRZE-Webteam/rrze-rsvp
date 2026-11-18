<?php

namespace RRZE\RSVP\Access;

defined('ABSPATH') || exit;

use WP_Error;

/** Admin-only permission and department editor; plaintext feedback stays request-local. */
final class Admin
{
    /** @var true|WP_Error|null Request-local save outcome. */
    private true|WP_Error|null $result = null;
    /** @var int Room selected by a validated submission. */
    private int $submittedRoom = 0;
    /** @var array|null Complete input retained only for this error response. */
    private ?array $submittedInput = null;
    /** @var DepartmentAdmin|null Request-local department controller. */
    private ?DepartmentAdmin $departments = null;

    /** @return void Register the separate handler before rendering the admin page. */
    public function onLoaded(): void { add_action('admin_init', [$this, 'handlePost']); }

    /** @return void Handle editor POSTs without persistent plaintext feedback. */
    public function handlePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_GET['page'] ?? null) !== 'rrze-rsvp'
            || ($_GET['current-tab'] ?? null) !== 'rrze-rsvp-room_access') return;
        if (($_GET['access-view'] ?? null) === 'departments') {
            $this->departments ??= new DepartmentAdmin();
            $this->departments->handlePost();
            return;
        }
        if (($_POST['rrze_rsvp_room_access_action'] ?? null) !== 'save') return;
        nocache_headers();
        $this->result = $this->submit(wp_unslash($_POST));
    }

    /**
     * Validate a complete table and merge additions before a single atomic write.
     * @param array<string,mixed> $request Unslashed editor submission.
     * @return true|WP_Error Safe save result without addresses in errors.
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
        $this->submittedRoom = $roomId;
        $rows = $request['entries'] ?? [];
        $count = $request['expected_rows'] ?? null;
        if ((!is_string($count) && !is_int($count))
            || filter_var($count, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false
            || !is_array($rows) || !array_is_list($rows) || count($rows) !== (int) $count
            || ($request['complete'] ?? null) !== '1'
            || !is_string($request['revision'] ?? null) || $request['revision'] === ''
            || !is_string($request['emails'] ?? null) || !is_string($request['bulk_department'] ?? null)) return $this->incomplete();
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['email'] ?? null) || !is_string($row['department_id'] ?? null)
                || (isset($row['remove']) && $row['remove'] !== '1')) return $this->incomplete();
        }
        $this->submittedInput = ['entries' => $rows, 'emails' => $request['emails'],
            'bulk_department' => $request['bulk_department'], 'revision' => $request['revision']];
        $snapshot = $this->snapshot($roomId);
        if (is_wp_error($snapshot)) return $snapshot;
        if (!hash_equals($snapshot['revision'], $request['revision'])) return $this->conflict();
        if (count($snapshot['entries']) !== (int) $count) return $this->incomplete();
        $entries = [];
        foreach ($rows as $row) {
            if (($row['remove'] ?? '') === '1') continue;
            $email = strtolower(trim($row['email']));
            $department = $row['department_id'] === '' ? null : $row['department_id'];
            if (!is_email($email) || strlen($email) > 254) return $this->invalidEmails();
            if (isset($entries[$email]) && $entries[$email]['department_id'] !== $department) {
                return new WP_Error('room_access_conflicting_departments', __('The same email address has different departments. Assign only one department per address.', 'rrze-rsvp'), ['status' => 422]);
            }
            $entries[$email] = ['email' => $email, 'department_id' => $department];
        }
        $bulk = preg_split('/\R/u', $request['emails']);
        if ($bulk === false) return $this->invalidEmails();
        foreach ($bulk as $line) {
            $email = strtolower(trim($line));
            if ($email === '') continue;
            if (!is_email($email) || strlen($email) > 254) return $this->invalidEmails();
            // The common department applies only to newly added addresses.
            if (!isset($entries[$email])) {
                $entries[$email] = ['email' => $email, 'department_id' => $request['bulk_department'] === '' ? null : $request['bulk_department']];
            }
        }
        return RoomAccess::saveEntries($roomId, array_values($entries), $request['revision']);
    }

    /**
     * Render the administrator view with separate room, list and bulk-entry panels.
     *
     * @return void
     */
    public function render(): void
    {
        if (!current_user_can('manage_options') || !RoomAccess::isEnabled()) {
            $this->notice(__('You cannot manage booking permissions.', 'rrze-rsvp'), 'error');
            return;
        }
        $departmentView = ($_GET['access-view'] ?? '') === 'departments';
        echo '<h2>' . esc_html__('Booking permissions', 'rrze-rsvp') . '</h2>';
        echo '<nav class="rrze-rsvp-settings-subnav" aria-label="' . esc_attr(__('Booking permissions', 'rrze-rsvp')) . '"><a href="' . esc_url($this->editorUrl(0)) . '"'
            . (!$departmentView ? ' aria-current="page"' : '') . '>' . esc_html__('Room permissions', 'rrze-rsvp') . '</a>';
        echo '<a href="' . esc_url(add_query_arg(['access-view' => 'departments'], $this->editorUrl(0))) . '"'
            . ($departmentView ? ' aria-current="page"' : '') . '>' . esc_html__('Departments', 'rrze-rsvp') . '</a></nav>';
        if ($departmentView) {
            $this->departments ??= new DepartmentAdmin();
            $this->departments->render();
            return;
        }
        echo '<div class="rrze-rsvp-settings-intro"><p>' . esc_html__('Assign allowed email addresses to a room. For rooms with a list, only the email address supplied by SSO or LDAP can be used to book.', 'rrze-rsvp') . '</p>';
        echo '<p class="description">' . esc_html__('Rooms without a list remain bookable under their existing rules. These lists apply to public room booking forms, not to manual bookings or course registrations.', 'rrze-rsvp') . '</p></div>';
        if ($this->result === true) $this->notice(__('Booking permissions saved.', 'rrze-rsvp'), 'success');
        elseif (is_wp_error($this->result)) $this->notice($this->result->get_error_message(), 'error');
        $roomId = $this->submittedRoom ?: $this->roomId($_GET['room_id'] ?? null);
        if (!$this->renderRoomSelector($roomId)) return;
        if (!$roomId) {
            echo '<div class="rrze-rsvp-empty-state"><span class="dashicons dashicons-admin-multisite" aria-hidden="true"></span><h3>'
                . esc_html__('Select a room', 'rrze-rsvp') . '</h3><p>'
                . esc_html__('Choose a room above to view and edit its booking permissions.', 'rrze-rsvp') . '</p></div>';
            return;
        }
        if (!$this->isRoom($roomId)) {
            $this->notice(__('Select a room on this website.', 'rrze-rsvp'), 'error');
            return;
        }
        $snapshot = $this->snapshot($roomId);
        if (is_wp_error($snapshot)) {
            $this->notice(__('The stored permission list cannot be read. Its restriction remains active. Restore the encryption key or contact the website administrator before editing it.', 'rrze-rsvp'), 'error');
            return;
        }
        $catalog = DepartmentCatalog::read();
        if (is_wp_error($catalog)) { $this->notice($catalog->get_error_message(), 'error'); return; }
        if (!$this->hasLoginRequirement($roomId)) {
            $this->notice(__('Enable SSO or LDAP for this room before adding allowed email addresses. An existing list keeps the room restricted until login is configured or the list is explicitly cleared.', 'rrze-rsvp'), 'warning');
            echo '<p><a href="' . esc_url(get_edit_post_link($roomId, 'raw')) . '">' . esc_html__('Edit room login settings', 'rrze-rsvp') . '</a></p>';
            if (!$snapshot['entries']) return;
        }
        $input = is_wp_error($this->result) && $roomId === $this->submittedRoom ? $this->submittedInput : null;
        $rows = $input['entries'] ?? $snapshot['entries'];
        $revision = $input['revision'] ?? $snapshot['revision'];
        echo '<form class="rrze-rsvp-access-editor" method="post" action="' . esc_url($this->editorUrl($roomId)) . '">';
        echo '<input type="hidden" name="rrze_rsvp_room_access_action" value="save"><input type="hidden" name="room_id" value="' . esc_attr((string) $roomId) . '">';
        echo '<input type="hidden" name="revision" value="' . esc_attr($revision) . '"><input type="hidden" name="expected_rows" value="' . esc_attr((string) count($rows)) . '">';
        wp_nonce_field('rrze-rsvp-room-access-' . $roomId, 'rrze_rsvp_room_access_nonce');
        echo '<section class="rrze-rsvp-settings-card rrze-rsvp-settings-card-flush" aria-labelledby="access-list-heading"><div class="rrze-rsvp-card-heading"><h3 id="access-list-heading">'
            . esc_html__('Allowed email addresses', 'rrze-rsvp') . ' <span class="rrze-rsvp-count">' . count($rows) . '</span></h3></div>';
        echo '<table class="widefat rrze-rsvp-settings-table rrze-rsvp-access-table"><thead><tr><th scope="col">' . esc_html__('Email', 'rrze-rsvp') . '</th><th scope="col">'
            . esc_html__('Department', 'rrze-rsvp') . '</th><th scope="col">' . esc_html__('Remove', 'rrze-rsvp') . '</th></tr></thead><tbody>';
        foreach ($rows as $index => $entry) {
            echo '<tr><td><label class="screen-reader-text" for="access-email-' . $index . '">' . esc_html__('Email', 'rrze-rsvp') . '</label>';
            echo '<input type="email" class="regular-text" id="access-email-' . $index . '" name="entries[' . $index . '][email]" value="'
                . esc_attr($entry['email']) . '" maxlength="254" required></td><td>';
            echo '<label class="screen-reader-text" for="access-department-' . $index . '">' . esc_html__('Department', 'rrze-rsvp') . '</label>';
            $this->departmentSelect('entries[' . $index . '][department_id]', 'access-department-' . $index, $entry['department_id'] ?? '', $catalog, true);
            echo '</td><td><label><input type="checkbox" name="entries[' . $index . '][remove]" value="1"'
                . (($entry['remove'] ?? '') === '1' ? ' checked' : '') . '> ' . esc_html__('Remove', 'rrze-rsvp') . '</label></td></tr>';
        }
        if (!$rows) echo '<tr><td colspan="3" class="rrze-rsvp-empty-cell">' . esc_html__('No addresses assigned. This room has no additional restriction.', 'rrze-rsvp') . '</td></tr>';
        echo '</tbody></table></section><section class="rrze-rsvp-settings-card" aria-labelledby="access-add-heading"><h3 id="access-add-heading">'
            . esc_html__('Add email addresses', 'rrze-rsvp') . '</h3><div class="rrze-rsvp-access-add"><div><p><label for="rrze-rsvp-access-emails">' . esc_html__('One email address per line', 'rrze-rsvp') . '</label></p>';
        echo '<textarea id="rrze-rsvp-access-emails" name="emails" rows="6" cols="55" class="large-text code" spellcheck="false" autocomplete="off" aria-describedby="rrze-rsvp-access-help">'
            . esc_textarea($input['emails'] ?? '') . '</textarea></div><div><p><label for="access-bulk-department">' . esc_html__('Department for new addresses', 'rrze-rsvp') . '</label></p>';
        $this->departmentSelect('bulk_department', 'access-bulk-department', $input['bulk_department'] ?? '', $catalog, false);
        echo '<p class="description" id="rrze-rsvp-access-help">' . esc_html__('The selected department applies only to new addresses. Addresses already in the table keep their assignment when pasted again. Departments are optional and are not shown publicly.', 'rrze-rsvp') . '</p></div></div></section>';
        echo '<div class="rrze-rsvp-settings-hint"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><p class="description">' . esc_html__('To remove the restriction, select Remove for every address and leave the new-address field empty. The room will then be bookable under its existing rules.', 'rrze-rsvp') . '</p></div>';
        // Must follow every data field to detect max_input_vars truncation.
        echo '<input type="hidden" name="complete" value="1">';
        echo '<div class="rrze-rsvp-settings-actions">';
        submit_button(__('Save booking permissions', 'rrze-rsvp'));
        echo '</div></form>';
    }

    /**
     * Read entries and their revision together, rejecting concurrent changes.
     *
     * @param int $roomId Room ID.
     * @return array{entries:array,revision:string}|WP_Error Stable read pair or failure.
     */
    private function snapshot(int $roomId): array|WP_Error
    {
        $before = RoomAccess::revision($roomId);
        $entries = RoomAccess::readEntries($roomId);
        $after = RoomAccess::revision($roomId);
        foreach ([$before, $entries, $after] as $value) if (is_wp_error($value)) return $value;
        return hash_equals($before, $after) ? ['entries' => $entries, 'revision' => $after] : $this->conflict();
    }

    /**
     * Render active choices, retaining an archived assignment only on its current row.
     * @param string $name Field name.
     * @param string $id Label target.
     * @param string $selectedId Current optional UUID.
     * @param array $catalog Department records.
     * @param bool $keepArchived Whether this is an existing table row.
     * @return void
     */
    private function departmentSelect(string $name, string $id, string $selectedId, array $catalog, bool $keepArchived): void
    {
        echo '<select name="' . esc_attr($name) . '" id="' . esc_attr($id) . '"><option value="">' . esc_html__('No department', 'rrze-rsvp') . '</option>';
        $known = $selectedId === '';
        foreach ($catalog as $department) {
            $selected = $department['id'] === $selectedId;
            $known = $known || $selected;
            if ($department['archived'] && !$selected) continue;
            $label = $department['name'];
            if ($department['archived']) {
                /* translators: %s: Department name. */
                $label = sprintf(__('%s (archived)', 'rrze-rsvp'), $label);
            }
            echo '<option value="' . esc_attr($department['id']) . '"' . ($selected ? ' selected' : '')
                . ($department['archived'] && !$keepArchived ? ' disabled' : '') . '>' . esc_html($label) . '</option>';
        }
        if (!$known) echo '<option value="' . esc_attr($selectedId) . '" selected' . (!$keepArchived ? ' disabled' : '') . '>' . esc_html__('Unavailable department', 'rrze-rsvp') . '</option>';
        echo '</select>';
    }

    /**
     * Render a separate GET form so choosing a room never submits an edited list.
     *
     * @param int $roomId Selected room ID.
     * @return bool Whether rooms are available.
     */
    private function renderRoomSelector(int $roomId): bool
    {
        $rooms = get_posts(['post_type' => 'room', 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'], 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']);
        if (!$rooms) { echo '<div class="rrze-rsvp-empty-state"><p>' . esc_html__('Create a room before assigning booking permissions.', 'rrze-rsvp') . '</p></div>'; return false; }
        echo '<form class="rrze-rsvp-settings-card rrze-rsvp-room-selector" method="get" action="' . esc_url(admin_url('options-general.php')) . '"><input type="hidden" name="page" value="rrze-rsvp">';
        echo '<input type="hidden" name="current-tab" value="rrze-rsvp-room_access"><div><label for="rrze-rsvp-access-room">' . esc_html__('Room', 'rrze-rsvp') . '</label>';
        echo '<select id="rrze-rsvp-access-room" name="room_id"><option value="">' . esc_html__('Select a room', 'rrze-rsvp') . '</option>';
        foreach ($rooms as $room) echo '<option value="' . esc_attr((string) $room->ID) . '"' . selected($roomId, $room->ID, false) . '>' . esc_html($room->post_title . ' (#' . $room->ID . ')') . '</option>';
        echo '</select></div>';
        submit_button(__('Select room', 'rrze-rsvp'), 'secondary', 'select_room', false);
        echo '</form>';
        return true;
    }

    /**
     * @param mixed $value Input ID.
     * @return int Positive room ID or zero.
     */
    private function roomId($value): int { return is_string($value) || is_int($value) ? (int) filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : 0; }
    /**
     * @param int $roomId Room ID.
     * @return bool Whether this is an editable room on this site.
     */
    private function isRoom(int $roomId): bool
    {
        $room = get_post($roomId);
        return $room && $room->post_type === 'room' && !in_array($room->post_status, ['trash', 'auto-draft'], true) && current_user_can('edit_post', $roomId);
    }
    /**
     * @param int $roomId Room ID.
     * @return bool Whether SSO or configured LDAP is required.
     */
    private function hasLoginRequirement(int $roomId): bool
    {
        $values = ['on', 'yes', 'true', '1', 1, true];
        $options = get_option('rrze_rsvp', []);
        return in_array(get_post_meta($roomId, 'rrze-rsvp-room-sso-required', true), $values, true)
            || (in_array(get_post_meta($roomId, 'rrze-rsvp-room-ldap-required', true), $values, true) && is_array($options) && !empty($options['ldap_server']));
    }
    /**
     * @param int $roomId Optional room ID.
     * @return string Editor URL without personal data.
     */
    private function editorUrl(int $roomId): string { return add_query_arg(['page' => 'rrze-rsvp', 'current-tab' => 'rrze-rsvp-room_access', 'room_id' => $roomId], admin_url('options-general.php')); }
    /** @return WP_Error Incomplete-table error which prevents destructive truncation. */
    private function incomplete(): WP_Error { return new WP_Error('room_access_incomplete_form', __('The submitted table is incomplete. No changes were saved. Reload the page and try again.', 'rrze-rsvp'), ['status' => 422]); }
    /** @return WP_Error Safe invalid-address feedback. */
    private function invalidEmails(): WP_Error { return new WP_Error('room_access_invalid_emails', __('Enter one valid email address per line.', 'rrze-rsvp'), ['status' => 422]); }
    /** @return WP_Error Conflict requiring a reload before any overwrite. */
    private function conflict(): WP_Error { return new WP_Error('room_access_conflict', __('This permission list changed while you were editing it. Reload the page before saving again.', 'rrze-rsvp'), ['status' => 409]); }
    /**
     * @param string $message Safe feedback.
     * @param string $type Known notice severity.
     * @return void
     */
    private function notice(string $message, string $type): void { echo '<div class="notice notice-' . esc_attr($type) . ' inline"><p>' . esc_html($message) . '</p></div>'; }
}
