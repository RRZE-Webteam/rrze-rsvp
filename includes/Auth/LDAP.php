<?php

namespace RRZE\RSVP\Auth;

defined('ABSPATH') || exit;

use RRZE\RSVP\Settings;
use function RRZE\RSVP\plugin;

final class LDAP extends Auth
{
    protected $settings;

    protected $session;

    protected $server;

    protected $connection = null;

    protected $port;

    protected $distinguished_name;

    protected $bind_base_dn;

    protected $search_base_dn;

    protected $searchFilter;

    protected $sessionTimeout = 10 * MINUTE_IN_SECONDS;

    protected $sessionName = 'rrze_rsvp';

    protected $ldapUid = null;

    protected $ldapMail = null;

    public function __construct()
    {
        $this->settings = new Settings(plugin()->getFile());
        $this->server = $this->settings->getOption('ldap', 'server');
        $this->port = $this->settings->getOption('ldap', 'port');
        $this->distinguished_name = $this->settings->getOption('ldap', 'distinguished_name');
        $this->bind_base_dn = $this->settings->getOption('ldap', 'bind_base_dn');
        $this->search_base_dn = $this->settings->getOption('ldap', 'search_base_dn');

        if ($this->isAuthenticated()) {
            $this->setAttributes();
        }
    }

    public function isAuthenticated(): bool
    {
        $sessionStarted = false;
        if (!isset($_SESSION)) {
            session_name($this->sessionName);
            session_start();
            $sessionStarted = true;
        }

        $this->ldapUid = !empty($_SESSION['ldap_uid']) ? $_SESSION['ldap_uid'] : null;
        $this->ldapMail = !empty($_SESSION['ldap_mail']) ? $_SESSION['ldap_mail'] : null;

        if ($sessionStarted) {
            session_write_close();
        }

        return !empty($this->ldapUid);
    }

    private function setAttributes()
    {
        $this->personAttributes = [
            'uid' => $this->ldapUid,
            'mail' => $this->ldapMail
        ];
    }

    public function getCustomerData(): array
    {
        $this->logout();
        return [
            'customer_email' => $this->personAttributes['mail'] ?: __('no@email', 'rrze-rsvp')
        ];
    }

    public function login()
    {
        $username = sanitize_text_field($_POST['username'] ?? '');
        $username = $username ? $username : sanitize_text_field($_GET['username'] ?? '');
        $password = sanitize_text_field($_POST['password'] ?? '');
        $password = $password ? $password : sanitize_text_field($_GET['password'] ?? '');

        if ($username && $password) {
            $this->connection = @ldap_connect($this->server, $this->port);

            if (!$this->connection) {
                $this->logError('ldap_connect()');
            } else {
                ldap_set_option($this->connection, LDAP_OPT_PROTOCOL_VERSION, 3);
                ldap_set_option($this->connection, LDAP_OPT_REFERRALS, 0);

                $bind = @ldap_bind($this->connection, $username . '@' . $this->bind_base_dn, $password);

                if (!$bind) {
                    $this->logError('ldap_bind()');
                } else {
                    $this->searchFilter = '(sAMAccountName=' . $username . ')';
                    $resultIdentifier = @ldap_search($this->connection, $this->search_base_dn, $this->searchFilter);

                    if ($resultIdentifier === false) {
                        $this->logError('ldap_search()');
                    } else {
                        $aEntry = @ldap_get_entries($this->connection, $resultIdentifier);

                        if (isset($aEntry['count']) && $aEntry['count'] > 0) {

                            if (isset($aEntry[0]['cn'][0]) && isset($aEntry[0]['mail'][0])) {
                                $mail = $aEntry[0]['mail'][0];
                                $sessionStarted = false;
                                if (!isset($_SESSION)) {
                                    session_name($this->sessionName);
                                    session_start();
                                    $sessionStarted = true;
                                }
                                $_SESSION['ldap_uid'] = $username;
                                $_SESSION['ldap_mail'] = $mail;
                                if ($sessionStarted) {
                                    session_write_close();
                                }
                            } else {
                                $this->logError('ldap_get_entries(): Attributes have changed. Expected $aEntry[0][\'cn\'][0] and $aEntry[0][\'mail\'][0]');
                            }
                        } else {
                            $this->logError('ldap_get_entries(): Not Found.');
                        }
                    }
                }
                @ldap_close($this->connection);
            }
        }
    }

    public function logout()
    {
        $cookies = [
            $this->sessionName,
            substr($this->sessionName, 1),
            'S' . $this->sessionName,
        ];

        foreach ($cookies as $cookie_name) {
            if (!isset($_COOKIE[$cookie_name])) {
                continue;
            }
            $params = session_get_cookie_params();
            setcookie($cookie_name, '', $_SERVER['REQUEST_TIME'] - 3600, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            unset($_COOKIE[$cookie_name]);
        }
    }

    private function logError(string $method)
    {
        $msg = 'LDAP-error ' . ldap_errno($this->connection) . ' ' . ldap_error($this->connection) . " using $method | server = {$this->server}:{$this->port}";
        do_action('rrze.log.error', 'rrze-rsvp : ' . $msg);
    }
}
