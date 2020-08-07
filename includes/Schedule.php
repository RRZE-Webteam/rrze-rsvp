<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use WP_Query;

class Schedule
{
    protected $email;

    public function __construct()
    {
        $this->email = new Email;
    }

    public function onLoaded()
    {
        add_action('wp', [$this, 'activateSchedule']);
        add_action('rrze_rsvp_hourly_event', [$this, 'hourlyEvent']);
        add_action('rrze_rsvp_daily_event', [$this, 'dailyEvent']);
    }

    public function activateSchedule()
    {
        if (!wp_next_scheduled('rrze_rsvp_hourly_event')) {
            wp_schedule_event(time(), 'hourly', 'rrze_rsvp_hourly_event');
        }
        if (!wp_next_scheduled('rrze_rsvp_daily_event')) {
            wp_schedule_event(time(), 'daily', 'rrze_rsvp_daily_event');
        }
    }

    public function hourlyEvent()
    {
        $this->cancelUnconfirmedBookings();
    }

    public function dailyEvent()
    {
        $this->deleteOldBookings();
    }

    protected function deleteOldBookings()
    {
        $args = [
            'fields'            => 'ids',
            'post_type'         => ['booking'],
            'post_status'       => 'publish',
            'posts_per_page'    => '-1',
            'meta_query'        => [
                [
                    'key'       => 'rrze-rsvp-booking-start',
                    'value'     => current_time('timestamp') - (4 * WEEK_IN_SECONDS),
                    'compare'   => '<'
                ]
            ]
        ];

        $query = new WP_Query($args);

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                wp_delete_post(get_the_ID(), true);
            }
            wp_reset_postdata();
        }
    }

    protected function cancelUnconfirmedBookings()
    {
        $args = [
            'fields'            => 'ids',
            'post_type'         => ['booking'],
            'post_status'       => 'publish',
            'posts_per_page'    => '-1',
            'meta_query' => [
                'relation' => 'AND',
                'user_status_clause' => [
                    'key' => 'rrze-rsvp-customer-status',
                    'value' => 'confirmed',
                    'compare' => '!='
                ],
                'status_clause' => [
                    'key' => 'rrze-rsvp-booking-status',
                    'value' => 'booked',
                    'compare' => '='
                ],
                'status_start' => [
                    'key'       => 'rrze-rsvp-booking-start',
                    'value'     => current_time('timestamp') - (1 * HOUR_IN_SECONDS),
                    'compare'   => '<'
                ]
            ]
        ];

        $query = new WP_Query($args);

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $roomId = get_post_meta(get_the_ID(), 'rrze-rsvp-seat-room', true);
                if (get_post_meta($roomId, 'rrze-rsvp-room-force-to-confirm', true)) {
                    update_post_meta(get_the_ID(), 'rrze-rsvp-booking-status', 'cancelled');
                    $this->email->bookingCancelledCustomer(get_the_ID());
                }
            }
            wp_reset_postdata();
        }
    }
}
