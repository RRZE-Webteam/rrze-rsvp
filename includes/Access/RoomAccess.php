<?php

declare(strict_types=1);

namespace RRZE\RSVP\Access;

use WP_Error;

defined('ABSPATH') || exit;

/** Stores encrypted room lists and evaluates verified identities against them. */
final class RoomAccess
{
    public const META_KEY = '_rrze_rsvp_room_access';
    public const CONTEXT_OPTION = 'rrze_rsvp_room_access_context';

    /** @return bool Whether room-access management is enabled on the current site. */
    public static function isEnabled(): bool
    {
        $options = get_option('rrze_rsvp', []);
        return is_array($options) && ($options['general_room_access_enabled'] ?? 'off') === 'on';
    }

    /**
     * Reads normalized addresses without exposing them through public metadata APIs.
     *
     * @param int $roomId Room ID on the current site.
     * @return list<string>|WP_Error Empty only for an absent or authenticated empty list.
     */
    public static function read(int $roomId): array|WP_Error
    {
        if (!self::isRoom($roomId)) {
            return self::invalidRoom();
        }
        try {
            return self::decode($roomId, self::raw($roomId));
        } catch (\Throwable) {
            return self::unavailable();
        }
    }

    /**
     * Replaces a room list after validation; callers must enforce admin capability and nonce.
     *
     * Existing unreadable data is never overwritten, including requests to clear it.
     * The previous ciphertext is used as an optimistic compare-and-swap predicate.
     *
     * @param int $roomId Room ID on the current site.
     * @param list<string> $emails Complete desired list; empty removes the restriction.
     * @return true|WP_Error Result without personal data in failure messages.
     */
    public static function save(int $roomId, #[\SensitiveParameter] array $emails): true|WP_Error
    {
        if (!self::isRoom($roomId)) {
            return self::invalidRoom();
        }
        $normalized = self::normalize($emails);
        if ($normalized === null) {
            return new WP_Error('room_access_invalid_emails', __('Enter valid email addresses. No changes were saved.', 'rrze-rsvp'), ['status' => 422]);
        }
        try {
            $previous = self::raw($roomId);
            self::decode($roomId, $previous);
            if ($normalized && !self::loginSources($roomId)) {
                return self::loginRequired(422);
            }
            if (!$normalized) {
                if ($previous !== null) {
                    delete_post_meta($roomId, self::META_KEY, $previous);
                }
                return self::raw($roomId) === null ? true : self::saveFailed();
            }
            // Validate the selected key before creating even a non-secret context option.
            EncryptionKey::forWriting();
            $cipher = new RoomAccessCipher(self::context(true), $roomId);
            $encrypted = $cipher->encrypt($normalized);
            if ($cipher->decrypt($encrypted) !== $normalized) {
                return self::unavailable();
            }
            if ($previous === null) {
                add_post_meta($roomId, self::META_KEY, wp_slash($encrypted), true);
            } else {
                update_post_meta($roomId, self::META_KEY, wp_slash($encrypted), $previous);
            }
            return self::raw($roomId) === $encrypted ? true : self::saveFailed();
        } catch (\Throwable) {
            return self::unavailable();
        }
    }

    /**
     * Authorizes a server-verified identity immediately before creating a room booking.
     *
     * Never pass a posted email or a caller-selected authentication status here.
     * A disabled feature or absent/empty list leaves existing booking rules unchanged.
     * Callers enforce the room's existing login requirements separately; an active
     * SSO identity retains priority when the caller also has an active LDAP session.
     *
     * @param int $roomId Room being booked on the current site.
     * @param array{source: string, email: string}|null $identity Verified SSO/LDAP identity.
     * @return true|WP_Error Authorization result, failing closed on unreadable lists.
     */
    public static function authorize(int $roomId, #[\SensitiveParameter] ?array $identity): true|WP_Error
    {
        if (!self::isEnabled()) {
            return true;
        }
        $emails = self::read($roomId);
        if ($emails instanceof WP_Error || !$emails) {
            return $emails instanceof WP_Error ? $emails : true;
        }
        $sources = self::loginSources($roomId);
        if (!$sources) {
            return self::loginRequired(403);
        }
        $email = is_string($identity['email'] ?? null) ? strtolower(trim($identity['email'])) : '';
        if (!in_array($identity['source'] ?? null, ['sso', 'ldap'], true) || !is_email($email)) {
            return new WP_Error('room_access_identity_required', __('Sign in with the required SSO or LDAP account to book this room.', 'rrze-rsvp'), ['status' => 403]);
        }
        if (!in_array($email, $emails, true)) {
            return new WP_Error('room_access_denied', __('Your account is not authorized to book this room.', 'rrze-rsvp'), ['status' => 403]);
        }
        return true;
    }

    /**
     * Reads exactly one raw encrypted value, detecting ambiguous duplicate records.
     *
     * @param int $roomId Existing room ID.
     * @return string|null Ciphertext or null when metadata is genuinely absent.
     * @throws \RuntimeException When metadata has an invalid shape.
     */
    private static function raw(int $roomId): ?string
    {
        global $wpdb;

        // A final authorization must observe a list changed by another request,
        // even if an earlier form/booking check populated this request's cache.
        wp_cache_delete($roomId, 'post_meta');
        $values = get_post_meta($roomId, self::META_KEY, false);
        if (isset($wpdb) && ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException('Room-access storage could not be read.');
        }
        if ($values === []) {
            return null;
        }
        if (!is_array($values) || count($values) !== 1 || !is_string($values[0])) {
            throw new \RuntimeException('Invalid room-access metadata.');
        }
        return $values[0];
    }

    /**
     * Authenticates a stored list and validates its normalized email schema.
     *
     * @param int $roomId Expected room ID.
     * @param string|null $raw Observed ciphertext or absent metadata.
     * @return list<string> Authenticated addresses.
     * @throws \RuntimeException On unreadable, malformed or noncanonical stored data.
     */
    private static function decode(int $roomId, ?string $raw): array
    {
        if ($raw === null) {
            return [];
        }
        $emails = (new RoomAccessCipher(self::context(false), $roomId))->decrypt($raw);
        $normalized = self::normalize($emails);
        if ($normalized === null || $emails !== $normalized) {
            throw new \RuntimeException('Invalid room-access email list.');
        }
        return $emails;
    }

    /**
     * Loads the site's stable random context; only an explicit first write may create it.
     *
     * @param bool $create Whether creation is allowed for new encrypted data.
     * @return string Valid persistent non-secret context.
     * @throws \RuntimeException When the context is absent, malformed or cannot be saved.
     */
    private static function context(bool $create): string
    {
        $context = get_option(self::CONTEXT_OPTION, null);
        if ($context === null && $create) {
            add_option(self::CONTEXT_OPTION, bin2hex(random_bytes(16)), '', false);
            $context = get_option(self::CONTEXT_OPTION, null);
        }
        if (!is_string($context) || !preg_match('/\A[a-f0-9]{32}\z/', $context)) {
            throw new \RuntimeException('The room-access site context is unavailable.');
        }
        return $context;
    }

    /**
     * Validates every address before deduplicating and sorting the whole list.
     *
     * @param array<mixed> $emails Submitted or authenticated stored values.
     * @return list<string>|null Canonical list, or null for any invalid input.
     */
    private static function normalize(#[\SensitiveParameter] array $emails): ?array
    {
        if (!array_is_list($emails)) {
            return null;
        }
        $normalized = [];
        foreach ($emails as $email) {
            if (!is_string($email)) {
                return null;
            }
            $email = strtolower(trim($email));
            if (strlen($email) > 254 || !is_email($email)) {
                return null;
            }
            $normalized[] = $email;
        }
        $normalized = array_values(array_unique($normalized));
        sort($normalized, SORT_STRING);
        return $normalized;
    }

    /**
     * Resolves current trusted login choices without creating any authentication session.
     *
     * @param int $roomId Existing room ID.
     * @return list<string> Enabled trusted sources for this room.
     */
    private static function loginSources(int $roomId): array
    {
        $sources = [];
        if (self::enabledMeta(get_post_meta($roomId, 'rrze-rsvp-room-sso-required', true))) {
            $sources[] = 'sso';
        }
        $options = get_option('rrze_rsvp', []);
        if (self::enabledMeta(get_post_meta($roomId, 'rrze-rsvp-room-ldap-required', true))
            && is_array($options) && is_string($options['ldap_server'] ?? null)
            && trim($options['ldap_server']) !== '') {
            $sources[] = 'ldap';
        }
        return $sources;
    }

    /**
     * Interprets the existing room checkbox values consistently with booking settings.
     *
     * @param mixed $value Stored checkbox value.
     * @return bool Whether the flag is enabled.
     */
    private static function enabledMeta(mixed $value): bool
    {
        if (!is_scalar($value) && $value !== null) {
            return false;
        }
        $normalized = preg_replace('/[^a-z0-9]/', '', strtolower((string) $value));
        return in_array($normalized, ['1', 'on', 'true', 'wahr', 'aktiv', 'show', 'yes'], true);
    }

    /**
     * Checks the room in the current site's post table, rejecting removed records.
     *
     * @param int $roomId Proposed room ID.
     * @return bool Whether it is an existing room outside the trash.
     */
    private static function isRoom(int $roomId): bool
    {
        $room = $roomId > 0 ? get_post($roomId) : null;
        return $room && $room->post_type === 'room' && $room->post_status !== 'trash';
    }

    /** @return WP_Error Safe error for a nonexistent or wrong-type room. */
    private static function invalidRoom(): WP_Error
    {
        return new WP_Error('room_access_invalid_room', __('Select an existing room on this website.', 'rrze-rsvp'), ['status' => 400]);
    }

    /**
     * Describes configuration incompatible with a restricted room.
     *
     * @param int $status HTTP status for the current boundary.
     * @return WP_Error Safe actionable configuration error.
     */
    private static function loginRequired(int $status): WP_Error
    {
        return new WP_Error('room_access_login_required', __('This room needs SSO or configured LDAP authentication before access can be restricted.', 'rrze-rsvp'), ['status' => $status]);
    }

    /** @return WP_Error Safe storage failure without ciphertext, addresses or key values. */
    private static function unavailable(): WP_Error
    {
        return new WP_Error('room_access_unavailable', __('Room access could not be verified. Ask an administrator to check the encryption configuration and stored list.', 'rrze-rsvp'), ['status' => 503]);
    }

    /** @return WP_Error Safe write/conflict failure preserving the current stored state. */
    private static function saveFailed(): WP_Error
    {
        return new WP_Error('room_access_save_failed', __('The room-access list could not be saved. Reload the page and try again.', 'rrze-rsvp'), ['status' => 500]);
    }
}
