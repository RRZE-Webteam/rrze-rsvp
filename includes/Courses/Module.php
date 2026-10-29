<?php

namespace RRZE\RSVP\Courses;

use RRZE\RSVP\Users;
use RRZE\RSVP\Capabilities;

defined('ABSPATH') || exit;

/**
 * Integrate independent course bookings with the existing RSVP installation.
 */
final class Module
{
    private const VERSION = 1;
    private const VERSION_OPTION = 'rrze_rsvp_courses_version';

    /**
     * Register course modules and per-site capability upgrades.
     *
     * @return void
     */
    public function onLoaded(): void
    {
        add_action('init', [PostTypes::class, 'register'], 11);
        add_action('init', [$this, 'upgradeRoles'], 20);
        add_action('wp_initialize_site', [$this, 'initializeSite'], 200);
        add_action('admin_menu', [$this, 'menu'], 20);
        add_filter('parent_file', [$this, 'parentMenu'], 20);
        add_action('template_redirect', [$this, 'preventPublicCache'], 0);
        (new Guards())->onLoaded();
        (new Admin())->onLoaded();
        (new Frontend())->onLoaded();
        (new Notifications())->onLoaded();
        (new Lifecycle())->onLoaded();
        (new ActionPage())->onLoaded();
    }

    /**
     * Upgrade site capabilities and repair roles recreated after reactivation.
     *
     * @return void
     */
    public function upgradeRoles(): void
    {
        $type = get_post_type_object(Model::COURSE_TYPE);
        if (!$type) {
            return;
        }

        Users::createBookingRole();
        // Network-activated plugins do not receive a per-site activation hook.
        $capabilities = array_merge(array_values((array) Capabilities::getCptCaps('booking')), array_values((array) $type->cap));
        foreach (['administrator', 'editor', Users::BOOKING_ROLE] as $roleName) {
            $role = get_role($roleName);
            if (!$role) {
                continue;
            }
            foreach ($capabilities as $capability) {
                if ($capability === 'read_customer_phone' && $roleName !== 'administrator') {
                    continue;
                }
                if ($capability !== 'do_not_allow' && !$role->has_cap($capability)) {
                    $role->add_cap($capability);
                }
            }
        }
        if ((int) get_option(self::VERSION_OPTION, 0) < self::VERSION) {
            update_option(self::VERSION_OPTION, self::VERSION, false);
        }
    }

    /**
     * Initialize capabilities after WordPress has created a new multisite blog.
     *
     * @param \WP_Site $site Newly initialized site.
     * @return void
     */
    public function initializeSite($site): void
    {
        switch_to_blog((int) $site->blog_id);
        try {
            $this->upgradeRoles();
        } finally {
            restore_current_blog();
        }
    }

    /**
     * Add course management beneath the untranslated RSVP parent menu.
     *
     * @return void
     */
    public function menu(): void
    {
        add_submenu_page(
            'edit.php?post_type=booking',
            __('Courses', 'rrze-rsvp'),
            __('Courses', 'rrze-rsvp'),
            'edit_rsvp_courses',
            'edit.php?post_type=' . Model::COURSE_TYPE
        );
    }

    /**
     * Keep the RSVP menu highlighted while editing a course.
     *
     * @param string $parent Existing parent menu slug.
     * @return string Parent menu slug for the current screen.
     */
    public function parentMenu($parent): string
    {
        $screen = get_current_screen();
        if ($screen && $screen->post_type === Model::COURSE_TYPE) {
            global $submenu_file;
            $submenu_file = 'edit.php?post_type=' . Model::COURSE_TYPE;
            return 'edit.php?post_type=booking';
        }
        return (string) $parent;
    }

    /**
     * Prevent caching personalized form nonces, availability and token pages.
     *
     * @return void
     */
    public function preventPublicCache(): void
    {
        if (!has_block('rrze-rsvp/course-booking') && !is_page('rsvp-course-booking')) {
            return;
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        nocache_headers();
    }
}
