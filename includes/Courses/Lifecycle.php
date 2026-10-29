<?php

namespace RRZE\RSVP\Courses;

use RRZE\RSVP\Schedule;

defined('ABSPATH') || exit;

/** Course-specific expiry and retention, isolated from the legacy room cleanup. */
final class Lifecycle
{
    private BookingService $service;

    /**
     * @param BookingService|null $service Optional service for maintenance tests.
     */
    public function __construct(?BookingService $service = null)
    {
        $this->service = $service ?? new BookingService();
    }

    /** @return void Register shared schedules and course-only maintenance callbacks. */
    public function onLoaded(): void
    {
        add_action('init', [$this, 'ensureSchedules'], 25);
        add_action('rrze_rsvp_every5minutes_event', [$this, 'expire'], 20);
        add_action('rrze_rsvp_daily_event', [$this, 'purge'], 20);
    }

    /** @return void Also create schedules when a site has only received admin visits. */
    public function ensureSchedules(): void
    {
        (new Schedule())->activateScheduledEvents();
    }

    /** @return void Expire only pending email-verification holds through the service. */
    public function expire(): void
    {
        $this->recordResult('expiry', $this->service->expireDue());
    }

    /** @return void Remove retained course data only after its own course deadline. */
    public function purge(): void
    {
        $this->recordResult('retention', $this->service->purgeExpired());
    }

    /**
     * Keep a diagnostic without putting participant information in logs.
     *
     * @param string $operation Maintenance operation.
     * @param array|\WP_Error $result Service result.
     * @return void
     */
    private function recordResult(string $operation, $result): void
    {
        $key = 'rrze_rsvp_course_' . $operation . '_error';
        if (is_wp_error($result)) {
            update_option($key, $result->get_error_code(), false);
        } else {
            delete_option($key);
        }
    }
}
