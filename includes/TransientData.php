<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

/**
 * TransientData class
 * Transient data will only be available for the next request, and is then automatically cleared.
 * This can be very useful, especially for one-time info, error or status data. 
 */
class TransientData
{
    /**
     * transient
     * @var string
     */
    public $transient = '';

    /**
     * transientExpiration
     * @var integer
     */
    protected $transientExpiration = 30;

    public function __construct(string $transient)
    {
        $this->transient = $transient;
    }

    public function getTransient(): string
    {
        return $this->transient;
    }

    public function addData(string $key, $message)
    {
        $transientValue = get_transient($this->transient);
        $data = maybe_unserialize($transientValue ? $transientValue : []);
        $data[$key] = $message;

        set_transient($this->transient, $data, $this->transientExpiration);
    }

    public function getData(bool $delete = true)
    {
        $transientValue = get_transient($this->transient);
        $data = maybe_unserialize($transientValue ? $transientValue : []);

        if ($delete) {
            delete_transient($this->transient);
        }
        return $data;
    }
}
