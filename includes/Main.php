<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use RRZE\RSVP\CPT\CPT;
use RRZE\RSVP\Shortcodes\{Availability, Bookings, QR};
use RRZE\RSVP\Printing\Printing;
use RRZE\RSVP\Auth\{Auth, IdM, LDAP};

use function RRZE\RSVP\Config\getOptionName;


/**
 * [Main description]
 */
class Main
{
    protected $pluginFile;

    /**
     * [__construct description]
     */
    public function __construct($pluginFile)
    {
        $this->pluginFile = $pluginFile;
    }

    /**
     * Register the legacy room functionality and the independent course module.
     *
     * @return void
     */
    public function onLoaded()
    {
        // Settings
        $settings = new Settings($this->pluginFile);
        $settings->onLoaded();

        // Posttypes 
        $cpt = new CPT;
        $cpt->onLoaded();

        // CMB2
        $metaboxes = new Metaboxes;
        $metaboxes->onLoaded();

        // Tracking
        if (CORONA_MODE) {
            $tracking = new Tracking;
            $tracking->onLoaded();
        }

        // Shortcodes
        new Bookings($settings);
        new Availability($settings);
        new QR();

        $printing = new Printing;
        $printing->onLoaded();

        $schedule = new Schedule;
        $schedule->onLoaded();

        $occupancy = new Occupancy;
        $occupancy->onLoaded();

        $tools = new Tools;
        $tools->onLoaded();

        $virtualPage = new VirtualPage(__('Booking', 'rrze-rsvp'), 'rsvp-booking');
        $virtualPage->onLoaded();

        $actions = new Actions;
        $actions->onLoaded();

        (new Courses\Module())->onLoaded();

        // Enqueue scripts
        add_action('admin_enqueue_scripts', [$this, 'adminEnqueueScripts']);
        add_action('wp_enqueue_scripts', [$this, 'wpEnqueueScripts']);

        // template redirects
        add_action('template_redirect', [$this, 'maybeAuthenticate']);
        add_action('template_redirect', [$this, 'includeSingleTemplate'], 99);

        // Reset settings to default
        add_action('update_option_rrze_rsvp', [$this, 'resetSettings']);

        // RRZE Cache Plugin: Skip Cache
        add_filter('rrzecache_skip_cache', [$this, 'skipCache']);
    }

    /**
     * Select and render the plugin template for the current front-end request.
     *
     * This callback runs late on `template_redirect`, after WordPress has
     * resolved the queried post. Templates are selected in this order:
     *
     * 1. Authentication page for a valid `require-auth` request when neither
     *    IdM nor LDAP reports an authenticated user.
     * 2. Booking form for a valid RSVP availability request.
     * 3. Single-room template for room posts.
     * 4. Single-seat template for seat posts.
     *
     * If no post or readable plugin template can be resolved, control returns
     * to WordPress so the active theme can handle the request. An included
     * template executes in this method's local scope and is responsible for
     * rendering the complete response. Execution then stops to prevent the
     * theme's template loader from producing a second response.
     *
     * @return void
     */
    public function includeSingleTemplate()
    {
        global $post;
        if (!is_a($post, '\WP_Post')) {
            return;
        }

        $template = '';
        if (isset($_GET['require-auth']) && wp_verify_nonce($_GET['require-auth'], 'require-auth')) {
            $idm = new IdM;
            $ldap = new LDAP;
            if (!$idm->isAuthenticated() && !$ldap->isAuthenticated()) {
                $template = plugin()->getPath('includes/templates/auth') . 'single-auth.php';
            }
        } elseif (
            isset($_REQUEST['nonce']) &&
            wp_verify_nonce($_REQUEST['nonce'], 'rsvp-availability')
        ) {
            $template = plugin()->getPath('includes/templates') . 'single-form.php';
        } elseif ($post->post_type == 'room') {
            $template = plugin()->getPath('includes/templates') . 'single-room.php';
        } elseif ($post->post_type == 'seat') {
            $template = plugin()->getPath('includes/templates') . 'single-seat.php';
        }

        if (!is_readable($template)) {
            return;
        }

        include($template);
        exit;
    }

