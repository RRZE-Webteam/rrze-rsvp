<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use RRZE\RSVP\Settings;

class LDAP {
    protected $settings;
    protected $template;
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
        $this->template = new Template;
        $this->server = $this->settings->getOption('ldap', 'server');
        $this->port = $this->settings->getOption('ldap', 'port');
        $this->distinguished_name = $this->settings->getOption('ldap', 'distinguished_name');
        $this->bind_base_dn = $this->settings->getOption('ldap', 'bind_base_dn');
        $this->search_base_dn = $this->settings->getOption('ldap', 'search_base_dn');
    }

    public function onLoaded() {
        add_action('wp', [$this, 'requireAuth']);
    }
    
    private function logError(string $method): string{
        $msg = 'LDAP-error ' . ldap_errno($this->link_identifier) . ' ' . ldap_error($this->link_identifier) . " using $method | server = {$this->server}:{$this->port}";
        // Helper::debugLog(__FILE__, __LINE__, __METHOD__, $msg);
        do_action('rrze.log.error', 'rrze-rsvp : ' . $msg);
        return $msg;
    }
    

    // returns true/false if logged in via LDAP and sets $this->mail fetched from LDAP
    public function getEmail(){
    // public function isAuthenticated(){
            // return true;

        $error = false;

        // if ($this->isLoggedIn){
        //     return true;
        // }

        $username = filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING);
        $username = ( $username ? $username : filter_input(INPUT_GET, 'username', FILTER_SANITIZE_STRING));
        $password = filter_input(INPUT_POST, 'password', FILTER_SANITIZE_STRING);
        $password = ( $password ? $password : filter_input(INPUT_GET, 'password', FILTER_SANITIZE_STRING));

        // BK 2DO !!!
        // $username = ( isset($_REQUEST['username']) ? $_REQUEST['username'] : '');
        // $password = ( isset($_REQUEST['password']) ? $_REQUEST['password'] : '');

        if ($username && $password){

            // echo 'user pass given';
            // exit;

            $this->link_identifier = @ldap_connect($this->server, $this->port);

            // echo '1';
            // exit;

            if (!$this->link_identifier){
                $error = $this->logError('ldap_connect()');
                // echo '2';
                // exit;
            }else{
                ldap_set_option($this->link_identifier, LDAP_OPT_PROTOCOL_VERSION, 3);
                ldap_set_option($this->link_identifier, LDAP_OPT_REFERRALS, 0);
            
                $bind = @ldap_bind($this->link_identifier, $username . '@' . $this->bind_base_dn, $password);

                // echo '3';
                // exit;
    
                if (!$bind) {
                    $error = $this->logError('ldap_bind()');
                    // echo '4';
                    // exit;
        
                }else{
                    // echo '5';
                    // exit;
        
                    $this->search_filter = '(sAMAccountName=' . $username . ')';
                    $result_identifier = @ldap_search($this->link_identifier, $this->search_base_dn, $this->search_filter);                    

                    if ($result_identifier === false){
                        $error = $this->logError('ldap_search()');
                        // echo '6';
                        // exit;
            
                    }else{
                        // echo '7';
                        // exit;
            
                        $aEntry = @ldap_get_entries($this->link_identifier, $result_identifier);

                        if (isset($aEntry['count']) && $aEntry['count'] > 0){

                            if (isset($aEntry[0]['cn'][0]) && isset($aEntry[0]['mail'][0])){
                                $this->mail = $aEntry[0]['mail'][0]; 
                                $this->isLoggedIn = true;

                                // var_dump($_REQUEST);
                                // echo '$username = ' . $username;
                                // echo '<br>$password = ' . $password;
                                // exit;
                        
                        
                                // $redirectUrl = esc_url(wp_nonce_url(trailingslashit(get_permalink()), 'do_something', 'my_nonce'));
                                // wp_redirect($redirectUrl);
                                // exit; 

                                return $aEntry[0]['mail'][0];
                        
                            }else{
                                $error = $this->logError('ldap_get_entries() : Attributes have changed. Expected $aEntry[0][\'cn\'][0] and $aEntry[0][\'mail\'][0]');
                            }
                        }else{
                            return 'not found';
                            // exit;
                    
                            // return false; // User not found
                        }
                        @ldap_close($this->connection);
                    }
                }
            }
        // }else{
        //     echo 'here';
        //     exit;
        }

        // echo 'done' . $this->mail;
        // exit;
        

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
        // Helper::debugLog(__FILE__, __LINE__, __METHOD__, '$nonce=' . $nonce);

        if (!wp_verify_nonce($nonce, 'require-ldap-auth')) {
            header('HTTP/1.0 403 Forbidden');
            wp_redirect(get_site_url());
            exit;            
        }
        // Helper::debugLog(__FILE__, __LINE__, __METHOD__, 'verified $nonce=' . $nonce);

        $roomId = isset($_GET['room_id']) ? absint($_GET['room_id']) : null;
        $room = $roomId ? sprintf('?room_id=%d', $roomId) : '';
        $seat = isset($_GET['seat_id']) ? sprintf('&seat_id=%d', absint($_GET['seat_id'])) : '';
        $bookingDate = isset($_GET['bookingdate']) ? sprintf('&bookingdate=%s', sanitize_text_field($_GET['bookingdate'])) : '';
        $timeslot = isset($_GET['timeslot']) ? sprintf('&timeslot=%s', sanitize_text_field($_GET['timeslot'])) : '';
        $nonce = isset($_GET['nonce']) ? sprintf('&nonce=%s', sanitize_text_field($_GET['nonce'])) : '';
        
        $bookingId = isset($_GET['id']) && !$roomId ? sprintf('?id=%s', absint($_GET['id'])) : '';
        $action = isset($_GET['action']) ? sprintf('&action=%s', sanitize_text_field($_GET['action'])) : '';        

        if ($this->isLoggedIn) {
            
            $redirectUrl = sprintf('%s%s%s%s%s%s%s%s', trailingslashit(get_permalink()), $bookingId, $action, $room, $seat, $bookingDate, $timeslot, $nonce);
            // Helper::debugLog(__FILE__, __LINE__, __METHOD__, 'isLoggedIn $redirectUrl=' . $redirectUrl);
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
        $data['title'] = __('Authentication Required', 'rrze-rsvp');
        $data['please_login'] = __('<a href="%s">Please login with your LDAP username</a>.', 'rrze-rsvp');

        add_filter('the_content', function ($content) use ($data) {
            return $this->template->getContent('auth/require-ldap-auth', $data);
        });
        // Helper::debugLog(__FILE__, __LINE__, __METHOD__, 'filter added =' . $nonce);
    }


    public function tryLogIn($test = ''){
        $roomId = isset($_GET['room_id']) ? absint($_GET['room_id']) : null;
        $room = $roomId ? sprintf('&room_id=%d', $roomId) : '';
        $seat = isset($_GET['seat_id']) ? sprintf('&seat_id=%d', absint($_GET['seat_id'])) : '';
        $bookingDate = isset($_GET['bookingdate']) ? sprintf('&bookingdate=%s', sanitize_text_field($_GET['bookingdate'])) : '';
        $timeslot = isset($_GET['timeslot']) ? sprintf('&timeslot=%s', sanitize_text_field($_GET['timeslot'])) : '';
        $nonce = isset($_GET['nonce']) ? sprintf('&nonce=%s', sanitize_text_field($_GET['nonce'])) : '';

        $bookingId = isset($_GET['id']) && !$roomId ? sprintf('&id=%s', absint($_GET['id'])) : '';
        $action = isset($_GET['action']) ? sprintf('&action=%s', sanitize_text_field($_GET['action'])) : '';

    
        if (!$this->isLoggedIn) {
            $authNonce = sprintf('?require-ldap-auth=%s', wp_create_nonce('require-ldap-auth'));
            $redirectUrl = sprintf('%s%s%s%s%s%s%s%s%s', trailingslashit(get_permalink()), $authNonce, $bookingId, $action, $room, $seat, $bookingDate, $timeslot, $nonce);
            // var_dump($redirectUrl);
            // echo '<br><br>try url';
            // exit;
            header('HTTP/1.0 403 Forbidden');
            wp_redirect($redirectUrl);
            exit;
        }

        return true;
    }


    public function getCustomerData(){
        return [
            'customer_email' => $this->mail ? $this->mail : __('no@email LDAP', 'rrze-rsvp')
        ];
    }


}