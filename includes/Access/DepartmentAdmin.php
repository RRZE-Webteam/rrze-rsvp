<?php

namespace RRZE\RSVP\Access;

defined('ABSPATH') || exit;

use WP_Error;

/** Maintains internal department labels without exposing them publicly. */
final class DepartmentAdmin
{
    /** @var true|WP_Error|null Outcome of the current catalog operation. */
    private true|WP_Error|null $result = null;
    /** @var array Request-local failed name input. */
    private array $input = [];

    /** @return void Dispatch one administrator POST for the selected catalog operation. */
    public function handlePost(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['department_action'])) {
            return;
        }
        nocache_headers();
        $this->result = $this->submit(wp_unslash($_POST));
    }

    /**
     * Verify administrator rights and an operation-scoped nonce before a catalog write.
     *
     * @param array<string, mixed> $request Unslashed department operation.
     * @return true|WP_Error Safe outcome retained only in this response.
     */
    public function submit(array $request): true|WP_Error
    {
        if (!current_user_can('manage_options') || !RoomAccess::isEnabled()) {
            return new WP_Error('room_access_admin_forbidden', __('You cannot manage booking permissions.', 'rrze-rsvp'), ['status' => 403]);
        }
        $action = $request['department_action'] ?? null;
        $id = $request['department_id'] ?? '';
        if (!is_string($action) || !in_array($action, ['create', 'rename', 'archive', 'reactivate'], true)
            || !is_string($id) || ($action !== 'create' && $id === '')) {
            return new WP_Error('room_access_invalid_department', __('Select a valid department action.', 'rrze-rsvp'), ['status' => 400]);
        }
        if (!is_string($request['department_nonce'] ?? null)
            || !wp_verify_nonce($request['department_nonce'], 'rrze-rsvp-department-' . $action . '-' . $id)) {
            return new WP_Error('room_access_admin_nonce', __('The form has expired. Reload the page and try again.', 'rrze-rsvp'), ['status' => 403]);
        }
        if (in_array($action, ['create', 'rename'], true) && !is_string($request['department_name'] ?? null)) {
            return new WP_Error('room_access_invalid_department', __('Enter a department name.', 'rrze-rsvp'), ['status' => 422]);
        }
        $this->input = ['action' => $action, 'id' => $id, 'name' => $request['department_name'] ?? ''];
        $result = match ($action) {
            'create' => DepartmentCatalog::create($request['department_name']),
            'rename' => DepartmentCatalog::rename($id, $request['department_name']),
            'archive' => DepartmentCatalog::setArchived($id, true),
            'reactivate' => DepartmentCatalog::setArchived($id, false),
        };
        return is_wp_error($result) ? $result : true;
    }

    /**
     * Render a distinct creation panel and responsive catalog with explicit status labels.
     *
     * @return void
     */
    public function render(): void
    {
        if (!current_user_can('manage_options') || !RoomAccess::isEnabled()) {
            $this->notice(__('You cannot manage booking permissions.', 'rrze-rsvp'), 'error');
            return;
        }
        echo '<div class="rrze-rsvp-settings-intro"><p>'
            . esc_html__('Departments are optional internal labels for allowed email addresses. Archived departments remain visible on existing assignments but cannot be assigned to new addresses.', 'rrze-rsvp') . '</p>';
        echo '</div>';
        if ($this->result === true) {
            $this->notice(__('Department saved.', 'rrze-rsvp'), 'success');
        } elseif (is_wp_error($this->result)) {
            $this->notice($this->result->get_error_message(), 'error');
        }
        $catalog = DepartmentCatalog::read();
        if (is_wp_error($catalog)) {
            $this->notice($catalog->get_error_message(), 'error');
            return;
        }
        $createName = is_wp_error($this->result) && ($this->input['action'] ?? '') === 'create' ? $this->input['name'] : '';
        echo '<section class="rrze-rsvp-settings-card" aria-labelledby="access-create-heading"><h3 id="access-create-heading">' . esc_html__('New department', 'rrze-rsvp') . '</h3>';
        $this->openForm('create', '');
        echo '<div class="rrze-rsvp-inline-form"><div><label for="access-new-department">' . esc_html__('Department name', 'rrze-rsvp') . '</label>';
        echo '<input id="access-new-department" name="department_name" class="regular-text" maxlength="200" required value="' . esc_attr($createName) . '"> ';
        echo '</div><button type="submit" class="button button-primary">' . esc_html__('Add department', 'rrze-rsvp') . '</button></div></form></section>';
        echo '<section class="rrze-rsvp-settings-card rrze-rsvp-settings-card-flush" aria-labelledby="access-departments-heading"><div class="rrze-rsvp-card-heading"><h3 id="access-departments-heading">'
            . esc_html__('Departments', 'rrze-rsvp') . ' <span class="rrze-rsvp-count">' . count($catalog) . '</span></h3></div>';
        if (!$catalog) {
            echo '<div class="rrze-rsvp-empty-state"><span class="dashicons dashicons-category" aria-hidden="true"></span><p><strong>' . esc_html__('No departments have been created.', 'rrze-rsvp') . '</strong></p><p>'
                . esc_html__('Create a department, then assign it to email addresses in the room permissions.', 'rrze-rsvp') . '</p></div></section>';
            return;
        }
        echo '<table class="widefat rrze-rsvp-settings-table rrze-rsvp-departments-table"><thead><tr><th scope="col">' . esc_html__('Department', 'rrze-rsvp') . '</th><th scope="col">'
            . esc_html__('Status', 'rrze-rsvp') . '</th><th scope="col">' . esc_html__('Actions', 'rrze-rsvp') . '</th></tr></thead><tbody>';
        foreach ($catalog as $department) {
            $id = $department['id'];
            $name = is_wp_error($this->result) && ($this->input['action'] ?? '') === 'rename' && ($this->input['id'] ?? '') === $id
                ? $this->input['name'] : $department['name'];
            echo '<tr' . ($department['archived'] ? ' class="is-archived"' : '') . '><td>';
            $this->openForm('rename', $id);
            echo '<label class="screen-reader-text" for="department-' . esc_attr($id) . '">' . esc_html__('Department name', 'rrze-rsvp') . '</label>';
            echo '<input id="department-' . esc_attr($id) . '" name="department_name" class="regular-text" maxlength="200" required value="' . esc_attr($name) . '"> ';
            echo '<button type="submit" class="button">' . esc_html__('Rename', 'rrze-rsvp') . '</button>';
            echo '</form></td><td><span class="rrze-rsvp-status' . ($department['archived'] ? ' is-archived' : '') . '">'
                . esc_html($department['archived'] ? __('Archived', 'rrze-rsvp') : __('Active', 'rrze-rsvp')) . '</span></td><td>';
            $this->openForm($department['archived'] ? 'reactivate' : 'archive', $id);
            echo '<button type="submit" class="button">' . esc_html($department['archived'] ? __('Reactivate', 'rrze-rsvp') : __('Archive', 'rrze-rsvp')) . '</button>';
            echo '</form></td></tr>';
        }
        echo '</tbody></table></section>';
    }

    /**
     * @param string $action Catalog operation.
     * @param string $id Optional department ID.
     * @return void Start an action-scoped form.
     */
    private function openForm(string $action, string $id): void
    {
        $url = add_query_arg(['page' => 'rrze-rsvp', 'current-tab' => 'rrze-rsvp-room_access', 'access-view' => 'departments'], admin_url('options-general.php'));
        echo '<form class="rrze-rsvp-department-form rrze-rsvp-department-' . esc_attr($action) . '" method="post" action="' . esc_url($url) . '"><input type="hidden" name="department_action" value="' . esc_attr($action) . '">';
        echo '<input type="hidden" name="department_id" value="' . esc_attr($id) . '">';
        wp_nonce_field('rrze-rsvp-department-' . $action . '-' . $id, 'department_nonce');
    }

    /**
     * @param string $message Safe feedback.
     * @param string $type Known notice severity.
     * @return void
     */
    private function notice(string $message, string $type): void
    {
        echo '<div class="notice notice-' . esc_attr($type) . ' inline"><p>' . esc_html($message) . '</p></div>';
    }
}
