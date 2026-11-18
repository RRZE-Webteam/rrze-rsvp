<?php

declare(strict_types=1);

namespace RRZE\RSVP\Access;

defined('ABSPATH') || exit;

/** Derives independent room-access keys without persisting secret material. */
final class EncryptionKey
{
    public const CONFIGURATION_CONSTANT = 'RRZE_APPOINTMENT_ENCRYPTION_KEY';
    public const APPOINTMENT = 'appointment';
    public const WORDPRESS = 'wp_auth_salt';

    /**
     * Retains a validated key only for this request.
     *
     * @param string $source Public identifier of the configured key source.
     * @param string $material Secret master key bytes.
     */
    private function __construct(
        public readonly string $source,
        #[\SensitiveParameter] private readonly string $material
    ) {
    }

    /**
     * Selects the configured source for an explicit write, without silent fallback.
     *
     * @return self Validated key selected for new ciphertext.
     * @throws \RuntimeException When the selected source is unavailable or invalid.
     */
    public static function forWriting(): self
    {
        return self::fromSource(defined(self::CONFIGURATION_CONSTANT) ? self::APPOINTMENT : self::WORDPRESS);
    }

    /**
     * Loads exactly the source recorded in an existing authenticated envelope.
     *
     * @param string $source Source selected by the envelope or the writer.
     * @return self Validated request-local key.
     * @throws \RuntimeException When the source is unsupported or unavailable.
     */
    public static function fromSource(string $source): self
    {
        if ($source === self::APPOINTMENT) {
            if (!defined(self::CONFIGURATION_CONSTANT) || !is_string(constant(self::CONFIGURATION_CONSTANT))) {
                throw new \RuntimeException('The room-access encryption key is unavailable.');
            }
            $encoded = str_replace(["\r", "\n", "\t", ' '], '', constant(self::CONFIGURATION_CONSTANT));
            $material = base64_decode($encoded, true);
            if ($material === false || !in_array(strlen($material), [32, 64], true)
                || base64_encode($material) !== $encoded) {
                throw new \RuntimeException('The room-access encryption key is invalid.');
            }
            return new self($source, $material);
        }
        if ($source !== self::WORDPRESS) {
            throw new \RuntimeException('The room-access key source is unsupported.');
        }
        self::assertWordPressConstants();
        $material = wp_salt('auth');
        if (!is_string($material) || $material === '') {
            throw new \RuntimeException('The room-access WordPress key is unavailable.');
        }
        return new self($source, $material);
    }

    /**
     * Prevents WordPress from replacing missing or duplicated constants with DB secrets.
     *
     * @return void
     * @throws \RuntimeException When either authentication constant is unsuitable.
     */
    private static function assertWordPressConstants(): void
    {
        foreach (['AUTH_KEY', 'AUTH_SALT'] as $name) {
            if (!defined($name) || !is_string(constant($name)) || !constant($name)
                || in_array(constant($name), ['put your unique phrase here', __('put your unique phrase here')], true)) {
                throw new \RuntimeException('Stable WordPress authentication keys must be configured.');
            }
            foreach (['AUTH', 'SECURE_AUTH', 'LOGGED_IN', 'NONCE', 'SECRET'] as $scheme) {
                foreach (['KEY', 'SALT'] as $part) {
                    $other = $scheme . '_' . $part;
                    if ($other !== $name && defined($other) && constant($other) === constant($name)) {
                        throw new \RuntimeException('WordPress authentication keys must be distinct.');
                    }
                }
            }
        }
    }

    /**
     * Derives a purpose-bound 256-bit key for this site's room-access data.
     *
     * @param string $siteContext Persistent non-secret site identifier.
     * @return string Binary encryption key; never persist or log it.
     */
    public function forStorage(string $siteContext): string
    {
        return hash_hkdf('sha256', $this->material, 32, 'rrze-rsvp:room-access:storage:v1:' . $this->source, $siteContext);
    }

    /**
     * Derives a public identifier independently from the storage key.
     *
     * @param string $siteContext Persistent non-secret site identifier.
     * @return string Lowercase hexadecimal key identifier.
     */
    public function identifier(string $siteContext): string
    {
        return bin2hex(hash_hkdf('sha256', $this->material, 16, 'rrze-rsvp:room-access:key-id:v1:' . $this->source, $siteContext));
    }

    /** @return array{source: string} Public debug information without key material. */
    public function __debugInfo(): array
    {
        return ['source' => $this->source];
    }

    /**
     * Prevents accidental persistence of key objects.
     *
     * @return array<never, never> Never returned.
     * @throws \RuntimeException Always.
     */
    public function __serialize(): array
    {
        throw new \RuntimeException('Room-access keys cannot be serialized.');
    }

    /**
     * Rejects reconstruction of secret key objects from serialized input.
     *
     * @param array<mixed> $data Unused serialized properties.
     * @return void
     * @throws \RuntimeException Always.
     */
    public function __unserialize(#[\SensitiveParameter] array $data): void
    {
        throw new \RuntimeException('Room-access keys cannot be unserialized.');
    }
}
