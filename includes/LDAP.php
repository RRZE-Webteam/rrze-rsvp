<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use RRZE\RSVP\Settings;

class LDAP {
    protected $settings;
    protected $server;
    protected $link_identifier = '';
    protected $port;
    protected $distinguished_name;
    protected $bind_base_dn;
    protected $search_base_dn;
    protected $search_filter;
    protected $isLoggedIn;

    protected $mail = null;

    public function __construct() {
        $this->settings = new Settings(plugin()->getFile());
        $this->server = $this->settings->getOption('ldap', 'server');
        $this->port = $this->settings->getOption('ldap', 'port');
        $this->distinguished_name = $this->settings->getOption('ldap', 'distinguished_name');
        $this->bind_base_dn = $this->settings->getOption('ldap', 'bind_base_dn');
        $this->search_base_dn = $this->settings->getOption('ldap', 'search_base_dn');
    }

    public function onLoaded() {
        add_shortcode('rsvp-ldap-login', [$this, 'ldapForm'], 10, 0);
        add_action('wp', [$this, 'requireAuth']);
    }
    
    private function logError(string $method): string{
        $msg = 'LDAP-error ' . ldap_errno($this->link_identifier) . ' ' . ldap_error($this->link_identifier) . " using $method | server = {$this->server}:{$this->port}";
        do_action('rrze.log.error', 'rrze-rsvp : ' . $msg);
        return $msg;
    }
    

