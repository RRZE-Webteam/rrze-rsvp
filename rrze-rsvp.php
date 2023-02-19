<?php

/*
Plugin Name:     RRZE RSVP
Plugin URI:      https://github.com/RRZE-Webteam/rrze-rsvp
Description:     FAU Reservation Tool
Version:         2.10.3
Author:          RRZE-Webteam
Author URI:      https://blogs.fau.de/webworking/
License:         GNU General Public License v2
License URI:     http://www.gnu.org/licenses/gpl-2.0.html
Domain Path:     /languages
Text Domain:     rrze-rsvp
*/

namespace RRZE\RSVP;

use RRZE\RSVP\CPT\CPT;

defined('ABSPATH') || exit;

const RRZE_PHP_VERSION = '7.4';
const RRZE_WP_VERSION = '6.0';

/**
 * Load the configuration file
 */
require_once __DIR__ . '/config/config.php';

/**
 * Composer autoload
 */
require_once __DIR__ . '/vendor/autoload.php';

// Register plugin hooks.
register_activation_hook(__FILE__, __NAMESPACE__ . '\activation');
register_deactivation_hook(__FILE__, __NAMESPACE__ . '\deactivation');

add_action('plugins_loaded', __NAMESPACE__ . '\loaded');

/**
 * Loads a plugin’s translated strings.
 */
function loadTextdomain()
{
    load_plugin_textdomain('rrze-rsvp', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

/**
 * System requirements verification.
 * @return string Return an error message.
 */
function systemRequirements(): string
{
    $error = '';
    if (version_compare(PHP_VERSION, RRZE_PHP_VERSION, '<')) {
        $error = sprintf(
            /* translators: 1: Server PHP version number, 2: Required PHP version number. */
            __('The server is running PHP version %1$s. The Plugin requires at least PHP version %2$s.', 'rrze-rsvp'),
            PHP_VERSION,
            RRZE_PHP_VERSION
        );
    } elseif (version_compare($GLOBALS['wp_version'], RRZE_WP_VERSION, '<')) {
        $error = sprintf(
            /* translators: 1: Server WordPress version number, 2: Required WordPress version number. */
            __('The server is running WordPress version %1$s. The Plugin requires at least WordPress version %2$s.', 'rrze-rsvp'),
            $GLOBALS['wp_version'],
            RRZE_WP_VERSION
        );
    }
    return $error;
}

/**
 * Activation callback function.
 */
function activation()
{
    loadTextdomain();
    if ($error = systemRequirements()) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            sprintf(
                /* translators: 1: The plugin name, 2: The error string. */
                __('Plugins: %1$s: %2$s', 'rrze-rsvp'),
                plugin_basename(__FILE__),
                $error
            )
        );
    } else {
        Users::addRoleCaps();
        Users::createBookingRole();

        $cpt = new CPT;
        $cpt->activation();

        flush_rewrite_rules();
    }
}

/**
 * Deactivation callback function.
 * Remove Roles and Caps.
 */
function deactivation()
{
    Users::removeRoleCaps();
    Users::removeBookingRole();

    flush_rewrite_rules();
}

/**
 * Instantiate Plugin class.
 * @return object Plugin
 */
function plugin()
{
    static $instance;
    if (null === $instance) {
        $instance = new Plugin(__FILE__);
    }

    return $instance;
}

/**
 * Execute on 'plugins_loaded' API/action.
 * @return void
 */
function loaded()
{
    loadTextdomain();
    plugin()->loaded();
    if ($error = systemRequirements()) {
        add_action('admin_init', function () use ($error) {
            if (current_user_can('activate_plugins')) {
                $pluginData = get_plugin_data(plugin()->getFile());
                $pluginName = $pluginData['Name'];
                $tag = is_plugin_active_for_network(plugin()->getBaseName()) ? 'network_admin_notices' : 'admin_notices';
                add_action($tag, function () use ($pluginName, $error) {
                    printf(
                        '<div class="notice notice-error"><p>' .
                            /* translators: 1: The plugin name, 2: The error string. */
                            __('Plugins: %1$s: %2$s', 'rrze-rsvp') .
                            '</p></div>',
                        esc_html($pluginName),
                        esc_html($error)
                    );
                });
            }
        });
        return;
    }
    $main = new Main(__FILE__);
    $main->onLoaded();
}
