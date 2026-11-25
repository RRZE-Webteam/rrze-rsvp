<?php

declare(strict_types=1);

namespace RRZE\RSVP\Export;

use RRZE\RSVP\Access\RoomAccess;
use WP_Error;
use function RRZE\RSVP\plugin;

defined('ABSPATH') || exit;

/** Provides a native, authenticated CSV form before WordPress emits its admin header. */
final class Admin
{
    private const PAGE = 'rrze-rsvp-export';
    private const NONCE = 'rrze-rsvp-export-bookings';
    /** @var array<string,string> Non-personal form state for the current request only. */
    private array $input = [];
    /** @var WP_Error|null Safe feedback shown instead of a failed or empty download. */
    private ?WP_Error $error = null;

    /** @return void Register the export submenu without adding a public endpoint. */
    public function onLoaded(): void
    {
        add_action('admin_menu', [$this, 'menu']);
    }

    /** @return void Bind processing to this page's pre-output load hook. */
    public function menu(): void
    {
        $hook = add_submenu_page('edit.php?post_type=booking', __('Booking export', 'rrze-rsvp'),
            __('Export', 'rrze-rsvp'), 'edit_bookings', self::PAGE, [$this, 'render']);
        if ($hook) add_action('load-' . $hook, [$this, 'load']);
    }

