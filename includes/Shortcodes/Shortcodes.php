<?php

namespace RRZE\RSVP\Shortcodes;

defined('ABSPATH') || exit;

use RRZE\RSVP\Helper;
use RRZE\RSVP\IdM;
use RRZE\RSVP\LDAP;
use RRZE\RSVP\Shortcodes\Bookings;
use RRZE\RSVP\Shortcodes\Availability;
use RRZE\RSVP\Shortcodes\QR;
use function RRZE\RSVP\Config\getShortcodeSettings;
use function RRZE\RSVP\plugin;

/**
 * Laden und definieren der Shortcodes
 */
class Shortcodes
{
    protected $pluginFile;
    
    private $settings = '';

    private $shortcodesettings = 'X';

    protected $idm;

    // protected $ldap; 

    public function __construct($pluginFile, $settings)
    {
        $this->pluginFile = $pluginFile;
        $this->settings = $settings;
        $this->shortcodesettings = getShortcodeSettings();
        $this->idm = new IdM;
        $this->ldapInstance = new LDAP;
    }

    public function onLoaded()
    {
        add_action('template_redirect', [$this, 'maybeAuthenticate']);
        add_filter('single_template', [$this, 'includeSingleTemplate']);

        $bookings_shortcode = new Bookings($this->pluginFile,  $this->settings);
        $bookings_shortcode->onLoaded();

        $availability_shortcode = new Availability($this->pluginFile,  $this->settings);
        $availability_shortcode->onLoaded();

        $qr_shortcode = new QR($this->pluginFile,  $this->settings);
        $qr_shortcode->onLoaded();
    }

    public function gutenberg_init()
    {
        // Skip block registration if Gutenberg is not enabled/merged.
        if (!function_exists('register_block_type')) {
            return;
        }

        $js = '../assets/js/gutenberg.js';
        $editor_script = $this->settings['block']['blockname'] . '-blockJS';

        wp_register_script(
            $editor_script,
            plugins_url($js, __FILE__),
            array(
                'wp-blocks',
                'wp-i18n',
                'wp-element',
                'wp-components',
                'wp-editor'
            ),
            filemtime(dirname(__FILE__) . '/' . $js)
        );

        wp_localize_script($editor_script, 'blockname', $this->settings['block']['blockname']);

        register_block_type($this->settings['block']['blocktype'], array(
            'editor_script' => $editor_script,
            'render_callback' => [$this, 'shortcodeOutput'],
            'attributes' => $this->settings
        ));

        wp_localize_script($editor_script, $this->settings['block']['blockname'] . 'Config', $this->settings);
    }

    public function shortcodeAtts($atts, $tag, $shortcodesettings)
    {
        // merge given attributes with default ones
        $atts_default = array();
        //var_dump($this->shortcodesettings);
        foreach ($shortcodesettings as $tagname => $settings) {
            foreach ($settings as $k => $v) {
                if ($k != 'block') {
                    $atts_default[$tagname][$k] = $v['default'];
                }
            }
        }
        return shortcode_atts($atts_default[$tag], $atts);
    }

    public function includeSingleTemplate($singleTemplate)
    {
        global $post;
        if (isset($_GET['require-sso-auth']) && wp_verify_nonce($_GET['require-sso-auth'], 'require-sso-auth')) {
            return sprintf('%sincludes/templates/single-auth.php', plugin()->getDirectory());
        } elseif (isset($_GET['require-ldap-auth']) && wp_verify_nonce($_GET['require-ldap-auth'], 'require-ldap-auth')) {
            return sprintf('%sincludes/templates/single-ldap-auth.php', plugin()->getDirectory());
        } elseif (isset($_REQUEST['nonce']) && wp_verify_nonce($_REQUEST['nonce'], 'rsvp-availability')) {
            return sprintf('%sincludes/templates/single-form.php', plugin()->getDirectory());
        } elseif ($post->post_type == 'room') {
            return dirname($this->pluginFile) . '/includes/templates/single-room.php';
        } elseif ($post->post_type == 'seat') {
            return dirname($this->pluginFile) . '/includes/templates/single-seat.php';
        }
        return $singleTemplate;
    }

    public function maybeAuthenticate()
    {
        // Helper::debugLog(__FILE__, __LINE__, __METHOD__);

        global $post;
        if (!is_a($post, '\WP_Post') || isset($_GET['require-sso-auth']) || isset($_GET['require-ldap-auth'])) {
            return;
        }

        if (isset($_REQUEST['nonce']) && wp_verify_nonce($_REQUEST['nonce'], 'rrze-rsvp-seat-check-inout')) {
            $this->idm->tryLogIn();
            $this->ldapInstance->tryLogIn();
        }     
    }
}
