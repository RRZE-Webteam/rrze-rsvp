<?php

declare(strict_types=1);

namespace RRZE\RSVP\Access;

use WP_Error;

defined('ABSPATH') || exit;

/** Maintains site-local department labels without storing any participant identity. */
final class DepartmentCatalog
{
    public const OPTION_NAME = 'rrze_rsvp_department_catalog';
    public const ID_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/';

    /**
     * Reads active and archived departments without creating or repairing data.
     *
     * @return list<array{id: string, name: string, archived: bool}>|WP_Error Valid catalog.
     */
    public static function read(): array|WP_Error
    {
        try {
            return self::snapshot()['departments'] ?? [];
        } catch (\Throwable) {
            return self::unavailable();
        }
    }

    /**
     * Creates a uniquely named department with a new stable UUID.
     *
     * @param string $name User-entered plain-text name.
     * @return array{id: string, name: string, archived: bool}|WP_Error Created record.
     */
    public static function create(string $name): array|WP_Error
    {
        $name = self::normalizeName($name);
        if ($name === null) {
            return self::invalidName();
        }
        try {
            $previous = self::snapshot();
            $records = $previous['departments'] ?? [];
            if (self::hasName($records, $name)) {
                return self::duplicateName();
            }
            $record = ['id' => wp_generate_uuid4(), 'name' => $name, 'archived' => false];
            if (!preg_match(self::ID_PATTERN, $record['id'])) {
                return self::unavailable();
            }
            foreach ($records as $existing) {
                if ($existing['id'] === $record['id']) {
                    return self::unavailable();
                }
            }
            $records[] = $record;
            $result = self::write($previous, $records);
            return $result === true ? $record : $result;
        } catch (\Throwable) {
            return self::unavailable();
        }
    }

    /**
     * Renames a department without changing its identity or archived state.
     *
     * @param string $id Existing department UUID.
     * @param string $name New plain-text name.
     * @return true|WP_Error Whether the validated replacement was persisted.
     */
    public static function rename(string $id, string $name): true|WP_Error
    {
        $name = self::normalizeName($name);
        if ($name === null) {
            return self::invalidName();
        }
        return self::change($id, $name, null);
    }

    /**
     * Archives or reactivates a department while preserving all existing references.
     *
     * @param string $id Existing department UUID.
     * @param bool $archived Desired archive flag; no deletion operation is exposed.
     * @return true|WP_Error Whether the catalog was updated.
     */
    public static function setArchived(string $id, bool $archived): true|WP_Error
    {
        return self::change($id, null, $archived);
    }

    /**
     * Applies a validated name or archive change to exactly one observed catalog.
     *
     * @param string $id Existing department UUID.
     * @param string|null $name Normalized replacement name, or null to retain it.
     * @param bool|null $archived Replacement flag, or null to retain it.
     * @return true|WP_Error Result without silently overwriting a concurrent edit.
     */
    private static function change(string $id, ?string $name, ?bool $archived): true|WP_Error
    {
        if (!preg_match(self::ID_PATTERN, $id)) {
            return self::notFound();
        }
        try {
            $previous = self::snapshot();
            $records = $previous['departments'] ?? [];
            foreach ($records as $index => $record) {
                if ($record['id'] !== $id) {
                    continue;
                }
                if ($name !== null && self::hasName($records, $name, $id)) {
                    return self::duplicateName();
                }
                $records[$index] = ['id' => $id, 'name' => $name ?? $record['name'],
                    'archived' => $archived ?? $record['archived']];
                return $records === $previous['departments'] ? true : self::write($previous, $records);
            }
            return self::notFound();
        } catch (\Throwable) {
            return self::unavailable();
        }
    }