    /**
     * Process one POST and send bytes only after validation, authorization and serialization.
     * Failed requests stay on the form; no draft or decrypted export is persisted.
     *
     * @return void Successful downloads terminate the request.
     */
    public function load(): void
    {
        if (!current_user_can('edit_bookings')) {
            wp_die(esc_html(BookingExport::forbidden()->get_error_message()), '', ['response' => 403]);
        }
        nocache_headers();
        $this->input = BookingExport::defaults();
        wp_enqueue_style('rrze-rsvp-settings', plugins_url('build/settings.css', plugin()->getFile()), [],
            (string) filemtime(dirname(__DIR__, 2) . '/build/settings.css'));
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
        $request = wp_unslash($_POST);
        foreach ($this->input as $key => $default) {
            $this->input[$key] = is_string($request[$key] ?? null) ? $request[$key] : ($key === 'include_personal' ? '0' : '');
        }
        $result = $this->submit($request);
        if (is_wp_error($result)) {
            $this->error = $result;
            return;
        }
        if (headers_sent()) {
            $this->error = new WP_Error('booking_export_headers', __('The download could not be started. Reload this page and try again.', 'rrze-rsvp'));
            return;
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo $result['csv'];
        exit;
    }

    /**
     * Check the download boundary independently of menu visibility and prepare the whole file.
     *
     * @param array<string,mixed> $request Unslashed POST fields, including the export nonce.
     * @return array{filename:string,csv:string}|WP_Error Complete in-memory file or safe feedback.
     */
    public function submit(array $request): array|WP_Error
    {
        if (!current_user_can('edit_bookings')) return BookingExport::forbidden();
        if (!is_string($request['_wpnonce'] ?? null) || !wp_verify_nonce($request['_wpnonce'], self::NONCE)) {
            return new WP_Error('booking_export_nonce', __('The export form has expired. Reload the page and try again.', 'rrze-rsvp'), ['status' => 403]);
        }
        $document = (new BookingExport())->prepare($request);
        if (is_wp_error($document)) return $document;
        if (!$document['rows']) {
            return new WP_Error('booking_export_empty', __('No accessible room bookings were found for these filters.', 'rrze-rsvp'));
        }
        $csv = CsvWriter::write($document);
        return is_wp_error($csv) ? $csv : ['filename' => 'rsvp-bookings-' . $document['from'] . '_' . $document['to'] . '.csv', 'csv' => $csv];
    }

    /** @return void Render native filters, optional personal columns and the retention explanation. */
    public function render(): void
    {
        if (!current_user_can('edit_bookings')) {
            wp_die(esc_html(BookingExport::forbidden()->get_error_message()), '', ['response' => 403]);
        }
        $input = $this->input ?: BookingExport::defaults();
        $enabled = RoomAccess::isEnabled();
        $rooms = get_posts(['post_type' => 'room', 'post_status' => ['publish', 'private', 'draft', 'pending', 'future'],
            'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']);
        ?>
        <div class="wrap rrze-rsvp-settings rrze-rsvp-export">
            <header class="rrze-rsvp-settings-header">
                <span class="rrze-rsvp-settings-mark dashicons dashicons-download" aria-hidden="true"></span>
                <div><h1><?php echo esc_html__('Booking export', 'rrze-rsvp'); ?></h1>
                    <p><?php echo esc_html__('Download room bookings as a CSV file for your analysis.', 'rrze-rsvp'); ?></p></div>
            </header>
            <?php if ($this->error): ?>
                <div class="notice notice-<?php echo $this->error->get_error_code() === 'booking_export_empty' ? 'info' : 'error'; ?> inline" role="status"><p><?php echo esc_html($this->error->get_error_message()); ?></p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('edit.php?post_type=booking&page=' . self::PAGE)); ?>">
                <?php wp_nonce_field(self::NONCE); ?>
                <section class="rrze-rsvp-settings-card" aria-labelledby="export-filter-heading">
                    <h2 id="export-filter-heading"><?php echo esc_html__('Select bookings', 'rrze-rsvp'); ?></h2>
                    <p class="description"><?php echo esc_html__('One row per room booking, including manual bookings. Courses, drafts and trashed bookings are excluded.', 'rrze-rsvp'); ?></p>
                    <div class="rrze-rsvp-export-fields">
                        <div class="rrze-rsvp-export-wide"><label for="export-room"><?php echo esc_html__('Room', 'rrze-rsvp'); ?></label>
                            <select id="export-room" name="room_id"><option value="0"><?php echo esc_html__('All rooms', 'rrze-rsvp'); ?></option>
                                <?php foreach ($rooms as $room): if (!current_user_can('read_post', $room->ID)) continue; ?>
                                    <option value="<?php echo esc_attr((string) $room->ID); ?>" <?php selected($input['room_id'], (string) $room->ID); ?>><?php echo esc_html($room->post_title . ' (#' . $room->ID . ')'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div><label for="export-from"><?php echo esc_html__('From', 'rrze-rsvp'); ?></label>
                            <input type="date" id="export-from" name="from" min="1970-01-01" required value="<?php echo esc_attr($input['from']); ?>" aria-describedby="export-dates-help"></div>
                        <div><label for="export-to"><?php echo esc_html__('Until', 'rrze-rsvp'); ?></label>
                            <input type="date" id="export-to" name="to" min="1970-01-01" required value="<?php echo esc_attr($input['to']); ?>" aria-describedby="export-dates-help"></div>
                        <p class="description rrze-rsvp-export-wide" id="export-dates-help"><?php echo esc_html__('Includes bookings starting on either selected date and every day in between. Times match the local times in booking administration.', 'rrze-rsvp'); ?></p>
                        <div><label for="export-status"><?php echo esc_html__('Status', 'rrze-rsvp'); ?></label>
                            <select id="export-status" name="status"><option value="all"><?php echo esc_html__('All booking statuses', 'rrze-rsvp'); ?></option>
                                <?php foreach (BookingExport::statuses() as $value => $label): ?>
                                    <option value="<?php echo esc_attr($value); ?>" <?php selected($input['status'], $value); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div><label for="export-sort"><?php echo esc_html__('Sort by', 'rrze-rsvp'); ?></label>
                            <select id="export-sort" name="sort"><option value="start"><?php echo esc_html__('Booking start', 'rrze-rsvp'); ?></option>
                                <?php if ($enabled): ?><option value="department" <?php selected($input['sort'], 'department'); ?>><?php echo esc_html__('Department, then booking start', 'rrze-rsvp'); ?></option><?php endif; ?>
                            </select>
                        </div>
                    </div>
                </section>
                <section class="rrze-rsvp-settings-card" aria-labelledby="export-columns-heading">
                    <h2 id="export-columns-heading"><?php echo esc_html__('CSV contents', 'rrze-rsvp'); ?></h2>
                    <p class="description"><?php echo esc_html__('Includes booking ID, room, seat, start, end, duration in minutes and status.', 'rrze-rsvp'); ?></p>
                    <p><label><input type="checkbox" name="include_personal" value="1" <?php checked($input['include_personal'], '1'); ?>> <?php echo esc_html__('Include names and email addresses', 'rrze-rsvp'); ?></label></p>
                    <p class="description"><?php echo esc_html($enabled
                        ? __('The department column is added when an exported booking has a recorded department or a represented room has current department assignments. Values reflect the department at booking; missing assignments stay blank.', 'rrze-rsvp')
                        : __('Booking permissions are disabled. The CSV will not contain a department column.', 'rrze-rsvp')); ?></p>
                </section>
                <div class="rrze-rsvp-settings-hint"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><p class="description"><?php echo esc_html__('Only existing bookings can be exported. Room bookings are automatically deleted after four weeks; cancelled bookings may be removed earlier. Deleted bookings cannot be recovered by the export.', 'rrze-rsvp'); ?></p></div>
                <div class="rrze-rsvp-settings-actions">
                    <?php submit_button(__('Download CSV', 'rrze-rsvp'), 'primary', 'download', false); ?>
                    <span class="description"><?php echo esc_html__('Up to 10,000 bookings per file · UTF-8 · Semicolon-separated', 'rrze-rsvp'); ?></span>
                </div>
            </form>
        </div>
        <?php
    }
}