    /**
     * Start legacy seat authentication only for room/seat booking requests.
     *
     * @return void
     */
    public function maybeAuthenticate()
    {
        if (isset($_REQUEST['id']) && Functions::isCourseBooking(absint($_REQUEST['id']))) {
            return;
        }
        // Check In/Out (seat)
        if (isset($_REQUEST['nonce']) && wp_verify_nonce($_REQUEST['nonce'], 'rrze-rsvp-seat-check-inout')) {
            $idm = new IdM;
            $ldap = new LDAP;
            if (!$idm->isAuthenticated() && !$ldap->isAuthenticated()) {
                $queryStr = Functions::getQueryStr([], ['require-auth']);
                Auth::tryLogIn($queryStr);
            }
        }
    }

    /**
     * skipCache
     * Check if cache is bypassed.
     * @return boolean
     */
    public function skipCache(): bool
    {
        global $post_type;
        if (has_block('rrze-rsvp/course-booking') || is_page('rsvp-course-booking')) {
            return true;
        }
        if (in_array($post_type, array_keys(Capabilities::getCurrentCptArgs()))) {
            return true;
        }
        return false;
    }

    public function resetSettings()
    {
        if (isset($_POST['rrze_rsvp']) && isset($_POST['rrze_rsvp']['reset_reset_settings']) && $_POST['rrze_rsvp']['reset_reset_settings'] == 'on') {
            $optionName = getOptionName();
            delete_option($optionName);
        }
    }

    /**
     * Load admin assets and translated configuration for RSVP editing screens.
     *
     * @return void
     */
    public function adminEnqueueScripts()
    {
        global $post_type;

        wp_enqueue_style(
            'rrze-rsvp-admin-menu',
            plugins_url('build/menu.css', plugin()->getBasename()),
            [],
            plugin()->getVersion()
        );

        if (!in_array($post_type, array_keys(Capabilities::getCurrentCptArgs()))) {
            return;
        }

        wp_enqueue_style(
            'rrze-rsvp-admin',
            plugins_url('build/admin.css', plugin()->getBasename()),
            [],
            $this->getAssetVersion('admin.css')
        );

        wp_enqueue_script(
            'rrze-rsvp-admin',
            plugins_url('build/admin.js', plugin()->getBasename()),
            ['jquery'],
            $this->getAssetVersion('admin.js')
        );

        wp_localize_script('rrze-rsvp-admin', 'rrze_rsvp_admin', array(
            'dateformat' => get_option('date_format'),
            'text_cancel' => __('Do you want to cancel?', 'rrze-rsvp'),
            'text_delete_permanently' => __('Are you sure you want to permanently delete the selected bookings? This cannot be undone.', 'rrze-rsvp'),
            'text_cancelled' => _x('Cancelled', 'Booking', 'rrze-rsvp'),
            'text_confirmed' => _x('Confirmed', 'Booking', 'rrze-rsvp'),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'booking_action_nonce' => wp_create_nonce('rrze-rsvp-booking-action'),
            'timeslots' => $post_type === 'room' ? $this->getTimeslotEditorLabels() : null,
            'closedDays' => $post_type === 'room' ? $this->getClosedDaysEditorLabels() : null,
            // Strings für CPT Booking Backend
            'alert_no_seat_date' => __('Please select a seat first.', 'rrze-rsvp')
        ));

        if ($post_type == 'booking') {
            wp_dequeue_script('autosave');
        } elseif ($post_type == 'room') {
            wp_dequeue_script('autosave');
        } elseif ($post_type == 'seat') {
            wp_dequeue_script('autosave');
            wp_enqueue_script(
                'rrze-rsvp-seat',
                plugins_url('build/seat.js', plugin()->getBasename()),
                ['jquery'],
                plugin()->getVersion()
            );

            wp_localize_script('rrze-rsvp-seat', 'button_label', ['label' => __('Create Seats', 'rrze-rsvp')]);
        }
    }

    /**
     * Invalidate cached bundles whenever their compiled content is updated.
     *
     * @param string $filename Bundle filename within the build directory.
     * @return string Plugin version with an asset modification timestamp.
     */
    protected function getAssetVersion(string $filename): string
    {
        $path = dirname(__DIR__) . '/build/' . $filename;
        return plugin()->getVersion() . (is_file($path) ? '.' . filemtime($path) : '');
    }