    /**
     * Loads and validates the complete non-autoloading option using fresh cache state.
     *
     * @return array{version: int, departments: list<array{id: string, name: string, archived: bool}>}|null Current envelope.
     * @throws \RuntimeException On database errors or any malformed catalog value.
     */
    private static function snapshot(): ?array
    {
        global $wpdb;

        self::evict();
        $value = get_option(self::OPTION_NAME, null);
        if (isset($wpdb) && ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException('The department catalog could not be read.');
        }
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || count($value) !== 2 || ($value['version'] ?? null) !== 1
            || !is_array($value['departments'] ?? null) || !array_is_list($value['departments'])) {
            throw new \RuntimeException('Invalid department catalog.');
        }
        $seen = [];
        foreach ($value['departments'] as $record) {
            if (!is_array($record) || count($record) !== 3 || !is_string($record['id'] ?? null)
                || !preg_match(self::ID_PATTERN, $record['id']) || !is_string($record['name'] ?? null)
                || self::normalizeName($record['name']) !== $record['name'] || !is_bool($record['archived'] ?? null)
                || isset($seen[$record['id']]) || self::hasName(array_values($seen), $record['name'])) {
                throw new \RuntimeException('Invalid department catalog record.');
            }
            $seen[$record['id']] = $record;
        }
        return $value;
    }

    /**
     * Compares the observed option bytes before replacing a complete catalog.
     *
     * @param array<mixed>|null $previous Validated previously observed option.
     * @param list<array{id: string, name: string, archived: bool}> $records Complete replacement.
     * @return true|WP_Error Success, conflict or database failure.
     */
    private static function write(?array $previous, array $records): true|WP_Error
    {
        global $wpdb;

        $next = ['version' => 1, 'departments' => $records];
        try {
            if ($previous === null) {
                $changed = add_option(self::OPTION_NAME, $next, '', false);
            } else {
                $changed = $wpdb->query($wpdb->prepare(
                    'UPDATE %i SET option_value = %s, autoload = %s WHERE option_name = %s AND BINARY option_value = BINARY %s',
                    $wpdb->options, maybe_serialize($next), 'no', self::OPTION_NAME, maybe_serialize($previous)
                ));
            }
            if (($wpdb->last_error ?? '') !== '') {
                return self::unavailable();
            }
            if (!$changed) {
                return new WP_Error('department_conflict', __('The departments changed in another request. Reload the page and try again.', 'rrze-rsvp'), ['status' => 409]);
            }
            return true;
        } finally {
            self::evict();
        }
    }

    /**
     * Normalizes Unicode spacing while rejecting markup, controls and oversized labels.
     *
     * @param string $name User-entered or stored label.
     * @return string|null Canonical label of 1–200 Unicode codepoints, or null.
     */
    private static function normalizeName(string $name): ?string
    {
        if (preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $name) !== 0) {
            return null;
        }
        $name = preg_replace('/[\p{Z}\s]+/u', ' ', $name);
        if ($name === null) {
            return null;
        }
        $name = trim($name);
        $length = preg_match_all('/./us', $name);
        return $length !== false && $length > 0 && $length <= 200 ? $name : null;
    }

    /**
     * Compares names case-insensitively using Unicode-aware PCRE, including archives.
     *
     * @param list<array{id: string, name: string, archived: bool}> $records Existing catalog.
     * @param string $name Normalized proposed label.
     * @param string|null $exceptId Record being renamed, if any.
     * @return bool Whether another record already owns the name.
     */
    private static function hasName(array $records, string $name, ?string $exceptId = null): bool
    {
        foreach ($records as $record) {
            if ($record['id'] !== $exceptId && preg_match('/\A' . preg_quote($name, '/') . '\z/iu', $record['name']) === 1) {
                return true;
            }
        }
        return false;
    }

    /** @return void Evict only this non-autoloading option and absent-option cache. */
    private static function evict(): void
    {
        wp_cache_delete(self::OPTION_NAME, 'options');
        wp_cache_delete('notoptions', 'options');
    }

    /** @return WP_Error Safe error for invalid names without echoing submitted content. */
    private static function invalidName(): WP_Error
    {
        return new WP_Error('department_invalid_name', __('Enter a plain-text department name with no more than 200 characters.', 'rrze-rsvp'), ['status' => 422]);
    }

    /** @return WP_Error Conflict with an active or archived existing label. */
    private static function duplicateName(): WP_Error
    {
        return new WP_Error('department_duplicate_name', __('A department with this name already exists, including archived departments.', 'rrze-rsvp'), ['status' => 422]);
    }

    /** @return WP_Error Unknown department identity. */
    private static function notFound(): WP_Error
    {
        return new WP_Error('department_not_found', __('The selected department does not exist on this website.', 'rrze-rsvp'), ['status' => 404]);
    }

    /** @return WP_Error Safe failure that never treats a damaged catalog as empty. */
    private static function unavailable(): WP_Error
    {
        return new WP_Error('department_unavailable', __('The department catalog could not be read or saved. No replacement catalog was created.', 'rrze-rsvp'), ['status' => 503]);
    }
}
