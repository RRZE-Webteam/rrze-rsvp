<?php

/** Verify capability repair on reactivation and isolated multisite initialization. */
declare(strict_types=1);

namespace RRZE\RSVP {
    /** Existing role creation contract, without accessing a real installation. */
    final class Users
    {
        public const BOOKING_ROLE = 'booking';
        /** @return void Recreate only the legacy role when it is missing. */
        public static function createBookingRole(): void
        {
            $site = $GLOBALS['moduleSite'];
            $GLOBALS['moduleRoles'][$site]['booking'] ??= new \ModuleTestRole();
        }
    }
    /** Minimal legacy booking capability map. */
    final class Capabilities
    {
        /** @param string $type Post type. @return object Booking primitive capabilities. */
        public static function getCptCaps(string $type): object
        {
            return (object) ['edit_posts' => 'edit_bookings', 'edit_others_posts' => 'edit_others_bookings', 'read_customer_phone' => 'read_customer_phone'];
        }
    }
}

namespace {
    define('ABSPATH', dirname(__DIR__) . '/');
    $moduleSite = 1;
    $moduleStack = [];
    $moduleOptions = [];
    $moduleRoles = [];

    /** In-memory WordPress role with observable writes. */
    final class ModuleTestRole
    {
        public array $caps = [];
        public int $writes = 0;
        /** @param string $cap Capability. @return bool Whether granted. */
        public function has_cap(string $cap): bool { return !empty($this->caps[$cap]); }
        /** @param string $cap Capability. @return void Grant once. */
        public function add_cap(string $cap): void { $this->caps[$cap] = true; $this->writes++; }
    }
    /** @param string $type Post type. @return object Registered course capabilities. */
    function get_post_type_object(string $type): object
    {
        return (object) ['cap' => (object) ['edit_posts' => 'edit_rsvp_courses', 'edit_others_posts' => 'edit_others_rsvp_courses', 'create_posts' => 'edit_rsvp_courses']];
    }
    /** @param string $name Role. @return ModuleTestRole|null Site-local role. */
    function get_role(string $name): ?ModuleTestRole { return $GLOBALS['moduleRoles'][$GLOBALS['moduleSite']][$name] ?? null; }
    /** @param string $name Option. @param mixed $default Default. @return mixed Site-local value. */
    function get_option(string $name, $default = false) { return $GLOBALS['moduleOptions'][$GLOBALS['moduleSite']][$name] ?? $default; }
    /** @param string $name Option. @param mixed $value Value. @param bool $autoload Autoload. @return void */
    function update_option(string $name, $value, bool $autoload): void { $GLOBALS['moduleOptions'][$GLOBALS['moduleSite']][$name] = $value; }
    /** @param int $site New site. @return void Preserve caller context. */
    function switch_to_blog(int $site): void { $GLOBALS['moduleStack'][] = $GLOBALS['moduleSite']; $GLOBALS['moduleSite'] = $site; }
    /** @return void Restore caller context. */
    function restore_current_blog(): void { $GLOBALS['moduleSite'] = array_pop($GLOBALS['moduleStack']); }
    /** @param bool $condition Expected invariant. @param string $message Failure detail. @return void */
    function moduleAssert(bool $condition, string $message): void { if (!$condition) { throw new \RuntimeException($message); } }

    require_once dirname(__DIR__) . '/includes/Courses/Model.php';
    require_once dirname(__DIR__) . '/includes/Courses/Module.php';
    foreach ([1, 2] as $site) {
        $moduleRoles[$site] = ['administrator' => new ModuleTestRole(), 'editor' => new ModuleTestRole()];
    }
    $module = new \RRZE\RSVP\Courses\Module();
    $module->upgradeRoles();
    foreach ($moduleRoles[1] as $role) {
        moduleAssert($role->has_cap('edit_rsvp_courses') && $role->has_cap('edit_bookings'), 'Existing roles need course and booking rights.');
    }
    moduleAssert(!get_role('editor')->has_cap('read_customer_phone'), 'Course upgrade must preserve the legacy phone restriction.');
    $writes = get_role('editor')->writes;
    $module->upgradeRoles();
    moduleAssert(get_role('editor')->writes === $writes, 'Normal initialization must not rewrite existing capabilities.');
    unset($moduleRoles[1]['booking']);
    $module->upgradeRoles();
    moduleAssert(get_role('booking')->has_cap('edit_rsvp_courses'), 'Recreated booking role needs course rights even at the current version.');
    $module->initializeSite((object) ['blog_id' => 2]);
    moduleAssert($moduleSite === 1, 'New-site initialization must restore the current website.');
    moduleAssert($moduleRoles[2]['editor']->has_cap('edit_bookings') && $moduleRoles[2]['administrator']->has_cap('edit_rsvp_courses'), 'Network activation must equip new-site editors and administrators.');
    moduleAssert(($moduleOptions[2]['rrze_rsvp_courses_version'] ?? 0) === 1, 'Upgrade version must be local to the new website.');
    echo "Course capability repair and multisite initialization tests passed.\n";
}
