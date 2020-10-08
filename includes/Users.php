<?php

namespace RRZE\RSVP;

defined('ABSPATH') || exit;

use WP_Roles;

class Users
{
    const BOOKING_ROLE = 'booking';

    protected static function getRolesArgs()
    {
        return [
            'administrator' => [
                'cpts' => array_keys(Capabilities::getCurrentCptArgs()),
                'exceptions' => []
            ],
            'editor' => [
                'cpts' => array_keys(Capabilities::getCurrentCptArgs()),
                'exceptions' => ['read_customer_phone']
            ]
        ];
    }

    public static function addRoleCaps()
    {
        foreach (self::getRolesArgs() as $role => $args) {
            foreach ($args['cpts'] as $cpt) {
                self::addRoleCptCaps($role, $cpt, $args['exceptions']);
            }
        }
    }

    public static function removeRoleCaps()
    {
        foreach (self::getRolesArgs() as $role => $args) {
            foreach ($args['cpts'] as $cpt) {
                self::removeRoleCptCaps($role, $cpt);
            }
        }
    }

    protected static function addRoleCptCaps(string $role, string $cpt, array $exceptions = [])
    {
        $roleObj = get_role($role);
        if (is_null($roleObj)) {
            return;
        }

        $capsObj = Capabilities::getCptCaps($cpt);
        foreach ($capsObj as $key => $cap) {
            if (!$roleObj->has_cap($cap) && !in_array($key, $exceptions)) {
                $roleObj->add_cap($cap);
            }
        }
    }

    protected static function removeRoleCptCaps(string $role, string $cpt)
    {
        $roleObj = get_role($role);
        if (is_null($roleObj)) {
            return;
        }

        $capsObj = Capabilities::getCptCaps($cpt);
        foreach ($capsObj as $cap) {
            if ($cap != 'read' && $roleObj->has_cap($cap)) {
                $roleObj->remove_cap($cap);
            }
        }
    }

    public static function createBookingRole()
    {
        $roleObj = get_role(static::BOOKING_ROLE);
        if (!is_null($roleObj)) {
            return;
        }

        add_role(static::BOOKING_ROLE, __('Booking Agent', 'rrze-rsvp'), ['read' => true, 'level_0' => true]);

        $currentCpts = array_keys(Capabilities::getCurrentCptArgs());

        foreach ($currentCpts as $cpt) {
            self::addRoleCptCaps(static::BOOKING_ROLE, $cpt, ['read_customer_phone']);
        }
    }

    public static function removeBookingRole()
    {
        remove_role(static::BOOKING_ROLE);
    }
}
