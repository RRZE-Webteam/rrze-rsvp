<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use RRZE\RSVP\Settings;

class LDAP {
    protected $settings;
    protected $ldap_server;
    protected $ldap_domain;


    public function __construct() {
        $this->settings = new Settings(plugin()->getFile());
        $this->ldap_server = $this->settings->getOption('ldap', 'server');
        $this->ldap_domain = $this->settings->getOption('ldap', 'domain');
    }


    public function onLoaded() {
        add_shortcode('rsvp-ldap-test', [$this, 'ldapTest'], 10, 2);
    }
    

    public function ldapTest($atts, $content = ''){
        if(isset($_POST['username']) && isset($_POST['password'])){
            $ldapconn = ldap_connect($this->ldap_server);
            ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($ldapconn, LDAP_OPT_REFERRALS, 0);
        
            if (!$ldapconn){
                $content = 'LDAP-error no.' . ldap_errno($ldapconn) . ': ' . ldap_error($ldapconn);
                do_action('rrze.log.error', 'rrze-rsvp : ldapTest() : ' . $content . ' using ldap_connect() with ' . $this->ldap_server);
            }else{
                $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
                $password = filter_input(INPUT_POST, 'password', FILTER_SANITIZE_STRING);
            
                // $username = $this->ldap_domain . '\\' . $username;
                // $username = $this->ldap_domain . '@' . $username;
            
                $bind = @ldap_bind($ldapconn, $username, $password);

                if (!$bind) {
                    $content = 'LDAP-error no.' . ldap_errno($ldapconn) . ': ' . ldap_error($ldapconn);
                    do_action('rrze.log.error', 'rrze-rsvp : ldapTest() : ' . $content . ' using ldap_bind()');
                }else{
                    $filter="(sAMAccountName=$username)";
                    $result = @ldap_search($ldapconn,"dc=MYDOMAIN,dc=COM",$filter);
                    if ($result === false){
                        $content = 'LDAP-error no.' . ldap_errno($ldapconn) . ': ' . ldap_error($ldapconn);
                        do_action('rrze.log.error', 'rrze-rsvp : ldapTest() : ' . $content . ' using ldap_search()');
                    }else{
                        $info = @ldap_get_entries($ldapconn, $result);
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
                        @ldap_close($ldapconn);
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