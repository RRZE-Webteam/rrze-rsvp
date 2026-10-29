<?php

namespace RRZE\RSVP\Courses;

use RRZE\RSVP\Auth\IdM;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

use function RRZE\RSVP\plugin;

defined('ABSPATH') || exit;

/** Server-rendered course block and narrowly scoped public booking endpoints. */
class Frontend
{
    public const REST_NAMESPACE = 'rrze-rsvp/v1';
    public const NONCE_ACTION = 'rrze-rsvp-course-booking';

    private Repository $repository;
    private BookingService $service;
    private ?IdM $identityProvider = null;

    /**
     * Create a frontend adapter with injectable domain collaborators.
     *
     * @param Repository|null $repository Course readers.
     * @param BookingService|null $service Shared transactional booking service.
     */
    public function __construct(?Repository $repository = null, ?BookingService $service = null)
    {
        $this->repository = $repository ?? new Repository();
        $this->service = $service ?? new BookingService($this->repository);
    }

    /** @return void Register frontend, editor and REST hooks. */
    public function onLoaded(): void
    {
        add_action('init', [$this, 'registerBlock'], 20);
        add_action('rest_api_init', [$this, 'registerRoutes']);
        add_action('template_redirect', [$this, 'bypassCache']);
        add_filter('rrzecache_skip_cache', [$this, 'skipCache'], 20);
    }

