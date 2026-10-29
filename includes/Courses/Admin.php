<?php

namespace RRZE\RSVP\Courses;

use RRZE\RSVP\Courses\Admin\Input;
use WP_Error;
use WP_Post;
use WP_Query;

defined('ABSPATH') || exit;

/** Accessible course editing and administration using the central booking service. */
final class Admin
{
    private Repository $repository;
    private BookingService $service;
    private array $forms = [];
    private array $flash = [];

    /**
     * Construct administration without registering side effects.
     *
     * @param Repository|null $repository Course reader.
     * @param BookingService|null $service Transactional writer.
     */
    public function __construct(?Repository $repository = null, ?BookingService $service = null)
    {
        $this->repository = $repository ?: new Repository();
        $this->service = $service ?: new BookingService($this->repository);
    }

    /** @return void Register editor, list-table and authenticated POST handlers. */
    public function onLoaded(): void
    {
        add_action('add_meta_boxes', [$this, 'metaboxes'], 30, 2);
        add_action('save_post_' . Model::COURSE_TYPE, [$this, 'saveSettings'], 20, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_footer', [$this, 'footerForms']);
        add_action('admin_notices', [$this, 'notices']);
        add_action('admin_post_rrze_rsvp_course_admin', [$this, 'postAction']);
        add_action('wp_ajax_rrze_rsvp_course_admin', [$this, 'ajaxAction']);
        add_filter('manage_booking_posts_columns', [$this, 'bookingColumns'], 20);
        add_action('manage_booking_posts_custom_column', [$this, 'bookingColumn'], 20, 2);
        add_filter('post_row_actions', [$this, 'rowActions'], 30, 2);
        add_action('restrict_manage_posts', [$this, 'filters'], 20);
        add_action('pre_get_posts', [$this, 'filterBookings'], 30);
    }

    /**
     * Load assets only where course controls are present.
     *
     * @return void
     */
    public function enqueue(): void
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->post_type, [Model::COURSE_TYPE, 'booking'], true)) {
            return;
        }
        $plugin = \RRZE\RSVP\plugin();
        foreach (['css', 'js'] as $extension) {
            $path = $plugin->getPath('build') . 'course-admin.' . $extension;
            $version = is_file($path) ? (string) filemtime($path) : $plugin->getVersion();
            $url = $plugin->getUrl('build') . 'course-admin.' . $extension;
            if ($extension === 'css') {
                wp_enqueue_style('rrze-rsvp-course-admin', $url, [], $version);
            } else {
                wp_enqueue_script('rrze-rsvp-course-admin', $url, [], $version, true);
            }
        }
        wp_localize_script('rrze-rsvp-course-admin', 'rsvpCourseAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'saving' => __('Saving…', 'rrze-rsvp'),
            'failed' => __('The request could not be completed. Your entered values have been kept. Please try again.', 'rrze-rsvp'),
        ]);
    }

    /**
     * Register separate course settings/date editors and read-only booking details.
     *
     * @param string $postType Current post type.
     * @param WP_Post $post Current post.
     * @return void
     */
    public function metaboxes(string $postType, $post): void
    {
        if ($postType === Model::COURSE_TYPE) {
            add_meta_box('rsvp-course-settings', __('Registration and notifications', 'rrze-rsvp'), [$this, 'settingsBox'], $postType, 'normal', 'high');
            add_meta_box('rsvp-course-dates', __('Course dates', 'rrze-rsvp'), [$this, 'datesBox'], $postType, 'normal', 'high');
        } elseif ($postType === 'booking' && $this->repository->isCourseBooking((int) $post->ID)) {
            remove_meta_box('submitdiv', 'booking', 'side');
            remove_meta_box('authordiv', 'booking', 'normal');
            remove_meta_box('rrze-rsvp-booking-details', 'booking', 'normal');
            add_meta_box('rsvp-course-booking', __('Course booking', 'rrze-rsvp'), [$this, 'bookingBox'], 'booking', 'normal', 'high');
        }
    }

    /**
     * Render course settings associated with WordPress' normal Save/Publish action.
     *
     * @param WP_Post $post Course post.
     * @return void
     */
    public function settingsBox($post): void
    {
        $course = $this->repository->getCourse((int) $post->ID);
        if (is_wp_error($course)) {
            return;
        }
        $flash = $this->getFlash((int) $post->ID);
        $settings = $flash['settings'] ?? $course['settings'];
        wp_nonce_field('rrze_rsvp_course_settings_' . $post->ID, 'rsvp_course_settings_nonce');
        echo '<div class="rsvp-course-settings">';
        echo '<p><label for="rsvp-course-access"><strong>' . esc_html__('Access', 'rrze-rsvp') . '</strong></label><br>';
        echo '<select name="rsvp_course_settings[access]" id="rsvp-course-access">';
        echo '<option value="sso_or_email"' . selected($settings['access'], 'sso_or_email', false) . '>' . esc_html__('SSO or verified email address', 'rrze-rsvp') . '</option>';
        echo '<option value="sso"' . selected($settings['access'], 'sso', false) . '>' . esc_html__('SSO required', 'rrze-rsvp') . '</option></select></p>';
        echo '<p><label><input type="checkbox" name="rsvp_course_settings[auto_confirm]" value="1"' . checked(!empty($settings['auto_confirm']), true, false) . '> <strong>' . esc_html__('Confirm participation automatically', 'rrze-rsvp') . '</strong></label>';
        echo '<br><span class="description">' . esc_html__('Otherwise, responsible staff approve each verified registration. Pending registrations already reserve a place.', 'rrze-rsvp') . '</span></p>';
        echo '<p><label for="rsvp-course-retention"><strong>' . esc_html__('Keep bookings after the course date (days)', 'rrze-rsvp') . '</strong></label><br>';
        echo '<input type="number" min="1" max="36500" step="1" id="rsvp-course-retention" name="rsvp_course_settings[retention_days]" value="' . esc_attr($settings['retention_days']) . '" class="small-text">';
        echo '<br><span class="description">' . esc_html__('Includes cancellations. After this period, participant information and action links are deleted automatically.', 'rrze-rsvp') . '</span></p>';
        echo '<h3>' . esc_html__('Notifications', 'rrze-rsvp') . '</h3><p class="description">' . esc_html__('Participants receive verification, approval and cancellation messages as needed. Sender and staff notifications use the existing RSVP email settings.', 'rrze-rsvp') . '</p>';
        echo '<p class="description">' . esc_html__('Save these settings with the course. Changes to access and confirmation apply to new registrations.', 'rrze-rsvp') . '</p></div>';
    }

    /**
     * Save validated course settings and preserve rejected values for correction.
     *
     * @param int $postId Course post ID.
     * @param WP_Post $post Course post.
     * @return void
     */
    public function saveSettings(int $postId, $post): void
    {
        if (BookingService::isWriting() || wp_is_post_revision($postId) || wp_is_post_autosave($postId)
            || !isset($_POST['rsvp_course_settings_nonce']) || !current_user_can('edit_post', $postId)
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['rsvp_course_settings_nonce'])), 'rrze_rsvp_course_settings_' . $postId)) {
            return;
        }
        $raw = isset($_POST['rsvp_course_settings']) && is_array($_POST['rsvp_course_settings']) ? wp_unslash($_POST['rsvp_course_settings']) : [];
        $settings = [
            'access' => sanitize_key(is_scalar($raw['access'] ?? '') ? (string) ($raw['access'] ?? '') : ''),
            'auto_confirm' => !empty($raw['auto_confirm']),
            'retention_days' => is_scalar($raw['retention_days'] ?? '') ? (string) ($raw['retention_days'] ?? '') : '',
        ];
        $result = $this->service->saveCourseSettings($postId, $settings);
        if (is_wp_error($result)) {
            $this->setFlash($postId, ['error' => $result->get_error_message(), 'settings' => $settings]);
        }
    }

    /**
     * Render stable occurrence rows and a separate new-date row.
     *
     * @param WP_Post $post Course post.
     * @return void
     */
    public function datesBox($post): void
    {
        if ($post->post_status === 'auto-draft') {
            echo '<p>' . esc_html__('Save the course as a draft before adding its dates.', 'rrze-rsvp') . '</p>';
            return;
        }
        echo '<p>' . esc_html__('Each date is booked separately. Dates are saved with their own button; the course title and description use the usual Save button.', 'rrze-rsvp') . '</p>';
        echo '<p class="description">' . esc_html(sprintf(__('Times are entered in the website timezone: %s.', 'rrze-rsvp'), wp_timezone_string())) . '</p>';
        echo '<div class="rsvp-course-date-list">';
        foreach ($this->repository->getOccurrences((int) $post->ID) as $date) {
            echo $this->dateRow((int) $post->ID, $date);
        }
        echo $this->dateRow((int) $post->ID);
        echo '</div>';
    }

    /**
     * Render one occurrence editor, preserving its ID independently of row position.
     *
     * @param int $courseId Parent course.
     * @param array|null $date Existing date, or null for a new row.
     * @param array $entered Rejected input retained by the non-JavaScript fallback.
     * @return string Escaped row markup.
     */
    private function dateRow(int $courseId, ?array $date = null, array $entered = []): string
    {
        $id = (int) ($date['id'] ?? 0);
        $state = $date['state'] ?? 'open';
        $availability = $id ? $this->service->availability($id) : [];
        $locked = !is_wp_error($availability) && !empty($availability['held']);
        $flash = $this->getFlash($courseId);
        if (($flash['target_id'] ?? -1) === $id && isset($flash['entered'])) {
            $entered = $flash['entered'];
        }
        $values = ['start' => '', 'end' => '', 'registration_start' => '', 'registration_end' => '', 'capacity' => 20, 'room_id' => 0, 'location' => ''];
        if ($date) {
            foreach (['start', 'end', 'registration_start', 'registration_end'] as $key) {
                $values[$key] = $date[$key] ? wp_date('Y-m-d\TH:i', $date[$key]) : '';
            }
            $values['capacity'] = $date['capacity'];
            $values['room_id'] = $date['room_id'];
            $values['location'] = $date['room_id'] ? '' : $date['location'];
        }
        $values = array_merge($values, $entered);
        $form = $this->queueForm('save_date', $courseId, $id);
        $prefix = 'rsvp-course-date-' . ($id ?: 'new');
        ob_start();
        echo '<section class="rsvp-course-date" id="' . esc_attr($prefix) . '" data-course-date="' . esc_attr($id) . '">';
        echo '<h3>' . esc_html($id ? wp_date(get_option('date_format') . ', ' . get_option('time_format'), $date['start']) : __('Add course date', 'rrze-rsvp')) . '</h3>';
        if ($id && !is_wp_error($availability)) {
            echo '<p class="rsvp-course-counts">' . esc_html(sprintf(__('%1$d confirmed · %2$d pending · %3$d free', 'rrze-rsvp'), $availability['confirmed'], $availability['pending'], $availability['free'])) . '</p>';
        }
        if ($state === 'cancelled') {
            echo '<p><strong>' . esc_html__('This course date has been cancelled.', 'rrze-rsvp') . '</strong></p>';
            echo '<p>' . esc_html($date['location']) . '</p>';
            echo '<p><a class="button" href="' . esc_url(add_query_arg(['post_type' => 'booking', 'rsvp_course_id' => $courseId, 'rsvp_occurrence_id' => $id], admin_url('edit.php'))) . '">' . esc_html__('View registrations', 'rrze-rsvp') . '</a>';
            if (!$this->repository->hasBookings($id)) {
                $delete = $this->queueForm('delete_date', $courseId, $id);
                echo ' <button type="submit" form="' . esc_attr($delete) . '" class="button-link-delete" data-course-confirm="' . esc_attr__('Move this unused date to the Trash?', 'rrze-rsvp') . '">' . esc_html__('Remove date', 'rrze-rsvp') . '</button>';
            }
            echo '</p><div class="rsvp-course-feedback" role="status" aria-live="polite"></div></section>';
            return (string) ob_get_clean();
        }
        if ($locked) {
            echo '<p class="description">' . esc_html__('Active registrations protect the date, time and location. Capacity cannot be reduced below the number of reserved places.', 'rrze-rsvp') . '</p>';
        }
        echo '<div class="rsvp-course-fields">';
        $this->dateInput($prefix, $form, 'start', __('Start', 'rrze-rsvp'), $values['start'], false, $locked);
        $this->dateInput($prefix, $form, 'end', __('End', 'rrze-rsvp'), $values['end'], false, $locked);
        echo '<p><label for="' . esc_attr($prefix . '-capacity') . '">' . esc_html__('Maximum participants', 'rrze-rsvp') . '</label>';
        echo '<input type="number" min="' . esc_attr($locked ? max(1, (int) $availability['held']) : 1) . '" step="1" required id="' . esc_attr($prefix . '-capacity') . '" name="occurrence[capacity]" form="' . esc_attr($form) . '" value="' . esc_attr($values['capacity']) . '"></p>';
        echo '<p><label for="' . esc_attr($prefix . '-room_id') . '">' . esc_html__('Room', 'rrze-rsvp') . '</label>';
        echo '<select id="' . esc_attr($prefix . '-room_id') . '" name="occurrence[room_id]" form="' . esc_attr($form) . '"' . disabled($locked, true, false) . '>';
        echo '<option value="0">' . esc_html__('Enter a different location', 'rrze-rsvp') . '</option>';
        foreach (get_posts(['post_type' => 'room', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']) as $room) {
            echo '<option value="' . esc_attr($room->ID) . '"' . selected((int) $values['room_id'], (int) $room->ID, false) . '>' . esc_html($room->post_title) . '</option>';
        }
        echo '</select>';
        if ($locked) {
            echo '<input type="hidden" name="occurrence[room_id]" form="' . esc_attr($form) . '" value="' . esc_attr($values['room_id']) . '">';
        }
        echo '</p>';
        echo '<p class="rsvp-course-location"><label for="' . esc_attr($prefix . '-location') . '">' . esc_html__('Location when no room is selected', 'rrze-rsvp') . '</label>';
        echo '<input type="text" id="' . esc_attr($prefix . '-location') . '" name="occurrence[location]" form="' . esc_attr($form) . '" value="' . esc_attr($values['location']) . '"' . ($locked ? ' readonly' : '') . '></p>';
        $this->dateInput($prefix, $form, 'registration_start', __('Registration opens (optional)', 'rrze-rsvp'), $values['registration_start'], true);
        $this->dateInput($prefix, $form, 'registration_end', __('Registration closes (optional)', 'rrze-rsvp'), $values['registration_end'], true);
        echo '</div><p class="description">' . esc_html__('Without a registration period, registration opens when the course is published and closes when the date begins.', 'rrze-rsvp') . '</p>';
        echo '<div class="rsvp-course-feedback" role="status" aria-live="polite"></div><p class="rsvp-course-row-actions">';
        echo '<button type="submit" form="' . esc_attr($form) . '" class="button button-primary">' . esc_html($id ? __('Save date', 'rrze-rsvp') : __('Add date', 'rrze-rsvp')) . '</button>';
        if ($id) {
            if ($date['end'] > time()) {
                $cancel = $this->queueForm('cancel_date', $courseId, $id);
                echo ' <button type="submit" form="' . esc_attr($cancel) . '" class="button" data-course-confirm="' . esc_attr__('Cancel this date and all its active registrations?', 'rrze-rsvp') . '">' . esc_html__('Cancel date', 'rrze-rsvp') . '</button>';
            }
            if (!$this->repository->hasBookings($id)) {
                $delete = $this->queueForm('delete_date', $courseId, $id);
                echo ' <button type="submit" form="' . esc_attr($delete) . '" class="button-link-delete" data-course-confirm="' . esc_attr__('Move this unused date to the Trash?', 'rrze-rsvp') . '">' . esc_html__('Remove date', 'rrze-rsvp') . '</button>';
            }
            echo ' <a class="button" href="' . esc_url(add_query_arg(['post_type' => 'booking', 'rsvp_course_id' => $courseId, 'rsvp_occurrence_id' => $id], admin_url('edit.php'))) . '">' . esc_html__('View registrations', 'rrze-rsvp') . '</a>';
        }
        echo '</p></section>';
        return (string) ob_get_clean();
    }

    /**
     * Render a labelled datetime-local control associated with an external form.
     *
     * @param string $prefix Unique row ID prefix.
     * @param string $form External form ID.
     * @param string $name Service field name.
     * @param string $label Translated label.
     * @param string $value Display value.
     * @param bool $optional Whether an empty value is allowed.
     * @param bool $locked Whether this value is protected by active registrations.
     * @return void
     */
    private function dateInput(string $prefix, string $form, string $name, string $label, string $value, bool $optional, bool $locked = false): void
    {
        echo '<p><label for="' . esc_attr($prefix . '-' . $name) . '">' . esc_html($label) . '</label>';
        echo '<input type="datetime-local" id="' . esc_attr($prefix . '-' . $name) . '" name="occurrence[' . esc_attr($name) . ']" form="' . esc_attr($form) . '" value="' . esc_attr($value) . '"' . ($optional ? '' : ' required') . ($locked ? ' readonly' : '') . '></p>';
    }

    /**
     * Queue a form that will be emitted outside WordPress' surrounding editor form.
     *
     * @param string $operation Allowed administrative operation.
     * @param int $courseId Course context.
     * @param int $targetId Date or booking ID.
     * @return string Unique form ID.
     */
    private function queueForm(string $operation, int $courseId, int $targetId): string
    {
        $id = 'rsvp-course-action-' . $operation . '-' . $courseId . '-' . $targetId;
        $this->forms[$id] = ['operation' => $operation, 'course_id' => $courseId, 'target_id' => $targetId];
        return $id;
    }

    /** @return string Complete form markup for queued controls. */
    private function formsHtml(): string
    {
        $html = '';
        foreach ($this->forms as $id => $fields) {
            $html .= '<form id="' . esc_attr($id) . '" class="rsvp-course-action-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-operation="' . esc_attr($fields['operation']) . '" data-target="' . esc_attr($fields['target_id']) . '">';
            $fields['action'] = 'rrze_rsvp_course_admin';
            $fields['_rsvp_nonce'] = wp_create_nonce($this->nonceAction($fields));
            foreach ($fields as $name => $value) {
                $html .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '">';
            }
            $html .= '</form>';
        }
        return $html;
    }

    /** @return void Emit external forms after the main editor/list form has closed. */
    public function footerForms(): void
    {
        echo $this->formsHtml();
    }

    /**
     * Build an operation-specific nonce action.
     *
     * @param array $data Operation, course ID and target ID.
     * @return string Nonce action.
     */
    private function nonceAction(array $data): string
    {
        return 'rsvp_course_admin_' . $data['operation'] . '_' . (int) $data['course_id'] . '_' . (int) $data['target_id'];
    }

    /**
     * Authorize an operation and execute it through the shared service.
     *
     * @param array $request Unslashed request payload.
     * @return array|WP_Error Service result and the validated operation context.
     */
    public function execute(array $request)
    {
        $operation = sanitize_key(is_scalar($request['operation'] ?? '') ? (string) ($request['operation'] ?? '') : '');
        $courseId = absint(is_scalar($request['course_id'] ?? 0) ? $request['course_id'] ?? 0 : 0);
        $targetId = absint(is_scalar($request['target_id'] ?? 0) ? $request['target_id'] ?? 0 : 0);
        $context = ['operation' => $operation, 'course_id' => $courseId, 'target_id' => $targetId];
        $allowed = ['save_date', 'cancel_date', 'delete_date', 'approve_booking', 'reject_booking', 'cancel_booking', 'resend_booking'];
        if (!in_array($operation, $allowed, true) || !wp_verify_nonce(is_scalar($request['_rsvp_nonce'] ?? '') ? (string) ($request['_rsvp_nonce'] ?? '') : '', $this->nonceAction($context))) {
            return new WP_Error('course_admin_nonce', __('This action has expired. Reload the page and try again.', 'rrze-rsvp'), ['status' => 403]);
        }
        $isBooking = str_ends_with($operation, '_booking');
        if (!current_user_can('edit_post', $isBooking ? $targetId : $courseId)) {
            return new WP_Error('course_admin_forbidden', __('You are not allowed to manage this item.', 'rrze-rsvp'), ['status' => 403]);
        }
        if ($isBooking) {
            $booking = $this->repository->getBooking($targetId);
            if (is_wp_error($booking) || $booking['course_id'] !== $courseId) {
                return new WP_Error('course_admin_target', __('This booking does not belong to the selected course.', 'rrze-rsvp'), ['status' => 404]);
            }
            switch ($operation) {
                case 'approve_booking':
                    $result = $this->service->approve($targetId);
                    break;
                case 'reject_booking':
                    $result = $this->service->cancel($targetId, 'rejected');
                    break;
                case 'cancel_booking':
                    $result = $this->service->cancel($targetId);
                    break;
                default:
                    $result = $this->service->resend($targetId);
            }
        } else {
            if ($targetId) {
                $date = $this->repository->getOccurrence($targetId);
                if (is_wp_error($date) || $date['course_id'] !== $courseId) {
                    return new WP_Error('course_admin_target', __('This date does not belong to the selected course.', 'rrze-rsvp'), ['status' => 404]);
                }
            }
            if ($operation === 'save_date') {
                $input = isset($request['occurrence']) && is_array($request['occurrence']) ? $request['occurrence'] : [];
                $values = Input::occurrence($input, wp_timezone());
                $result = is_wp_error($values) ? $values : $this->service->saveOccurrence($courseId, $values, $targetId);
            } elseif ($operation === 'cancel_date') {
                $result = $this->service->cancelOccurrence($targetId);
            } else {
                $result = $this->service->deleteOccurrence($targetId);
            }
        }
        if (is_wp_error($result)) {
            return $result;
        }
        return ['context' => $context, 'result' => $result, 'booking' => $isBooking];
    }

    /** @return void Execute an authenticated AJAX POST while preserving editor state. */
    public function ajaxAction(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_send_json_error(['message' => __('Use POST for this action.', 'rrze-rsvp')], 405);
        }
        $request = wp_unslash($_POST);
        $executed = $this->execute($request);
        if (is_wp_error($executed)) {
            $data = (array) $executed->get_error_data();
            wp_send_json_error(['message' => $executed->get_error_message(), 'field' => $data['field'] ?? ''], $data['status'] ?? 400);
        }
        $context = $executed['context'];
        $response = ['message' => __('Saved.', 'rrze-rsvp'), 'operation' => $context['operation'], 'target_id' => $context['target_id']];
        if ($executed['booking']) {
            $booking = $this->repository->getBooking($context['target_id']);
            $response['status_html'] = is_wp_error($booking) ? '' : $this->bookingStatus($booking);
        } elseif ($context['operation'] !== 'delete_date') {
            $id = $context['operation'] === 'save_date' ? (int) $executed['result']['id'] : $context['target_id'];
            $date = $this->repository->getOccurrence($id);
            $response['row_html'] = is_wp_error($date) ? '' : $this->dateRow($context['course_id'], $date);
            if (!$context['target_id']) {
                $response['new_html'] = $this->dateRow($context['course_id']);
            }
        }
        $response['forms_html'] = $this->formsHtml();
        wp_send_json_success($response);
    }

    /** @return void Support the same operations without JavaScript using POST/redirect/GET. */
    public function postAction(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('Use POST for this action.', 'rrze-rsvp'), '', ['response' => 405]);
        }
        $request = wp_unslash($_POST);
        $executed = $this->execute($request);
        $courseId = absint(is_scalar($request['course_id'] ?? 0) ? $request['course_id'] ?? 0 : 0);
        $targetId = absint(is_scalar($request['target_id'] ?? 0) ? $request['target_id'] ?? 0 : 0);
        $isBooking = str_ends_with(sanitize_key(is_scalar($request['operation'] ?? '') ? (string) ($request['operation'] ?? '') : ''), '_booking');
        if (is_wp_error($executed)) {
            $status = ((array) $executed->get_error_data())['status'] ?? 400;
            if (in_array($status, [403, 404], true)) {
                wp_die(esc_html($executed->get_error_message()), '', ['response' => $status]);
            }
            $flash = ['error' => $executed->get_error_message(), 'target_id' => $targetId];
            if (isset($request['occurrence']) && is_array($request['occurrence'])) {
                $flash['entered'] = array_map('sanitize_text_field', $request['occurrence']);
            }
            $this->setFlash($isBooking ? $targetId : $courseId, $flash);
        } else {
            $this->setFlash($isBooking ? $targetId : $courseId, ['success' => __('Saved.', 'rrze-rsvp')]);
        }
        wp_safe_redirect(get_edit_post_link($isBooking ? $targetId : $courseId, 'raw') . ($isBooking ? '' : '#rsvp-course-dates'));
        exit;
    }

    /**
     * Clarify the mixed room/course booking table column labels.
     *
     * @param array $columns Existing columns.
     * @return array Updated labels.
     */
    public function bookingColumns(array $columns): array
    {
        if (isset($columns['room'])) {
            $columns['room'] = __('Room / course', 'rrze-rsvp');
        }
        if (isset($columns['seat'])) {
            $columns['seat'] = __('Seat / location', 'rrze-rsvp');
        }
        return $columns;
    }

    /**
     * Render only course rows in the shared booking table.
     *
     * @param string $column Column key.
     * @param int $postId Booking ID.
     * @return void
     */
    public function bookingColumn(string $column, int $postId): void
    {
        if (!$this->repository->isCourseBooking($postId)) {
            return;
        }
        $booking = $this->repository->getBooking($postId);
        if (is_wp_error($booking)) {
            return;
        }
        switch ($column) {
            case 'bookingdate':
                echo esc_html(wp_date(get_option('date_format'), $booking['start']));
                break;
            case 'bookingstart':
                echo esc_html(wp_date(get_option('time_format'), $booking['start']) . ' – ' . wp_date(get_option('time_format'), $booking['end']));
                break;
            case 'room':
                echo '<a href="' . esc_url(get_edit_post_link($booking['course_id'])) . '">' . esc_html($booking['course_title']) . '</a>';
                break;
            case 'seat':
                echo esc_html($booking['location']);
                break;
            case 'name':
                echo '<a href="' . esc_url(get_edit_post_link($postId)) . '">' . esc_html($booking['guest_firstname'] . ' ' . $booking['guest_lastname']) . '</a>';
                break;
            case 'email':
                echo esc_html($booking['guest_email']);
                break;
            case 'status':
                echo '<div data-course-booking-status="' . esc_attr($postId) . '">' . $this->bookingStatus($booking) . '</div>';
                break;
            case 'phone':
                echo '—';
        }
    }

    /**
     * Render current status and capability-checked POST actions.
     *
     * @param array $booking Private booking DTO.
     * @return string Escaped status markup.
     */
    private function bookingStatus(array $booking): string
    {
        $expired = $booking['status'] === 'booked' && $booking['expires_at'] <= time();
        $labels = ['booked' => __('Waiting for email verification', 'rrze-rsvp'), 'customer-confirmed' => __('Awaiting approval', 'rrze-rsvp'), 'confirmed' => __('Confirmed', 'rrze-rsvp'), 'cancelled' => __('Cancelled', 'rrze-rsvp')];
        $html = '<strong>' . esc_html($expired ? __('Expired', 'rrze-rsvp') : ($labels[$booking['status']] ?? $booking['status'])) . '</strong>';
        if ($booking['cancel_reason']) {
            $reasons = ['rejected' => __('Registration rejected', 'rrze-rsvp'), 'customer' => __('Cancelled by participant', 'rrze-rsvp'), 'admin' => __('Cancelled by staff', 'rrze-rsvp'), 'expired' => __('Email verification expired', 'rrze-rsvp'), 'occurrence_cancelled' => __('Course date cancelled', 'rrze-rsvp')];
            $html .= '<br><span>' . esc_html($reasons[$booking['cancel_reason']] ?? __('Course date cancelled', 'rrze-rsvp')) . '</span>';
        }
        $mailError = get_post_meta($booking['id'], 'rrze-rsvp-course-mail-error', true);
        if ($mailError) {
            $html .= '<p class="rsvp-course-error">' . esc_html((string) $mailError) . '</p>';
        }
        if (current_user_can('edit_post', $booking['id'])) {
            $buttons = [];
            if (!$expired && $booking['status'] === 'customer-confirmed' && time() < $booking['start']) {
                $buttons['approve_booking'] = __('Approve registration', 'rrze-rsvp');
                $buttons['reject_booking'] = __('Reject registration', 'rrze-rsvp');
            }
            if (!$expired && in_array($booking['status'], Model::ACTIVE_STATUSES, true) && time() < $booking['end']) {
                $buttons['cancel_booking'] = __('Cancel registration', 'rrze-rsvp');
            }
            if (!$expired && ($booking['status'] === 'cancelled' || $booking['end'] > time())) {
                $buttons['resend_booking'] = __('Resend email', 'rrze-rsvp');
            }
            $html .= '<p class="rsvp-course-booking-actions">';
            foreach ($buttons as $operation => $label) {
                $form = $this->queueForm($operation, $booking['course_id'], $booking['id']);
                $confirm = in_array($operation, ['cancel_booking', 'reject_booking'], true) ? ' data-course-confirm="' . esc_attr__('Cancel this registration and release its place?', 'rrze-rsvp') . '"' : '';
                $html .= '<button type="submit" class="button button-small" form="' . esc_attr($form) . '"' . $confirm . '>' . esc_html($label) . '</button> ';
            }
            $html .= '</p>';
        }
        return $html . '<div class="rsvp-course-feedback" role="status" aria-live="polite"></div>';
    }

    /**
     * Show course booking information without a native post-update route.
     *
     * @param WP_Post $post Booking post.
     * @return void
     */
    public function bookingBox($post): void
    {
        $booking = $this->repository->getBooking((int) $post->ID);
        if (is_wp_error($booking)) {
            return;
        }
        $rows = [
            __('Course', 'rrze-rsvp') => $booking['course_title'],
            __('Date', 'rrze-rsvp') => wp_date(get_option('date_format') . ' ' . get_option('time_format'), $booking['start']) . ' – ' . wp_date(get_option('date_format') . ' ' . get_option('time_format'), $booking['end']),
            __('Location', 'rrze-rsvp') => $booking['location'],
            __('Name', 'rrze-rsvp') => $booking['guest_firstname'] . ' ' . $booking['guest_lastname'],
            __('Email', 'rrze-rsvp') => $booking['guest_email'],
            __('Notes', 'rrze-rsvp') => $booking['notes'],
        ];
        echo '<dl class="rsvp-course-booking-details">';
        foreach ($rows as $label => $value) {
            echo '<dt>' . esc_html($label) . '</dt><dd>' . nl2br(esc_html($value)) . '</dd>';
        }
        echo '</dl><div data-course-booking-status="' . esc_attr($booking['id']) . '">' . $this->bookingStatus($booking) . '</div>';
        echo '<p><a href="' . esc_url(add_query_arg(['post_type' => 'booking', 'rsvp_course_id' => $booking['course_id'], 'rsvp_occurrence_id' => $booking['occurrence_id']], admin_url('edit.php'))) . '">' . esc_html__('Back to registrations for this date', 'rrze-rsvp') . '</a></p>';
    }

    /**
     * Offer course-booking details and protect retained parent rows from deletion.
     *
     * @param array $actions Existing row actions.
     * @param WP_Post $post Current row.
     * @return array Appropriate actions.
     */
    public function rowActions(array $actions, $post): array
    {
        if ($post->post_type === 'booking' && $this->repository->isCourseBooking((int) $post->ID)) {
            return current_user_can('edit_post', $post->ID) ? ['view' => '<a href="' . esc_url(get_edit_post_link($post->ID)) . '">' . esc_html__('View registration', 'rrze-rsvp') . '</a>'] : [];
        }
        if ($post->post_type === Model::COURSE_TYPE && $this->repository->hasBookings((int) $post->ID)) {
            unset($actions['trash'], $actions['delete']);
        }
        return $actions;
    }

    /**
     * Render course and course-date filters alongside the existing booking filters.
     *
     * @param string $postType Current list-table post type.
     * @return void
     */
    public function filters(string $postType): void
    {
        if ($postType !== 'booking') {
            return;
        }
        $courseId = absint($_GET['rsvp_course_id'] ?? 0);
        $dateId = absint($_GET['rsvp_occurrence_id'] ?? 0);
        echo '<label class="screen-reader-text" for="rsvp-course-filter">' . esc_html__('Filter by course', 'rrze-rsvp') . '</label>';
        echo '<select name="rsvp_course_id" id="rsvp-course-filter"><option value="0">' . esc_html__('All courses and rooms', 'rrze-rsvp') . '</option>';
        foreach (get_posts(['post_type' => Model::COURSE_TYPE, 'post_status' => ['publish', 'draft', 'private', 'pending', 'future'], 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']) as $course) {
            echo '<option value="' . esc_attr($course->ID) . '"' . selected($courseId, (int) $course->ID, false) . '>' . esc_html($course->post_title) . '</option>';
        }
        echo '</select>';
        if ($courseId) {
            echo '<label class="screen-reader-text" for="rsvp-course-date-filter">' . esc_html__('Filter by course date', 'rrze-rsvp') . '</label>';
            echo '<select name="rsvp_occurrence_id" id="rsvp-course-date-filter"><option value="0">' . esc_html__('All course dates', 'rrze-rsvp') . '</option>';
            foreach ($this->repository->getOccurrences($courseId) as $date) {
                echo '<option value="' . esc_attr($date['id']) . '"' . selected($dateId, $date['id'], false) . '>' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $date['start'])) . '</option>';
            }
            echo '</select>';
        }
    }

    /**
     * Combine course/date selectors with all existing list-table constraints.
     *
     * @param WP_Query $query Current WordPress query.
     * @return void
     */
    public function filterBookings($query): void
    {
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'booking') {
            return;
        }
        $clauses = [];
        foreach (['rsvp_course_id' => Model::BOOKING_COURSE_META, 'rsvp_occurrence_id' => Model::BOOKING_DATE_META] as $parameter => $metaKey) {
            $id = absint($_GET[$parameter] ?? 0);
            if ($id) {
                $clauses[] = ['key' => $metaKey, 'value' => $id, 'compare' => '=', 'type' => 'NUMERIC'];
            }
        }
        if (!$clauses) {
            return;
        }
        $existing = $query->get('meta_query');
        if ($existing) {
            $clauses[] = $existing;
        }
        $clauses['relation'] = 'AND';
        $query->set('meta_query', $clauses);
    }

    /** @return void Render operation feedback and unsupported-storage guidance. */
    public function notices(): void
    {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->post_type, [Model::COURSE_TYPE, 'booking'], true)) {
            return;
        }
        $id = absint($_GET['post'] ?? 0);
        $flash = $id ? $this->getFlash($id) : [];
        foreach (['error', 'success'] as $kind) {
            if (!empty($flash[$kind])) {
                echo '<div class="notice notice-' . esc_attr($kind) . '"><p>' . esc_html($flash[$kind]) . '</p></div>';
            }
        }
        if ($screen->post_type === Model::COURSE_TYPE && !$this->service->supportsTransactions()) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Course booking is unavailable because the database does not support the required transactions. Existing room reservations remain available.', 'rrze-rsvp') . '</p></div>';
        }
    }

    /**
     * Read one user/site/post-specific notice while keeping it available during rendering.
     *
     * @param int $postId Relevant course or booking.
     * @return array Flash values.
     */
    private function getFlash(int $postId): array
    {
        if (!array_key_exists($postId, $this->flash)) {
            $key = 'rsvp_course_admin_' . get_current_user_id() . '_' . $postId;
            $this->flash[$postId] = (array) get_transient($key);
            delete_transient($key);
        }
        return $this->flash[$postId];
    }

    /**
     * Preserve validation feedback across a non-JavaScript redirect.
     *
     * @param int $postId Relevant course or booking.
     * @param array $data Notice and optional entered values.
     * @return void
     */
    private function setFlash(int $postId, array $data): void
    {
        set_transient('rsvp_course_admin_' . get_current_user_id() . '_' . $postId, $data, 5 * MINUTE_IN_SECONDS);
    }
}
