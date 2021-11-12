<?php

namespace RRZE\RSVP\Auth;

defined('ABSPATH') || exit;

use RRZE\RSVP\Functions;

use SimpleSAML\Auth\Simple as SimpleSAMLAuthSimple;
use SimpleSAML\Session as Session;

final class IdM extends Auth
{
    protected $ssoPlugin = 'rrze-sso/rrze-sso.php';
    // Backward compatibility
    protected $webssoPlugin = 'fau-websso/fau-websso.php';

    protected $ssoOptionName = 'rrze_sso';
    // Backward compatibility
    protected $webssoOptionName = '_fau_websso';

    public $simplesamlAuth = null;

    protected $displayName = null;

    protected $eduPersonAffiliation = null;

    protected $eduPersonEntitlement = null;

    public function __construct()
    {
        $this->simplesamlAuth = $this->simplesamlAuth();
        if ($this->simplesamlAuth) $this->setAttributes();
    }

    public function isAuthenticated(): bool
    {
        if ($this->simplesamlAuth && $this->simplesamlAuth->isAuthenticated()) {
            return true;
        } else {
            if (class_exists('Session')){
                Session::getSessionFromRequest()->cleanup();
            }
            return false;
        }
    }

    public function setAttributes()
    {
        $this->personAttributes = $this->simplesamlAuth->getAttributes();

        if ($this->isPluginActive($this->ssoPlugin)) {
            $this->uid = $this->personAttributes['uid'][0] ?? null;
            $this->mail = $this->personAttributes['mail'][0] ?? null;
            $this->displayName = $this->personAttributes['displayName'][0] ?? null;
            $this->eduPersonAffiliation = $this->personAttributes['eduPersonAffiliation'] ?? null;
            $this->eduPersonEntitlement = $this->personAttributes['eduPersonEntitlement'] ?? null;
        // Backward compatibility    
        } elseif ($this->isPluginActive($this->webssoPlugin)) {
            $this->uid = $this->personAttributes['urn:mace:dir:attribute-def:uid'][0] ?? null;
            $this->mail = $this->personAttributes['urn:mace:dir:attribute-def:mail'][0] ?? null;
            $this->displayName = $this->personAttributes['urn:mace:dir:attribute-def:displayName'][0] ?? null;
            $this->eduPersonAffiliation = $this->personAttributes['urn:mace:dir:attribute-def:eduPersonAffiliation'] ?? null;
            $this->eduPersonEntitlement = $this->personAttributes['urn:mace:dir:attribute-def:eduPersonEntitlement'] ?? null;    
        }
    }

    public function getCustomerData(): array
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

    public function logout(string $returnTo = '')
    {
        if (filter_var($returnTo, FILTER_VALIDATE_URL)) {
            $this->simplesamlAuth->logout($returnTo);
        } else {
            $this->simplesamlAuth->logout();
        }
        Session::getSessionFromRequest()->cleanup();
    }

    public function getLoginURL()
    {
        $queryStr = Functions::getQueryStr([], ['require-auth']);
        $loginUrl = trailingslashit(get_permalink()) . ($queryStr ? '?' . $queryStr : '');
        return $this->simplesamlAuth->getLoginURL($loginUrl);
    }

    private function simplesamlAuth()
    {
        if ($this->isPluginActive($this->ssoPlugin)) {
            if (is_multisite()) {
                $options = get_site_option($this->ssoOptionName);
            } else {
                $options = get_option($this->ssoOptionName);
            }
        // Backward compatibility
        } elseif ($this->isPluginActive($this->webssoPlugin)) {
            if (is_multisite()) {
                $options = get_site_option($this->webssoOptionName);
            } else {
                $options = get_option($this->webssoOptionName);
            }
        } else {
            return null;
        }

        if (!isset($options['simplesaml_include']) || !isset($options['simplesaml_auth_source'])) {
            return null;
        }

        if (!file_exists(WP_CONTENT_DIR . $options['simplesaml_include'])) {
            return null;
        }

        if (!class_exists('\SimpleSAML\Auth\Simple')) {
            require_once(WP_CONTENT_DIR . $options['simplesaml_include']);
        }

        return new SimpleSAMLAuthSimple($options['simplesaml_auth_source']);
    }

    private function isPluginActive($plugin)
    {
        return in_array($plugin, (array) get_option('active_plugins', array())) || $this->isPluginActiveForNetwork($plugin);
    }

    private function isPluginActiveForNetwork($plugin)
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