    /**
     * Provide translated schedule controls and distinguish saved legacy rules.
     *
     * @return array Labels and flags used by the progressive schedule editor.
     */
    protected function getTimeslotEditorLabels(): array
    {
        $timeslots = get_post_meta(get_the_ID(), 'rrze-rsvp-room-timeslots', true);

        return [
            'hasSavedTimeslots' => is_array($timeslots) && !empty($timeslots),
            'type' => __('Schedule type', 'rrze-rsvp'),
            'single' => __('Single date', 'rrze-rsvp'),
            'weekly' => __('Weekly', 'rrze-rsvp'),
            'date' => __('Date', 'rrze-rsvp'),
            'singleHelp' => __('Bookable only on this date. Add another time slot for additional dates.', 'rrze-rsvp'),
            'weeklyHelp' => __('Repeats on the selected weekdays. Optionally limit the date range below.', 'rrze-rsvp'),
            'chooseDate' => __('Choose a date and time.', 'rrze-rsvp'),
            'chooseWeekdays' => __('Choose weekdays and a time.', 'rrze-rsvp'),
            'from' => __('from', 'rrze-rsvp'),
            'until' => __('until', 'rrze-rsvp'),
            'unlimited' => __('No date limit', 'rrze-rsvp'),
            'locked' => __('Bookings exist for this time slot. Its schedule type, weekdays and times are protected.', 'rrze-rsvp'),
            'dateRequired' => __('Please select a date for this time slot.', 'rrze-rsvp'),
            'weekdayRequired' => __('Please select at least one weekday.', 'rrze-rsvp'),
            'invalidDate' => __('Please enter a valid date.', 'rrze-rsvp'),
            'dateOrder' => __('The end date must not be before the start date.', 'rrze-rsvp'),
            'timeRequired' => __('Please enter start and end times in HH:MM format.', 'rrze-rsvp'),
            'timeOrder' => __('The end time must be after the start time on the same day.', 'rrze-rsvp'),
        ];
    }

    /**
     * Supply calendar labels and site-local date conventions for closed days.
     *
     * @return array Translated controls and WordPress calendar settings.
     */
    protected function getClosedDaysEditorLabels(): array
    {
        return [
            'today' => current_time('Y-m-d'),
            'firstDay' => max(0, min(6, (int) get_option('start_of_week', 1))),
            'calendar' => __('Closed days calendar', 'rrze-rsvp'),
            'help' => __('Select closed days in the calendar. Highlighted days are closed; click again to remove them.', 'rrze-rsvp'),
            'previous' => __('Previous month', 'rrze-rsvp'),
            'next' => __('Next month', 'rrze-rsvp'),
            'currentMonth' => __('Current month', 'rrze-rsvp'),
            'selected' => __('Selected closed days', 'rrze-rsvp'),
            'empty' => __('No closed days selected.', 'rrze-rsvp'),
            /* translators: %s: Localized calendar date. */
            'remove' => __('Remove closed day: %s', 'rrze-rsvp'),
            'textList' => __('Date list (YYYY-MM-DD)', 'rrze-rsvp'),
            'invalid' => __('Some lines cannot be displayed in the calendar. Enter one valid date per line (YYYY-MM-DD), without spaces. Your text is retained.', 'rrze-rsvp'),
        ];
    }

    /**
     * Register booking assets and translated labels for progressive enhancement.
     *
     * @return void
     */
    public function wpEnqueueScripts()
    {
        wp_register_style(
            'rrze-rsvp-shortcode',
            plugins_url('build/shortcode.css', plugin()->getBasename()),
            [],
            $this->getAssetVersion('shortcode.css')
        );
        wp_register_script(
            'rrze-rsvp-shortcode',
            plugins_url('build/shortcode.js', plugin()->getBasename()),
            ['jquery'],
            $this->getAssetVersion('shortcode.js')
        );
        wp_localize_script('rrze-rsvp-shortcode', 'rsvp_booking', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rsvp-ajax-nonce'),
            'picker' => [
                'loading' => __('Loading availability…', 'rrze-rsvp'),
                'loadingTimes' => __('Loading available times…', 'rrze-rsvp'),
                'updated' => __('Availability updated.', 'rrze-rsvp'),
                'error' => __('Availability could not be loaded. Please try again.', 'rrze-rsvp'),
                'chooseDay' => __('Select an available day in the calendar to see its times.', 'rrze-rsvp'),
                'chooseTime' => __('Choose a day and time to see available seats.', 'rrze-rsvp'),
                'selection' => __('Your selection', 'rrze-rsvp'),
                'incomplete' => __('Please complete your appointment selection first.', 'rrze-rsvp'),
            ],
        ]);
    }
}
