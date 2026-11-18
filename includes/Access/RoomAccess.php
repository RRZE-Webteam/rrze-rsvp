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
        $entries = self::readEntries($roomId);
        return $entries instanceof WP_Error ? $entries : array_column($entries, 'email');
    }

    /**
     * Reads canonical email assignments while keeping catalog lookup out of the editor.
     *
     * Legacy version-one emails are exposed with a null department without writes.
     * Unknown catalog references remain editable; booking resolution rejects them.
     *
     * @param int $roomId Room ID on the current site.
     * @return list<array{email: string, department_id: ?string}>|WP_Error Stored entries.
     */
    public static function readEntries(int $roomId): array|WP_Error
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
     * Replaces emails through the legacy interface while retaining existing assignments.
     *
     * Existing unreadable data is never overwritten, including requests to clear it.
     * A concurrent edit between reading assignments and saving causes a conflict.
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
            $oldEntries = self::decode($roomId, $previous);
            $departments = array_column($oldEntries, 'department_id', 'email');
            $entries = [];
            foreach ($normalized as $email) {
                $entries[] = ['email' => $email, 'department_id' => $departments[$email] ?? null];
            }
            return self::saveEntries($roomId, $entries, self::revisionOf($previous));
        } catch (\Throwable) {
            return self::unavailable();
        }
    }

    /**
     * Replaces complete assignments after validation and optional editor-revision checking.
     *
     * Callers enforce administration capability and nonce. Existing unreadable data
     * is never overwritten. Archived departments may remain on the same existing
     * email but cannot be assigned to another email or newly selected.
     *
     * @param int $roomId Room ID on the current site.
     * @param list<array{email: string, department_id: ?string}> $entries Complete desired list.
     * @param string|null $expectedRevision Revision observed when the editor was loaded.
     * @return true|WP_Error Safe result without leaking plaintext in errors.
     */
    public static function saveEntries(int $roomId, #[\SensitiveParameter] array $entries, ?string $expectedRevision = null): true|WP_Error
    {
        if (!self::isRoom($roomId)) {
            return self::invalidRoom();
        }
        $normalized = self::normalizeEntries($entries);
        if ($normalized === null) {
            return new WP_Error('room_access_invalid_entries', __('Enter valid email addresses with at most one department per address.', 'rrze-rsvp'), ['status' => 422]);
        }
        try {
            $previous = self::raw($roomId);
            if ($expectedRevision !== null && !hash_equals(self::revisionOf($previous), $expectedRevision)) {
                return self::conflict();
            }
            $oldEntries = self::decode($roomId, $previous);
            if ($normalized && !self::loginSources($roomId)) {
                return self::loginRequired(422);
            }
            $validDepartments = self::validateDepartments($normalized, $oldEntries);
            if ($validDepartments instanceof WP_Error) {
                return $validDepartments;
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
            if ($cipher->decrypt($encrypted) !== ['version' => 2, 'entries' => $normalized]) {
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
        $resolved = self::resolve($roomId, $identity);
        return $resolved instanceof WP_Error ? $resolved : true;
    }

    /**
     * Authorizes a verified identity and resolves its current optional department label.
     *
     * Existing archived assignments remain valid. A missing or damaged referenced
     * department blocks booking rather than silently losing snapshot information.
     *
     * @param int $roomId Room being booked on the current site.
     * @param array{source: string, email: string}|null $identity Server-verified identity.
     * @return array{department: null|array{id: string, name: string}}|WP_Error Resolution.
     */
    public static function resolve(int $roomId, #[\SensitiveParameter] ?array $identity): array|WP_Error
    {
        if (!self::isEnabled()) {
            return ['department' => null];
        }
        $entries = self::readEntries($roomId);
        if ($entries instanceof WP_Error || !$entries) {
            return $entries instanceof WP_Error ? $entries : ['department' => null];
        }
        $sources = self::loginSources($roomId);
        if (!$sources) {
            return self::loginRequired(403);
        }
        $email = is_string($identity['email'] ?? null) ? strtolower(trim($identity['email'])) : '';
        if (!in_array($identity['source'] ?? null, ['sso', 'ldap'], true) || !is_email($email)) {
            return new WP_Error('room_access_identity_required', __('Sign in with the required SSO or LDAP account to book this room.', 'rrze-rsvp'), ['status' => 403]);
        }
        foreach ($entries as $entry) {
            if ($entry['email'] !== $email) {
                continue;
            }
            if ($entry['department_id'] === null) {
                return ['department' => null];
            }
            $catalog = DepartmentCatalog::read();
            if ($catalog instanceof WP_Error) {
                return self::departmentUnavailable();
            }
            foreach ($catalog as $department) {
                if ($department['id'] === $entry['department_id']) {
                    return ['department' => ['id' => $department['id'], 'name' => $department['name']]];
                }
            }
            return self::departmentUnavailable();
        }
        return new WP_Error('room_access_denied', __('Your account is not authorized to book this room.', 'rrze-rsvp'), ['status' => 403]);
    }

    /**
     * Returns an opaque revision of exactly the currently observed encrypted record.
     *
     * @param int $roomId Room whose editor or caller needs conflict detection.
     * @return string|WP_Error SHA-256 revision; absent metadata has a stable marker.
     */
    public static function revision(int $roomId): string|WP_Error
    {
        if (!self::isRoom($roomId)) {
            return self::invalidRoom();
        }
        try {
            return self::revisionOf(self::raw($roomId));
        } catch (\Throwable) {
            return self::unavailable();
        }
    }

    /**
     * Exposes the existing validated context to the separate booking-snapshot codec.
     *
     * @return string Persistent site context, never created by this reader.
     * @throws \RuntimeException When the context is missing or malformed.
     */
    public static function siteContext(): string
    {
        return self::context(false);
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
     * @return list<array{email: string, department_id: ?string}> Authenticated assignments.
     * @throws \RuntimeException On unreadable, malformed or noncanonical stored data.
     */
    private static function decode(int $roomId, ?string $raw): array
    {
        if ($raw === null) {
            return [];
        }
        $payload = (new RoomAccessCipher(self::context(false), $roomId))->decrypt($raw);
        if (array_is_list($payload)) {
            $emails = self::normalize($payload);
            if ($emails === null || $emails !== $payload) {
                throw new \RuntimeException('Invalid legacy room-access list.');
            }
            $entries = [];
            foreach ($emails as $email) {
                $entries[] = ['email' => $email, 'department_id' => null];
            }
            return $entries;
        }
        $entries = $payload['entries'] ?? null;
        if (!is_array($entries) || self::normalizeEntries($entries) !== $entries) {
            throw new \RuntimeException('Invalid room-access assignments.');
        }
        return $entries;
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
     * Canonicalizes complete rows, rejecting conflicting duplicate assignments.
     *
     * @param array<mixed> $entries Submitted or authenticated structured rows.
     * @return list<array{email: string, department_id: ?string}>|null Canonical rows.
     */
    private static function normalizeEntries(#[\SensitiveParameter] array $entries): ?array
    {
        if (!array_is_list($entries)) {
            return null;
        }
        $normalized = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || count($entry) !== 2 || !is_string($entry['email'] ?? null)
                || !array_key_exists('department_id', $entry)
                || ($entry['department_id'] !== null && (!is_string($entry['department_id'])
                    || !preg_match(DepartmentCatalog::ID_PATTERN, $entry['department_id'])))) {
                return null;
            }
            $email = strtolower(trim($entry['email']));
            if (strlen($email) > 254 || !is_email($email)
                || (array_key_exists($email, $normalized) && $normalized[$email]['department_id'] !== $entry['department_id'])) {
                return null;
            }
            $normalized[$email] = ['email' => $email, 'department_id' => $entry['department_id']];
        }
        ksort($normalized, SORT_STRING);
        return array_values($normalized);
    }

    /**
     * Validates referenced departments while retaining unchanged archived assignments.
     *
     * @param list<array{email: string, department_id: ?string}> $entries Desired assignments.
     * @param list<array{email: string, department_id: ?string}> $previous Existing assignments.
     * @return true|WP_Error Whether the desired references are permissible.
     */
    private static function validateDepartments(#[\SensitiveParameter] array $entries, #[\SensitiveParameter] array $previous): true|WP_Error
    {
        $assigned = array_filter(array_column($entries, 'department_id'));
        if (!$assigned) {
            return true;
        }
        $catalog = DepartmentCatalog::read();
        if ($catalog instanceof WP_Error) {
            return self::departmentUnavailable();
        }
        $byId = array_column($catalog, null, 'id');
        $oldByEmail = array_column($previous, 'department_id', 'email');
        foreach ($entries as $entry) {
            $id = $entry['department_id'];
            if ($id === null) {
                continue;
            }
            if (!isset($byId[$id])) {
                return new WP_Error('room_access_invalid_department', __('Select an existing department on this website.', 'rrze-rsvp'), ['status' => 422]);
            }
            if ($byId[$id]['archived'] && ($oldByEmail[$entry['email']] ?? null) !== $id) {
                return new WP_Error('room_access_department_archived', __('Archived departments can only be retained on their existing email assignments.', 'rrze-rsvp'), ['status' => 422]);
            }
        }
        return true;
    }

    /**
     * Hashes a storage value without exposing ciphertext or plaintext to editor inputs.
     *
     * @param string|null $raw Exact observed ciphertext or absent metadata.
     * @return string Opaque revision with distinct absence and presence domains.
     */
    private static function revisionOf(?string $raw): string
    {
        return hash('sha256', $raw === null ? 'rrze-rsvp:room-access:absent' : "rrze-rsvp:room-access:record\0" . $raw);
    }

    /** @return WP_Error Stale editor revision without applying any partial replacement. */
    private static function conflict(): WP_Error
    {
        return new WP_Error('room_access_conflict', __('The room-access list changed in another request. Reload the page before saving.', 'rrze-rsvp'), ['status' => 409]);
    }

    /** @return WP_Error Missing or damaged department data must block snapshot resolution. */
    private static function departmentUnavailable(): WP_Error
    {
        return new WP_Error('room_access_department_unavailable', __('The assigned department could not be verified. Ask an administrator to check the department catalog.', 'rrze-rsvp'), ['status' => 503]);
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
