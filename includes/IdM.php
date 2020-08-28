<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use SimpleSAML\Auth\Simple as SimpleSAMLAuthSimple;

class IdM
{
    protected $webssoPlugin = 'fau-websso/fau-websso.php';

    protected $webssoOptionName = '_fau_websso';

    public $simplesamlAuth = null;

    protected $personAttributes = null;

    protected $uid = null;

    protected $mail = null;

    protected $displayName = null;

    protected $eduPersonAffiliation = null;

    protected $eduPersonEntitlement = null;

    protected $template;

    public function __construct()
    {
        $this->template = new Template;
    }

    public function onLoaded() {
        add_action('wp', [$this, 'requireAuth']);
    }

    public function tryLogIn()
    {
        if (!$this->simplesamlAuth()) {
            return false;
        }

        $roomId = isset($_GET['room_id']) ? absint($_GET['room_id']) : null;
        $room = $roomId ? sprintf('&room_id=%d', $roomId) : '';
        $seat = isset($_GET['seat_id']) ? sprintf('&seat_id=%d', absint($_GET['seat_id'])) : '';
        $bookingDate = isset($_GET['bookingdate']) ? sprintf('&bookingdate=%s', sanitize_text_field($_GET['bookingdate'])) : '';
        $timeslot = isset($_GET['timeslot']) ? sprintf('&timeslot=%s', sanitize_text_field($_GET['timeslot'])) : '';
        $nonce = isset($_GET['nonce']) ? sprintf('&nonce=%s', sanitize_text_field($_GET['nonce'])) : '';

        if (!$this->simplesamlAuth->isAuthenticated()) {
            $authNonce = sprintf('?require-sso-auth=%s', wp_create_nonce('require-sso-auth'));
            $redirectUrl = sprintf('%s/%s%s%s%s%s%s', get_permalink(), $authNonce, $room, $seat, $bookingDate, $timeslot, $nonce);
            header('HTTP/1.0 403 Forbidden');
            wp_redirect($redirectUrl);
            exit;
        }

        $this->personAttributes = $this->simplesamlAuth->getAttributes();

        $this->uid = isset($this->personAttributes['urn:mace:dir:attribute-def:uid'][0]) ? $this->personAttributes['urn:mace:dir:attribute-def:uid'][0] : null;
        $this->mail = isset($this->personAttributes['urn:mace:dir:attribute-def:mail'][0]) ? $this->personAttributes['urn:mace:dir:attribute-def:mail'][0] : null;
        $this->displayName = isset($this->personAttributes['urn:mace:dir:attribute-def:displayName'][0]) ? $this->personAttributes['urn:mace:dir:attribute-def:displayName'][0] : null;
        $this->eduPersonAffiliation = isset($this->personAttributes['urn:mace:dir:attribute-def:eduPersonAffiliation']) ? $this->personAttributes['urn:mace:dir:attribute-def:eduPersonAffiliation'] : null;
        $this->eduPersonEntitlement = isset($this->personAttributes['urn:mace:dir:attribute-def:eduPersonEntitlement']) ? $this->personAttributes['urn:mace:dir:attribute-def:eduPersonEntitlement'] : null;

        return true;
    }

    public function requireAuth()
    {
        global $post;
        if (!is_a($post, '\WP_Post')) {
            return;
        }

        $nonce = isset($_GET['require-sso-auth']) ? sanitize_text_field($_GET['require-sso-auth']) : false;

        if (!$nonce) {
            return;
        }
        if (!wp_verify_nonce($nonce, 'require-sso-auth')) {
            header('HTTP/1.0 403 Forbidden');
            wp_redirect(get_site_url());
            exit;            
        }

        $roomId = isset($_GET['room_id']) ? absint($_GET['room_id']) : null;
        $room = $roomId ? sprintf('?room_id=%d', $roomId) : '';
        $seat = isset($_GET['seat_id']) ? sprintf('&seat_id=%d', absint($_GET['seat_id'])) : '';
        $bookingDate = isset($_GET['bookingdate']) ? sprintf('&bookingdate=%s', sanitize_text_field($_GET['bookingdate'])) : '';
        $timeslot = isset($_GET['timeslot']) ? sprintf('&timeslot=%s', sanitize_text_field($_GET['timeslot'])) : '';
        $nonce = isset($_GET['nonce']) ? sprintf('&nonce=%s', sanitize_text_field($_GET['nonce'])) : '';        

        if ($this->simplesamlAuth() && $this->simplesamlAuth->isAuthenticated()) {
            $redirectUrl = sprintf('%s/%s%s%s%s%s', get_permalink(), $room, $seat, $bookingDate, $timeslot, $nonce);
            wp_redirect($redirectUrl);
            exit;
        }

        wp_enqueue_style(
            'rrze-rsvp-require-auth',
            plugins_url('assets/css/rrze-rsvp.css', plugin()->getBasename()),
            [],
            plugin()->getVersion()
        );

        $data = [];
        if ($this->simplesamlAuth()) {
            $loginUrl = $this->simplesamlAuth->getLoginURL();
            $data['title'] = __('Authentication Required', 'rrze-rsvp');
            $data['please_login'] = sprintf(__('<a href="%s">Please login with your IdM username</a>.', 'rrze-rsvp'), $loginUrl);
        } else {
            header('HTTP/1.0 403 Forbidden');
            wp_redirect(get_site_url());
            exit;
        }

        add_filter('the_content', function ($content) use ($data) {
            return $this->template->getContent('auth/require-sso-auth', $data);
        });
    }

    public function isAuthenticated()
    {
        return $this->simplesamlAuth && $this->simplesamlAuth->isAuthenticated();
    }

    public function getPersonAttributes()
    {
        return $this->personAttributes;
    }

    public function getCustomerData()
    {
        if ($displayName = $this->displayName) {
            $displayNameAry = array_map('trim', explode(' ', $displayName));
            $customerFirstname = array_shift($displayNameAry);
            $customerLastname = implode(' ', $displayNameAry);
        } else {
            $customerFirstname = __('No name', 'rrze-rsvp');
            $customerLastname = $customerFirstname;
        }

        return [
            'customer_firstname' => $customerFirstname,
            'customer_lastname' => $customerLastname,
            'customer_email' => $this->mail ? $this->mail : __('no@email', 'rrze-rsvp')
        ];
    }

    public function simplesamlAuth()
    {
        if (!$this->isPluginActive($this->webssoPlugin)) {
            return false;
        }

        if (is_multisite()) {
            $options = get_site_option($this->webssoOptionName);
        } else {
            $options = get_option($this->webssoOptionName);
        }

        if (!isset($options['simplesaml_include']) || !isset($options['simplesaml_auth_source'])) {
            return false;
        }

        if (!file_exists(WP_CONTENT_DIR . $options['simplesaml_include'])) {
            return false;
        }

        if (!class_exists('\SimpleSAML\Auth\Simple')) {
            require_once(WP_CONTENT_DIR . $options['simplesaml_include']);
        }
        $this->simplesamlAuth = new SimpleSAMLAuthSimple($options['simplesaml_auth_source']);
        return true;
    }

    protected function isPluginActive($plugin)
    {
        return in_array($plugin, (array) get_option('active_plugins', array())) || $this->isPluginActiveForNetwork($plugin);
    }

    protected function isPluginActiveForNetwork($plugin)
    {
        if (!is_multisite()) {
            return false;
        }

        $plugins = get_site_option('active_sitewide_plugins');
        if (isset($plugins[$plugin])) {
            return true;
        }

        return false;
    }
}
