<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use RRZE\RSVP\Settings;

class LDAP {
    protected $settings;
    protected $connection;
    protected $server;
    protected $port;
    protected $domain;
    protected $distinguished_name;
    protected $base_dn;
    protected $filter;
    protected $attributes;

    public function __construct() {
        $this->settings = new Settings(plugin()->getFile());
        // $this->server = $this->settings->getOption('ldap', 'server');
        // $this->base_dn = $this->settings->getOption('ldap', 'domain');

        $this->server = 'ubaddc1.bib.uni-erlangen.de';
        $this->port = '3268';
        // $this->distinguished_name = 'CN=UB Bib User,OU=Groups,OU=UB,DC=ubad,DC=fau,DC=de';
        $this->distinguished_name = 'CN=UB_Bib_User,OU=Groups,OU=UB,DC=ubad,DC=fau,DC=de';
        // $this->base_dn = 'ubad.fau.de:3269';
        $this->base_dn = 'ubad.fau.de';
        // $this->filter = 'UB_Bib_User';
        $this->filter = 'sAMAccountName';
        $this->attributes = array("mail", "displayName", "givenName", "title");
    }

    public function onLoaded() {
        add_shortcode('rsvp-ldap-test', [$this, 'ldapTest'], 10, 2);
    }
    
    private function logError(string $method): string{
        $msg = 'LDAP-error ' . ldap_errno($this->connection) . ' ' . ldap_error($this->connection) . " using $method | server = {$this->server}:{$this->port}";
        do_action('rrze.log.error', 'rrze-rsvp : ' . $msg);
        return $msg;
    }

    public function ldapTest($atts, $content = ''){
        if(isset($_POST['username']) && isset($_POST['password'])){
            $this->connection = ldap_connect($this->server, $this->port);
        
            if (!$this->connection){
                $content = $this->logError('ldap_connect()');
            }else{
                ldap_set_option($this->connection, LDAP_OPT_PROTOCOL_VERSION, 3);
                ldap_set_option($this->connection, LDAP_OPT_REFERRALS, 0);
                $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
                $password = filter_input(INPUT_POST, 'password', FILTER_SANITIZE_STRING);
            
                // $bind = @ldap_bind($this->connection, $username, $password);
                $bind = @ldap_bind($this->connection, $this->base_dn . ',' . $username, $password);
                // $bind = @ldap_bind($this->connection, $this->base_dn . '\\' . $username, $password);
                // $bind = @ldap_bind($this->connection, $this->base_dn . '@' . $username, $password);
                // $bind = @ldap_bind($this->connection, $username . ',' . $this->base_dn, $password);
                // $bind = @ldap_bind($this->connection, $username . '\\' . $this->base_dn, $password);
                // $bind = @ldap_bind($this->connection, $username . '@' . $this->base_dn, $password);
                // => none of these 7 variants do work : $username &| $password is wrong

                if (!$bind) {
                    $content = $this->logError('ldap_bind()');
                }else{
                    $result = @ldap_search( $this->connection, $this->distinguished_name, "({$this->filter}=$username)");
                    if ($result === false){
                        $content = $this->logError('ldap_search()');
                    }else{
                        $info = @ldap_get_entries($this->connection, $result);
                        // return_value["count"] = number of entries in the result
                        // return_value[0] : refers to the details of first entry
                        // return_value[i]["dn"] =  DN of the ith entry in the result
                        // return_value[i]["count"] = number of attributes in ith entry
                        // return_value[i][j] = NAME of the jth attribute in the ith entry in the result
                        // return_value[i]["attribute"]["count"] = number of values for attribute in ith entry
                        // return_value[i]["attribute"][j] = jth value of attribute in ith entry                        
                        for ($i=0; $i<$info["count"]; $i++) {
                            if($info['count'] > 1){
                                break;
                            }
                            $content = "<p>You are accessing <strong> ". $info[$i]["sn"][0] .", " . $info[$i]["givenname"][0] ."</strong><br /> (" . $info[$i]["samaccountname"][0] .")</p>\n"
                                . json_encode($info) . ' $userDn = ' . $info[$i]["distinguishedname"][0]; 
                        }
                        if (!$content){
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