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

    public function tryLogIn()
    {
        $this->simplesamlAuth();

        if (!$this->simplesamlAuth || !$this->simplesamlAuth->isAuthenticated()) {
            return false;
        }

        $this->personAttributes = $this->simplesamlAuth->getAttributes();

        $this->uid = isset($this->personAttributes['urn:mace:dir:attribute-def:uid'][0]) ? $this->personAttributes['urn:mace:dir:attribute-def:uid'][0] : null;
        $this->mail = isset($this->personAttributes['urn:mace:dir:attribute-def:mail'][0]) ? $this->personAttributes['urn:mace:dir:attribute-def:mail'][0] : null;
        $this->displayName = isset($this->personAttributes['urn:mace:dir:attribute-def:displayName'][0]) ? $this->personAttributes['urn:mace:dir:attribute-def:displayName'][0] : null;
        $this->eduPersonAffiliation = isset($this->personAttributes['urn:mace:dir:attribute-def:eduPersonAffiliation']) ? $this->personAttributes['urn:mace:dir:attribute-def:eduPersonAffiliation'] : null;
        $this->eduPersonEntitlement = isset($this->personAttributes['urn:mace:dir:attribute-def:eduPersonEntitlement']) ? $this->personAttributes['urn:mace:dir:attribute-def:eduPersonEntitlement'] : null;

        return true;
    }

    public function getPersonAttributes()
    {
        return $this->personAttributes;
    }

    public function getGuestData()
    {
        $displayName = $this->displayName;
        $displayNameAry = explode(' ', $displayName);
        $guestFirstname = array_shift($displayNameAry);
        $guestLastname = implode(' ', $displayNameAry);

        return [
            'guest_firstname' => $guestFirstname,
            'guest_lastname' => $guestLastname,
            'guest_email' => $this->mail
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
