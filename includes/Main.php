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

    public function maybeAuthenticate()
    {
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
            plugin()->getVersion()
        );

        wp_enqueue_script(
            'rrze-rsvp-admin',
            plugins_url('build/admin.js', plugin()->getBasename()),
            ['jquery'],
            plugin()->getVersion()
        );

        wp_localize_script('rrze-rsvp-admin', 'rrze_rsvp_admin', array(
            'dateformat' => get_option('date_format'),
            'text_cancel' => __('Do you want to cancel?', 'rrze-rsvp'),
            'text_cancelled' => _x('Cancelled', 'Booking', 'rrze-rsvp'),
            'text_confirmed' => _x('Confirmed', 'Booking', 'rrze-rsvp'),
            'ajaxurl' => admin_url('admin-ajax.php'),
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

    public function wpEnqueueScripts()
    {
        wp_register_style(
            'rrze-rsvp-shortcode',
            plugins_url('build/shortcode.css', plugin()->getBasename()),
            [],
            plugin()->getVersion()
        );
        wp_register_script(
            'rrze-rsvp-shortcode',
            plugins_url('build/shortcode.js', plugin()->getBasename()),
            ['jquery'],
            plugin()->getVersion()
        );
        wp_localize_script('rrze-rsvp-shortcode', 'rsvp_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rsvp-ajax-nonce'),
        ]);
    }
}
