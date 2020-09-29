<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use RRZE\RSVP\Settings;

class LDAP {
    protected $settings;
    protected $server;
    protected $link_identifier = '';
    protected $port;
    protected $domain;
    protected $distinguished_name;
    protected $base_dn;
    protected $filter;
    protected $attributes;

    public function __construct() {
        $this->settings = new Settings(plugin()->getFile());
        $this->server = 'ubaddc1.bib.uni-erlangen.de';
        $this->port = '389';
        $this->distinguished_name = 'CN=UB Bib User,OU=Groups,OU=UB,DC=ubad,DC=fau,DC=de';
        $this->base_dn = 'ubad.fau.de';
        $this->filter = '(sAMAccountName=UB_Bib_User)';

        $this->attributes = array("mail", "displayName", "givenName", "title");
    }

    public function onLoaded() {
        add_shortcode('rsvp-ldap-test', [$this, 'ldapTest'], 10, 2);
    }
    
    private function logError(string $method): string{
        $msg = 'LDAP-error ' . ldap_errno($this->link_identifier) . ' ' . ldap_error($this->link_identifier) . " using $method | server = {$this->server}:{$this->port}";
        do_action('rrze.log.error', 'rrze-rsvp : ' . $msg);
        return $msg;
    }

    public function ldapTest($atts, $content = ''){
        if(isset($_POST['username']) && isset($_POST['password'])){
            $this->link_identifier = ldap_connect($this->server, $this->port);
        
            if (!$this->link_identifier){
                $content = $this->logError('ldap_connect()');
            }else{
                ldap_set_option($this->link_identifier, LDAP_OPT_PROTOCOL_VERSION, 3);
                ldap_set_option($this->link_identifier, LDAP_OPT_REFERRALS, 0);

                $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
                $password = filter_input(INPUT_POST, 'password', FILTER_SANITIZE_STRING);
            
                $bind = @ldap_bind($this->link_identifier, $username . '@' . $this->base_dn, $password);

                if (!$bind) {
                    $content = $this->logError('ldap_bind()');
                }else{
                    $result_identifier = @ldap_search($this->link_identifier, $this->distinguished_name, $this->filter);
                    
                    if ($result_identifier === false){
                        $content = $this->logError('ldap_search()');
                    }else{
                        $aEntry = @ldap_get_entries($this->link_identifier, $result_identifier);

                        if (isset($aEntry['count']) && $aEntry['count'] > 0){
                            if (isset($aEntry[0]['cn'][0])){
                                $content = '<p>Hello <strong>' . $aEntry[0]['cn'][0] . '</strong><br>' . json_encode($aEntry); 
                            }else{
                                $content = $this->logError('ldap_get_entries() : Attributes have changed. Expected $aEntry[0][\'cn\'][0]');
                            }
                        }else{
                            $content = 'User not found';
                        }
                        @ldap_close($this->connection);
                    }
                }
            }
        }else{
            $content = '<form action="#" method="POST">'
                . '<label for="username">Username: </label><input id="username" type="text" name="username" />'
                . '<label for="password">Password: </label><input id="password" type="password" name="password" />'
                . '<input type="submit" name="submit" value="Submit" />'
                . '</form>';
        }
        return $content;   
    } 
}