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

    protected $connection = '';

    protected $port;

    protected $distinguished_name;

    protected $bind_base_dn;

    protected $search_base_dn;

    protected $searchFilter;

    protected $sessionTimeout = 10 * MINUTE_IN_SECONDS;

    public function __construct()
    {
        if (!isset($_SESSION)) {
            session_name('rrze_rsvp');
            session_start();
        }
        if (!empty($_SESSION['ldap_logged_in']) && $_SESSION['ldap_logged_in'] < time() - $this->sessionTimeout) {
            unset($_SESSION['ldap_logged_in'], $_SESSION['ldap_mail']);
        }

        if (!empty($_SESSION['ldap_logged_in'])) $this->setAttributes();

        $this->settings = new Settings(plugin()->getFile());
        $this->server = $this->settings->getOption('ldap', 'server');
        $this->port = $this->settings->getOption('ldap', 'port');
        $this->distinguished_name = $this->settings->getOption('ldap', 'distinguished_name');
        $this->bind_base_dn = $this->settings->getOption('ldap', 'bind_base_dn');
        $this->search_base_dn = $this->settings->getOption('ldap', 'search_base_dn');
    }

    public function isAuthenticated(): bool
    {
        return empty($_SESSION['ldap_logged_in']) ? false : true;
    }

    public function setAttributes()
    {
        $this->personAttributes = [
            'uid' => isset($_SESSION['ldap_uid']) ? $_SESSION['ldap_uid'] : null,
            'mail' => isset($_SESSION['ldap_mail']) ? $_SESSION['ldap_mail'] : null
        ];

        $this->uid = $this->personAttributes['uid'];
        $this->mail = $this->personAttributes['mail'];
    }

    public function getCustomerData(): array
    {
        $this->logout();
        return [
            'customer_email' => $this->mail ? $this->mail : __('no@email', 'rrze-rsvp')
        ];
    }

    public function login()
    {
        $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
        $username = ($username ? $username : filter_input(INPUT_GET, 'username', FILTER_SANITIZE_STRING));
        $password = filter_input(INPUT_POST, 'password', FILTER_SANITIZE_STRING);
        $password = ($password ? $password : filter_input(INPUT_GET, 'password', FILTER_SANITIZE_STRING));

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
                                $_SESSION['ldap_logged_in'] = time();
                                $_SESSION['ldap_uid'] = $username;
                                $_SESSION['ldap_mail'] = $mail;
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
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();

            // setcookie(
            //     session_name(),
            //     '',
            //     time() - 42000,
            //     $params['ldap_logged_in'],
            //     $params['ldap_uid'],
            //     $params['ldap_mail']
            // );

            $_SESSION['ldap_logged_in'] = '';
            $_SESSION['ldap_uid'] = '';
            $_SESSION['ldap_mail'] = '';

        }
        session_destroy();
    }

    private function logError(string $method)
    {
        $msg = 'LDAP-error ' . ldap_errno($this->connection) . ' ' . ldap_error($this->connection) . " using $method | server = {$this->server}:{$this->port}";
        do_action('rrze.log.error', 'rrze-rsvp : ' . $msg);
    }
}
