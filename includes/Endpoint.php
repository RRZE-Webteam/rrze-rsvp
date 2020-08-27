<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

class Endpoint
{
    /**
     * Name of the endpoint.
     * @var string
     */
    protected $name;

    public function __construct(string $name)
    {
        $this->name = sanitize_title($name);
    }

    public function onLoaded()
    {
        add_action('init', [$this, 'addEndpoint']);
    }

    public function addEndpoint()
    {
        add_rewrite_endpoint($this->name, EP_PERMALINK | EP_PAGES);
    }

    public function isEndpoint()
    {
        global $wp_query;
        return isset($wp_query->query_vars[$this->name]);
    }

    public function endpointUrl($query = '')
    {
        return site_url($this->name . '/' . $query);
    }

    public function getEndpointName()
    {
        return $this->name;
    }
}