    /** @return void Register independently bundled editor and frontend assets. */
    public function registerBlock(): void
    {
        wp_register_script('rrze-rsvp-course-block', plugins_url('build/course-block.js', plugin()->getFile()),
            ['wp-blocks', 'wp-block-editor', 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch'], $this->assetVersion('course-block.js'), true);
        wp_register_style('rrze-rsvp-course-block', plugins_url('build/course-block.css', plugin()->getFile()),
            [], $this->assetVersion('course-block.css'));
        wp_register_script('rrze-rsvp-course-frontend', plugins_url('build/course-frontend.js', plugin()->getFile()),
            [], $this->assetVersion('course-frontend.js'), true);
        wp_register_style('rrze-rsvp-course-frontend', plugins_url('build/course-frontend.css', plugin()->getFile()),
            [], $this->assetVersion('course-frontend.css'));

        wp_localize_script('rrze-rsvp-course-block', 'rrzeRsvpCourseEditor', [
            'labels' => [
                'title' => __('Course booking', 'rrze-rsvp'),
                'description' => __('Display a course and its individually bookable dates.', 'rrze-rsvp'),
                'select' => __('Select a course', 'rrze-rsvp'),
                'loading' => __('Loading courses…', 'rrze-rsvp'),
                'empty' => __('Create a course under RSVP → Courses first.', 'rrze-rsvp'),
                'preview' => __('The published page displays this course, its dates and the registration form.', 'rrze-rsvp'),
                'error' => __('Courses could not be loaded. Check your permissions and try again.', 'rrze-rsvp'),
                'draft' => __('Draft', 'rrze-rsvp'),
            ],
        ]);
        register_block_type(dirname(__DIR__, 2) . '/blocks/course-booking', [
            'render_callback' => [$this, 'render'],
            'editor_script' => 'rrze-rsvp-course-block',
            'editor_style' => 'rrze-rsvp-course-block',
        ]);
    }

    /** @return void Register safe readers and POST-only booking commands. */
    public function registerRoutes(): void
    {
        register_rest_route(self::REST_NAMESPACE, '/courses', [
            'methods' => 'GET', 'callback' => [$this, 'editorCourses'],
            'permission_callback' => [$this, 'editorPermission'],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/courses/(?P<id>\d+)/occurrences', [
            'methods' => 'GET', 'callback' => [$this, 'occurrences'],
            'permission_callback' => '__return_true',
            'args' => ['id' => ['type' => 'integer', 'minimum' => 1]],
        ]);
        register_rest_route(self::REST_NAMESPACE, '/course-bookings', [
            'methods' => 'POST', 'callback' => [$this, 'book'],
            'permission_callback' => [$this, 'bookingPermission'],
            'args' => [
                'occurrenceId' => ['required' => true, 'type' => 'integer', 'minimum' => 1],
                'requestId' => ['required' => true, 'type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{16,128}$'],
                'participant' => ['required' => true, 'type' => 'object'],
            ],
        ]);
        foreach (['verify', 'cancel'] as $action) {
            register_rest_route(self::REST_NAMESPACE, '/course-bookings/' . $action, [
                'methods' => 'POST', 'callback' => [$this, $action],
                'permission_callback' => [$this, 'tokenPermission'],
                'args' => [
                    'bookingId' => ['required' => true, 'type' => 'integer', 'minimum' => 1],
                    'token' => ['required' => true, 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                ],
            ]);
        }
    }

    /**
     * Authorize the private editor selector independently of public course reads.
     *
     * @return bool|WP_Error Permission decision.
     */
    public function editorPermission()
    {
        return current_user_can('edit_rsvp_courses') ? true
            : new WP_Error('course_forbidden', __('You cannot manage courses.', 'rrze-rsvp'), ['status' => 403]);
    }

    /**
     * Require a form nonce and reject explicitly cross-origin booking requests.
     *
     * @param WP_REST_Request $request Incoming registration request.
     * @return bool|WP_Error Permission decision.
     */
    public function bookingPermission(WP_REST_Request $request)
    {
        $origin = $this->tokenPermission($request);
        if (is_wp_error($origin)) {
            return $origin;
        }
        $nonce = $request->get_header('X-RSVP-Nonce');
        if (!$nonce || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return new WP_Error('course_invalid_nonce', __('Please reload the page before registering.', 'rrze-rsvp'), ['status' => 403]);
        }
        return true;
    }

    /**
     * Keep browser POSTs same-origin; a purpose-scoped token authorizes mail actions.
     *
     * @param WP_REST_Request $request Incoming POST request.
     * @return bool|WP_Error Origin decision; the service checks the secret itself.
     */
    public function tokenPermission(WP_REST_Request $request)
    {
        $origin = $request->get_header('Origin');
        if ($origin && !$this->sameOrigin($origin, rest_url())) {
            return new WP_Error('course_invalid_origin', __('This request must originate from this website.', 'rrze-rsvp'), ['status' => 403]);
        }
        return true;
    }

    /**
     * Return course labels for authorized block editors, including their drafts.
     *
     * @return WP_REST_Response Private selector data.
     */
    public function editorCourses(): WP_REST_Response
    {
        $courses = get_posts(['post_type' => Model::COURSE_TYPE, 'post_status' => ['publish', 'draft', 'pending', 'future', 'private'],
            'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']);
        $rows = [];
        foreach ($courses as $course) {
            if (current_user_can('edit_post', $course->ID)) {
                $rows[] = ['id' => (int) $course->ID, 'title' => $course->post_title, 'status' => $course->post_status];
            }
        }
        return $this->response($rows);
    }

    /**
     * Return published future occurrences using an explicit public-field allowlist.
     *
     * @param WP_REST_Request $request Request containing the course ID.
     * @return WP_REST_Response|WP_Error Safe course availability or a not-found error.
     */
    public function occurrences(WP_REST_Request $request)
    {
        $course = $this->repository->getCourse((int) $request->get_param('id'));
        if (is_wp_error($course) || $course['post_status'] !== 'publish') {
            return $this->notFound();
        }
        $rows = [];
        foreach ($this->repository->getOccurrences($course['id'], true) as $date) {
            if ($date['post_status'] !== 'publish' || $date['start'] <= time()) {
                continue;
            }
            $availability = $this->service->availability($date['id']);
            if (!is_wp_error($availability)) {
                $rows[] = $this->publicAvailability($availability);
            }
        }
        return $this->response(['courseId' => $course['id'], 'occurrences' => $rows]);
    }

    /**
     * Register a participant, replacing submitted identity with verified SSO data.
     *
     * @param WP_REST_Request $request Registration body.
     * @return WP_REST_Response|WP_Error Public booking result.
     */
    public function book(WP_REST_Request $request)
    {
        $date = $this->repository->getOccurrence((int) $request->get_param('occurrenceId'));
        if (is_wp_error($date)) {
            return $this->notFound();
        }
        $course = $this->repository->getCourse($date['course_id']);
        if (is_wp_error($course) || $course['post_status'] !== 'publish' || $date['post_status'] !== 'publish') {
            return $this->notFound();
        }
        $input = $request->get_param('participant');
        if (!is_array($input)) {
            return new WP_Error('course_invalid_participant', __('Please check your registration details.', 'rrze-rsvp'), ['status' => 422]);
        }
        $identity = $this->identity();
        $trusted = $identity['authenticated'];
        if ($course['settings']['access'] === 'sso' && !$trusted) {
            return new WP_Error('course_sso_required', $identity['available']
                ? __('Please sign in using SSO to book this course.', 'rrze-rsvp')
                : __('SSO is currently unavailable. Please contact the course organizer.', 'rrze-rsvp'), ['status' => 403]);
        }
        $data = $trusted ? $identity['participant'] : $input;
        foreach (['firstname', 'lastname', 'email'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field]) || strlen($data[$field]) > 254) {
                return new WP_Error('course_invalid_participant', __('Please check your registration details.', 'rrze-rsvp'), ['status' => 422]);
            }
        }
        if ($trusted && !is_email($data['email'])) {
            return new WP_Error('course_invalid_identity', __('Your SSO profile does not provide a valid email address. Please contact the course organizer.', 'rrze-rsvp'), ['status' => 403]);
        }
        $participant = ['firstname' => $data['firstname'], 'lastname' => $data['lastname'], 'email' => $data['email'],
            'consent' => ($input['consent'] ?? false) === true, 'locale' => determine_locale()];
        $result = $this->service->book($date['id'], $participant, (string) $request->get_param('requestId'), $trusted);
        return $this->bookingResponse($result);
    }

    /**
     * Verify an email token without accepting any caller-provided status.
     *
     * @param WP_REST_Request $request Booking ID and secret token.
     * @return WP_REST_Response|WP_Error Public verification result.
     */
    public function verify(WP_REST_Request $request)
    {
        return $this->bookingResponse($this->service->verify((int) $request->get_param('bookingId'), (string) $request->get_param('token')));
    }

    /**
     * Self-cancel through the token-only service path, never a manager path.
     *
     * @param WP_REST_Request $request Booking ID and cancellation token.
     * @return WP_REST_Response|WP_Error Public cancellation result.
     */
    public function cancel(WP_REST_Request $request)
    {
        return $this->bookingResponse($this->service->cancel((int) $request->get_param('bookingId'), 'customer', false, (string) $request->get_param('token')));
    }

    /**
     * Render a course with independently selectable, individually bookable dates.
     *
     * @param array $attributes Saved block attributes.
     * @return string Escaped public course markup.
     */
    public function render(array $attributes): string
    {
        $course = $this->repository->getCourse(absint($attributes['courseId'] ?? 0));
        if (is_wp_error($course) || $course['post_status'] !== 'publish') {
            return '<p class="rrze-rsvp-course-unavailable">' . esc_html__('This course is currently unavailable.', 'rrze-rsvp') . '</p>';
        }
        $this->noCache();
        wp_enqueue_script('rrze-rsvp-course-frontend');
        // Core button styles may be loaded separately from the block library.
        wp_enqueue_style('wp-block-buttons');
        wp_enqueue_style('wp-block-button');
        wp_enqueue_style('rrze-rsvp-course-frontend');
        wp_localize_script('rrze-rsvp-course-frontend', 'rrzeRsvpCourses', [
            'restUrl' => esc_url_raw(rest_url(self::REST_NAMESPACE . '/')),
            'nonce' => wp_create_nonce(self::NONCE_ACTION), 'restNonce' => wp_create_nonce('wp_rest'),
            'labels' => $this->labels(),
        ]);
        $identity = $this->identity();
        $requiresSso = $course['settings']['access'] === 'sso';
        $formAllowed = !$requiresSso || $identity['authenticated'];
        $allowedDomains = Model::normalizeEmailDomains($course['settings']['allowed_email_domains'] ?? []) ?? [];
        $uid = wp_unique_id('rsvp-course-');
        $dates = [];
        foreach ($this->repository->getOccurrences($course['id'], true) as $date) {
            if ($date['post_status'] === 'publish' && $date['start'] > time()) {
                $availability = $this->service->availability($date['id']);
                if (!is_wp_error($availability)) {
                    $dates[] = $this->publicAvailability($availability);
                }
            }
        }
        ob_start();
        ?>
        <section class="rrze-rsvp-course" aria-labelledby="<?php echo esc_attr($uid); ?>title" data-course-id="<?php echo esc_attr($course['id']); ?>">
            <h2 id="<?php echo esc_attr($uid); ?>title"><?php echo esc_html($course['title']); ?></h2>
            <div class="rrze-rsvp-course-description"><?php echo wpautop(wp_kses_post($course['description'])); ?></div>
            <?php if (!$dates) : ?>
                <p><?php esc_html_e('No upcoming course dates are currently available.', 'rrze-rsvp'); ?></p>
            <?php else : ?>
                <p><?php esc_html_e('Each date is booked separately. One registration reserves one place.', 'rrze-rsvp'); ?></p>
                <?php if ($allowedDomains) : ?>
                    <p class="rrze-rsvp-course-notice" id="<?php echo esc_attr($uid); ?>email-domains"><?php
                        /* translators: %s: Comma-separated email domains, each prefixed with @. */
                        echo esc_html(sprintf(__('Registration is only possible with an email address ending in: %s. This also applies when signing in with SSO.', 'rrze-rsvp'), '@' . implode(', @', $allowedDomains)));
                    ?></p>
                <?php endif; ?>
                <?php if ($requiresSso && !$identity['authenticated']) : ?>
                    <?php if ($identity['available']) : ?>
                        <p><a class="rrze-rsvp-course-login" href="<?php echo esc_url($this->loginUrl()); ?>"><?php esc_html_e('Sign in with SSO', 'rrze-rsvp'); ?></a></p>
                    <?php else : ?>
                        <p class="rrze-rsvp-course-notice"><?php esc_html_e('SSO is currently unavailable. Please contact the course organizer.', 'rrze-rsvp'); ?></p>
                    <?php endif; ?>
                <?php elseif ($identity['authenticated']) : ?>
                    <p><?php esc_html_e('You are signed in with SSO. Your verified profile will be used for this registration.', 'rrze-rsvp'); ?></p>
                <?php endif; ?>
                <form method="post" class="rrze-rsvp-course-form" data-course-id="<?php echo esc_attr($course['id']); ?>" data-trusted="<?php echo $identity['authenticated'] ? '1' : '0'; ?>" data-form-allowed="<?php echo $formAllowed ? '1' : '0'; ?>">
                    <fieldset>
                        <legend><?php esc_html_e('Choose a course date', 'rrze-rsvp'); ?></legend>
                        <div class="rrze-rsvp-course-dates">
                            <?php foreach ($dates as $date) : $radioId = $uid . 'date-' . $date['occurrence_id']; ?>
                                <label class="rrze-rsvp-course-date" for="<?php echo esc_attr($radioId); ?>" data-occurrence-id="<?php echo esc_attr($date['occurrence_id']); ?>">
                                    <input type="radio" name="occurrenceId" id="<?php echo esc_attr($radioId); ?>" value="<?php echo esc_attr($date['occurrence_id']); ?>" required <?php disabled(!$date['bookable'] || !$formAllowed); ?> aria-describedby="<?php echo esc_attr($radioId); ?>status">
                                    <span><strong><?php echo esc_html($date['label']); ?></strong><span class="rrze-rsvp-course-location"><?php echo esc_html($date['location']); ?></span><span class="rrze-rsvp-course-availability" id="<?php echo esc_attr($radioId); ?>status"><?php echo esc_html($date['message']); ?></span></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <?php if ($formAllowed) : ?>
                        <?php if (!$identity['authenticated']) : ?>
                            <fieldset class="rrze-rsvp-course-participant">
                                <legend><?php esc_html_e('Your registration details', 'rrze-rsvp'); ?></legend>
                                <?php if ($identity['available']) : ?>
                                    <p class="rrze-rsvp-course-login-option"><a class="rrze-rsvp-course-login" href="<?php echo esc_url($this->loginUrl()); ?>"><?php esc_html_e('Sign in with SSO', 'rrze-rsvp'); ?></a> — <?php esc_html_e('or register with your email address below.', 'rrze-rsvp'); ?></p>
                                <?php endif; ?>
                                <label for="<?php echo esc_attr($uid); ?>firstname"><?php esc_html_e('First name', 'rrze-rsvp'); ?><input id="<?php echo esc_attr($uid); ?>firstname" name="firstname" type="text" autocomplete="given-name" maxlength="254" required></label>
                                <label for="<?php echo esc_attr($uid); ?>lastname"><?php esc_html_e('Last name', 'rrze-rsvp'); ?><input id="<?php echo esc_attr($uid); ?>lastname" name="lastname" type="text" autocomplete="family-name" maxlength="254" required></label>
                                <label for="<?php echo esc_attr($uid); ?>email"><?php esc_html_e('Email address', 'rrze-rsvp'); ?><input id="<?php echo esc_attr($uid); ?>email" name="email" type="email" autocomplete="email" maxlength="254" required<?php if ($allowedDomains) : ?> aria-describedby="<?php echo esc_attr($uid); ?>email-domains"<?php endif; ?>></label>
                            </fieldset>
                            <p class="rrze-rsvp-course-hint"><?php esc_html_e('After registering, you will receive an email with a confirmation link. Confirm your email address within 60 minutes. If the course starts sooner, the deadline is the course start time. We will hold your place until then.', 'rrze-rsvp'); ?></p>
                        <?php endif; ?>
                        <label class="rrze-rsvp-course-consent" for="<?php echo esc_attr($uid); ?>consent"><input id="<?php echo esc_attr($uid); ?>consent" name="consent" type="checkbox" required> <span><?php esc_html_e('I agree to the processing of my details for this course registration.', 'rrze-rsvp'); ?><?php $privacyUrl = get_privacy_policy_url(); if ($privacyUrl) : ?> <a href="<?php echo esc_url($privacyUrl); ?>"><?php esc_html_e('Privacy policy', 'rrze-rsvp'); ?></a><?php endif; ?></span></label>
                        <p><?php echo esc_html($course['settings']['auto_confirm']
                            ? __('Participation is confirmed automatically once your identity has been verified.', 'rrze-rsvp')
                            : __('The course organizer will review and confirm your registration.', 'rrze-rsvp')); ?></p>
                        <div class="wp-block-buttons is-layout-flex">
                            <div class="wp-block-button">
                                <button class="wp-block-button__link wp-element-button rrze-rsvp-course-submit" type="submit" disabled><?php esc_html_e('Register for this date', 'rrze-rsvp'); ?></button>
                            </div>
                        </div>
                        <noscript><p><?php esc_html_e('Please enable JavaScript to use the course registration form.', 'rrze-rsvp'); ?></p></noscript>
                    <?php endif; ?>
                    <div class="rrze-rsvp-course-result" role="status" aria-live="polite" tabindex="-1"></div>
                </form>
            <?php endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    /** @return void Mark singular pages containing the course block uncacheable. */
    public function bypassCache(): void
    {
        if ($this->hasCourseBlock()) {
            $this->noCache();
        }
    }

    /**
     * Preserve existing cache exclusions while also excluding course block pages.
     *
     * @param bool $skip Earlier plugin cache decision.
     * @return bool Whether this response contains live booking state.
     */
    public function skipCache($skip = false): bool
    {
        return (bool) $skip || $this->hasCourseBlock();
    }

    /**
     * Read authentication only from the configured server-side IdM integration.
     *
     * @return array Authentication flags and a private participant identity.
     */
    protected function identity(): array
    {
        $this->identityProvider ??= new IdM();
        $authenticated = $this->identityProvider->isAuthenticated();
        $data = $authenticated ? $this->identityProvider->getCustomerData() : [];
        return ['authenticated' => $authenticated, 'available' => (bool) $this->identityProvider->simplesamlAuth,
            'participant' => ['firstname' => $data['customer_firstname'] ?? '', 'lastname' => $data['customer_lastname'] ?? '', 'email' => $data['customer_email'] ?? '']];
    }

    /**
     * Build an SSO login URL with a fixed local page as the return destination.
     *
     * @return string Provider-generated login link or an empty string.
     */
    private function loginUrl(): string
    {
        if (!$this->identityProvider || !$this->identityProvider->simplesamlAuth) {
            return '';
        }
        $returnTo = get_permalink(get_queried_object_id());
        if (!$returnTo || !$this->sameOrigin($returnTo, home_url('/'))) {
            $returnTo = home_url('/');
        }
        return (string) $this->identityProvider->simplesamlAuth->getLoginURL($returnTo);
    }

    /**
     * Construct a no-cache response containing only public booking result fields.
     *
     * @param array|WP_Error $result Domain result.
     * @return WP_REST_Response|WP_Error Public result with a translated next step.
     */
    private function bookingResponse($result)
    {
        if (is_wp_error($result)) {
            return $result;
        }
        $public = array_intersect_key($result, array_flip(['id', 'status', 'course_id', 'occurrence_id', 'expires_at', 'created']));
        $messages = [
            'booked' => __('Your place is reserved. Please confirm your email using the link we sent within 60 minutes and before the course starts.', 'rrze-rsvp'),
            'customer-confirmed' => __('Your registration has been received and is awaiting confirmation by the course organizer.', 'rrze-rsvp'),
            'confirmed' => __('Your participation is confirmed. You will receive the details by email.', 'rrze-rsvp'),
            'cancelled' => __('Your course registration has been cancelled.', 'rrze-rsvp'),
        ];
        $public['message'] = $messages[$public['status'] ?? ''] ?? __('Your registration has been updated.', 'rrze-rsvp');
        return $this->response($public, !empty($result['created']) ? 201 : 200);
    }

    /**
     * Whitelist availability fields and format UTC times in the website timezone.
     *
     * @param array $availability Service availability DTO.
     * @return array Public occurrence display data.
     */
    private function publicAvailability(array $availability): array
    {
        $public = array_intersect_key($availability, array_flip(['occurrence_id', 'course_id', 'capacity', 'free', 'bookable', 'reason', 'start', 'end', 'location']));
        $start = (int) $public['start'];
        $end = (int) $public['end'];
        $public['label'] = wp_date(get_option('date_format'), $start) . ', ' . wp_date(get_option('time_format'), $start)
            . ' – ' . (wp_date('Y-m-d', $start) === wp_date('Y-m-d', $end) ? '' : wp_date(get_option('date_format'), $end) . ', ')
            . wp_date(get_option('time_format'), $end);
        $messages = ['full' => __('Fully booked', 'rrze-rsvp'), 'closed' => __('Registration closed', 'rrze-rsvp'),
            'cancelled' => __('Course date cancelled', 'rrze-rsvp'), 'unpublished' => __('Currently unavailable', 'rrze-rsvp'),
            'unsupported' => __('Registration is currently unavailable.', 'rrze-rsvp')];
        $public['message'] = $public['reason'] === 'available'
            ? sprintf(_n('%d place available', '%d places available', (int) $public['free'], 'rrze-rsvp'), $public['free'])
            : ($messages[$public['reason']] ?? __('Currently unavailable', 'rrze-rsvp'));
        return $public;
    }

    /**
     * Set response headers to avoid stale capacity and authentication state.
     *
     * @param array $data JSON response body.
     * @param int $status HTTP response code.
     * @return WP_REST_Response Response object.
     */
    private function response(array $data, int $status = 200): WP_REST_Response
    {
        $response = new WP_REST_Response($data, $status);
        $response->header('Cache-Control', 'no-store, private');
        return $response;
    }

    /** @return WP_Error Uniform public not-found response without draft details. */
    private function notFound(): WP_Error
    {
        return new WP_Error('course_not_found', __('Course or date not found.', 'rrze-rsvp'), ['status' => 404]);
    }

    /**
     * Compare canonical URL origins including scheme and effective port.
     *
     * @param string $left Submitted origin or local return URL.
     * @param string $right Trusted site URL.
     * @return bool Whether both URLs share an HTTP origin.
     */
    protected function sameOrigin(string $left, string $right): bool
    {
        $a = wp_parse_url($left);
        $b = wp_parse_url($right);
        return is_array($a) && is_array($b) && isset($a['scheme'], $a['host'], $b['scheme'], $b['host'])
            && strtolower($a['scheme']) === strtolower($b['scheme']) && strtolower($a['host']) === strtolower($b['host'])
            && ($a['port'] ?? ($a['scheme'] === 'https' ? 443 : 80)) === ($b['port'] ?? ($b['scheme'] === 'https' ? 443 : 80));
    }

    /** @return bool Whether the queried post directly contains a course block. */
    private function hasCourseBlock(): bool
    {
        $post = get_queried_object();
        return $post instanceof \WP_Post && has_block('rrze-rsvp/course-booking', $post);
    }

    /** @return void Prevent page caches from storing live course forms. */
    private function noCache(): void
    {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (!headers_sent()) {
            nocache_headers();
        }
    }

    /**
     * Version a generated asset independently of the plugin release number.
     *
     * @param string $file Filename within build/.
     * @return string Cache-busting version.
     */
    private function assetVersion(string $file): string
    {
        $path = dirname(__DIR__, 2) . '/build/' . $file;
        return plugin()->getVersion() . '.' . (is_file($path) ? filemtime($path) : '0');
    }

    /** @return array Translated frontend interaction messages. */
    private function labels(): array
    {
        return ['submitting' => __('Registering…', 'rrze-rsvp'), 'submit' => __('Register for this date', 'rrze-rsvp'),
            'success' => __('Your registration has been received.', 'rrze-rsvp'),
            'networkError' => __('The request could not be completed. Please try again; an existing registration will not be duplicated.', 'rrze-rsvp'),
            'retryDetails' => __('A previous registration may already have been saved. Enter the same details and submit again to retrieve its result.', 'rrze-rsvp'),
            'chooseDate' => __('Please choose an available course date.', 'rrze-rsvp'),
            'unavailable' => __('The selected date is no longer available. Please choose another date.', 'rrze-rsvp'),
            'refreshError' => __('Availability could not be refreshed. It will be checked again when you register.', 'rrze-rsvp')];
    }
}