    public function ldapForm(){
        if(isset($_POST['username']) && isset($_POST['password'])){
            $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
            $password = filter_input(INPUT_POST, 'password', FILTER_SANITIZE_STRING);

            $this->link_identifier = ldap_connect($this->server, $this->port);
        
            if (!$this->link_identifier){
                $content = $this->logError('ldap_connect()');
            }else{
                ldap_set_option($this->link_identifier, LDAP_OPT_PROTOCOL_VERSION, 3);
                ldap_set_option($this->link_identifier, LDAP_OPT_REFERRALS, 0);
            
                $bind = @ldap_bind($this->link_identifier, $username . '@' . $this->bind_base_dn, $password);


                if (!$bind) {
                    $content = $this->logError('ldap_bind()');
                }else{

                    $this->search_filter = '(sAMAccountName=' . $username . ')';
                    $result_identifier = @ldap_search($this->link_identifier, $this->search_base_dn, $this->search_filter);
                    
                    if ($result_identifier === false){
                        $content = $this->logError('ldap_search()');
                    }else{
                        $aEntry = @ldap_get_entries($this->link_identifier, $result_identifier);

                        if (isset($aEntry['count']) && $aEntry['count'] > 0){
                            if (isset($aEntry[0]['cn'][0]) && isset($aEntry[0]['mail'][0])){
                                $content = $aEntry[0]['mail'][0]; 
                                $this->mail = $aEntry[0]['mail'][0]; 
                                $this->isLoggedIn = true;
                                // $this->tryLogIn();
                                // exit;

                            }else{
                                $content = $this->logError('ldap_get_entries() : Attributes have changed. Expected $aEntry[0][\'cn\'][0] and $aEntry[0][\'mail\'][0]');
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



    public function requireAuth(){
        global $post;
        if (!is_a($post, '\WP_Post')) {
            return;
        }

        $nonce = isset($_GET['require-ldap-auth']) ? sanitize_text_field($_GET['require-ldap-auth']) : false;

        if (!$nonce) {
            return;
        }
        if (!wp_verify_nonce($nonce, 'require-ldap-auth')) {
            header('HTTP/1.0 403 Forbidden');
            wp_redirect(get_site_url());
            exit;            
        }

        $roomId = isset($_GET['room_id']) ? absint($_GET['room_id']) : null;
        $room = $roomId ? sprintf('?room_id=%d', $roomId) : '';
        $seat = isset($_GET['seat_id']) ? sprintf('&seat_id=%d', absint($_GET['seat_id'])) : '';
        $bookingDate = isset($_GET['bookingdate']) ? sprintf('&bookingdate=%s', sanitize_text_field($_GET['bookingdate'])) : '';
        $timeslot = isset($_GET['timeslot']) ? sprintf('&timeslot=%s', sanitize_text_field($_GET['timeslot'])) : '';
        $nonce = isset($_GET['ldap-nonce']) ? sprintf('&ldap-nonce=%s', sanitize_text_field($_GET['ldap-nonce'])) : '';
        
        $bookingId = isset($_GET['id']) && !$roomId ? sprintf('?id=%s', absint($_GET['id'])) : '';
        $action = isset($_GET['action']) ? sprintf('&action=%s', sanitize_text_field($_GET['action'])) : '';        

        if ($this->isLoggedIn) {
            $redirectUrl = sprintf('%s%s%s%s%s%s%s%s', trailingslashit(get_permalink()), $bookingId, $action, $room, $seat, $bookingDate, $timeslot, $nonce);
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
        $loginUrl = 'URL-UEBER-requireAuth';
        $data['title'] = __('Authentication Required', 'rrze-rsvp');
        $data['please_login'] = sprintf(__('<a href="%s">Please login with your IdM username</a>.', 'rrze-rsvp'), $loginUrl);

        add_filter('the_content', function ($content) use ($data) {
            return $this->template->getContent('auth/require-ldap-auth', $data);
        });
    }


    public function tryLogIn(){
        $roomId = isset($_GET['room_id']) ? absint($_GET['room_id']) : null;
        $room = $roomId ? sprintf('&room_id=%d', $roomId) : '';
        $seat = isset($_GET['seat_id']) ? sprintf('&seat_id=%d', absint($_GET['seat_id'])) : '';
        $bookingDate = isset($_GET['bookingdate']) ? sprintf('&bookingdate=%s', sanitize_text_field($_GET['bookingdate'])) : '';
        $timeslot = isset($_GET['timeslot']) ? sprintf('&timeslot=%s', sanitize_text_field($_GET['timeslot'])) : '';
        $nonce = isset($_GET['ldap-nonce']) ? sprintf('&ldap-nonce=%s', sanitize_text_field($_GET['ldap-nonce'])) : '';

        $bookingId = isset($_GET['id']) && !$roomId ? sprintf('&id=%s', absint($_GET['id'])) : '';
        $action = isset($_GET['action']) ? sprintf('&action=%s', sanitize_text_field($_GET['action'])) : '';

        if (!$this->isLoggedIn) {
            $authNonce = sprintf('?require-ldap-auth=%s', wp_create_nonce('require-ldap-auth'));
            $redirectUrl = sprintf('%s%s%s%s%s%s%s%s%s', trailingslashit(get_permalink()), $authNonce, $bookingId, $action, $room, $seat, $bookingDate, $timeslot, $nonce);
            header('HTTP/1.0 403 Forbidden');
            wp_redirect($redirectUrl);
            exit;
        }

        // $this->setAttributes();

        return true;
    }

    // public function setAttributes(){
    //     $this->personAttributes = $this->simplesamlAuth->getAttributes();

    //     $this->uid = isset($this->personAttributes['urn:mace:dir:attribute-def:uid'][0]) ? $this->personAttributes['urn:mace:dir:attribute-def:uid'][0] : null;
    //     $this->mail = isset($this->personAttributes['urn:mace:dir:attribute-def:mail'][0]) ? $this->personAttributes['urn:mace:dir:attribute-def:mail'][0] : null;
    //     $this->displayName = isset($this->personAttributes['urn:mace:dir:attribute-def:displayName'][0]) ? $this->personAttributes['urn:mace:dir:attribute-def:displayName'][0] : null;
    //     $this->eduPersonAffiliation = isset($this->personAttributes['urn:mace:dir:attribute-def:eduPersonAffiliation']) ? $this->personAttributes['urn:mace:dir:attribute-def:eduPersonAffiliation'] : null;
    //     $this->eduPersonEntitlement = isset($this->personAttributes['urn:mace:dir:attribute-def:eduPersonEntitlement']) ? $this->personAttributes['urn:mace:dir:attribute-def:eduPersonEntitlement'] : null;
    // }



    public function isAuthenticated(){
        return $this->isLoggedIn;

    }


    public function getLoginURL(){
        return 'URL-GETLOGINURL';
    }


    public function getCustomerData(){
        return [
            'customer_email' => $this->mail ? $this->mail : __('no@email', 'rrze-rsvp')
        ];
    }


}