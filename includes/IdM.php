<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use SimpleSAML\Auth\Simple as SimpleSAMLAuthSimple;

class IdM
{
    protected $webssoPlugin = 'fau-websso/fau-websso.php';

    protected $webssoOptionName = '_fau_websso';

    protected $simplesamlAuth = null;

    protected $personAttributes = null;

    protected $uid = null;

    protected $mail = null;

    protected $displayName = null;

    protected $eduPersonAffiliation = null;

    protected $eduPersonEntitlement = null;

    public function __construct()
    {
        //
    }

    public function tryLogIn(bool $message = false)
    {
        $this->simplesamlAuth();

        if (!$this->simplesamlAuth || !$this->simplesamlAuth->isAuthenticated()) {
            if ($message) {
                wp_die(
                    $this->getMessage(),
                    __('Forbidden', 'rrze-rsvp'),
                    [
                        'response' => '403',
                        'back_link' => false
                    ]
                );
            }
            $this->simplesamlAuth->requireAuth();
        }

        $this->personAttributes = $this->simplesamlAuth->getAttributes();

        $this->uid = isset($this->personAttributes['urn:mace:dir:attribute-def:uid'][0]) ? $this->personAttributes['urn:mace:dir:attribute-def:uid'][0] : null;
        $this->mail = isset($this->personAttributes['urn:mace:dir:attribute-def:mail'][0]) ? $this->personAttributes['urn:mace:dir:attribute-def:mail'][0] : null;
        $this->displayName = isset($this->personAttributes['urn:mace:dir:attribute-def:displayName'][0]) ? $this->personAttributes['urn:mace:dir:attribute-def:displayName'][0] : null;
        $this->eduPersonAffiliation = isset($this->personAttributes['urn:mace:dir:attribute-def:eduPersonAffiliation']) ? $this->personAttributes['urn:mace:dir:attribute-def:eduPersonAffiliation'] : null;
        $this->eduPersonEntitlement = isset($this->personAttributes['urn:mace:dir:attribute-def:eduPersonEntitlement']) ? $this->personAttributes['urn:mace:dir:attribute-def:eduPersonEntitlement'] : null;

        return true;
    }

    protected function getMessage()
    {
        $message = '';

        if ($this->simplesamlAuth) {
            $login_url = $this->simplesamlAuth->getLoginURL();
            $message .= '<h3>' . __('Access to the requested page is denied', 'rrze-rsvp') . '</h3>';
            $message .= '<p>' . sprintf(__('<a href="%s">Please login with your IdM username</a>.', 'rrze-rsvp'), $login_url) . '</p>';
            $message .= '<p>' . sprintf(__('<a href="%s">Login through Single Sign-On (central login service of the University Erlangen-Nürnberg)</a>.', 'rrze-rsvp'), $login_url) . '</p>';
            return $message;
        }

        $message .= '<h3>' . __('Access is denied', 'rrze-rsvp') . '</h3>';
        return $message;
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

    protected function simplesamlAuth()
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

        require_once(WP_CONTENT_DIR . $options['simplesaml_include']);
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
