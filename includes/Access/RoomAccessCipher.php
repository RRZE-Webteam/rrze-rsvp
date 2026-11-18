<?php

declare(strict_types=1);

namespace RRZE\RSVP\Access;

defined('ABSPATH') || exit;

/** Authenticates encrypted room lists and binds them to their site and room. */
final class RoomAccessCipher
{
    public const PREFIX = 'rrze-rsvp:room-access:encrypted:';
    private const VERSION = 1;

    /**
     * Binds this codec to a persistent site's context and one room.
     *
     * @param string $siteContext Lowercase hex encoding of a random 16-byte identifier.
     * @param int $roomId Existing room ID on the current site.
     * @throws \RuntimeException When Sodium or the context is unavailable.
     */
    public function __construct(private readonly string $siteContext, private readonly int $roomId)
    {
        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
            || !function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')
            || !preg_match('/\A[a-f0-9]{32}\z/', $siteContext) || $roomId < 1) {
            throw new \RuntimeException('Room-access encryption is unavailable.');
        }
    }

    /**
     * Encrypts a normalized list with the current configured key and a fresh nonce.
     *
     * @param list<string> $emails Validated normalized email addresses.
     * @return string Authenticated versioned ciphertext for post metadata.
     * @throws \RuntimeException When encoding or encryption fails.
     */
    public function encrypt(#[\SensitiveParameter] array $emails): string
    {
        try {
            $key = EncryptionKey::forWriting();
            $keyId = $key->identifier($this->siteContext);
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                json_encode($emails, JSON_THROW_ON_ERROR),
                $this->additionalData($key->source, $keyId),
                $nonce,
                $key->forStorage($this->siteContext)
            );
            return self::PREFIX . json_encode([
                'version' => self::VERSION,
                'key_source' => $key->source,
                'key_id' => $keyId,
                'nonce' => base64_encode($nonce),
                'ciphertext' => base64_encode($ciphertext),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable) {
            throw new \RuntimeException('The room-access list could not be encrypted.');
        }
    }

    /**
     * Decrypts only an authenticated supported envelope using its pinned source.
     *
     * @param string $stored Ciphertext as read from the room's metadata.
     * @return array<mixed> Payload, whose email schema is validated by the repository.
     * @throws \RuntimeException On malformed data, missing keys or authentication failure.
     */
    public function decrypt(string $stored): array
    {
        try {
            if (!str_starts_with($stored, self::PREFIX)) {
                throw new \RuntimeException();
            }
            $envelope = json_decode(substr($stored, strlen(self::PREFIX)), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($envelope) || count($envelope) !== 5
                || ($envelope['version'] ?? null) !== self::VERSION
                || !is_string($envelope['key_source'] ?? null)
                || !in_array($envelope['key_source'], [EncryptionKey::APPOINTMENT, EncryptionKey::WORDPRESS], true)
                || !is_string($envelope['key_id'] ?? null)
                || !preg_match('/\A[a-f0-9]{32}\z/', $envelope['key_id'])
                || !is_string($envelope['nonce'] ?? null) || !is_string($envelope['ciphertext'] ?? null)) {
                throw new \RuntimeException();
            }
            $key = EncryptionKey::fromSource($envelope['key_source']);
            $nonce = self::decodeBase64($envelope['nonce']);
            $ciphertext = self::decodeBase64($envelope['ciphertext']);
            if (strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
                || strlen($ciphertext) < SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES
                || !hash_equals($key->identifier($this->siteContext), $envelope['key_id'])) {
                throw new \RuntimeException();
            }
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $ciphertext,
                $this->additionalData($envelope['key_source'], $envelope['key_id']),
                $nonce,
                $key->forStorage($this->siteContext)
            );
            if ($plaintext === false) {
                throw new \RuntimeException();
            }
            $emails = json_decode($plaintext, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($emails)) {
                throw new \RuntimeException();
            }
            return $emails;
        } catch (\Throwable) {
            throw new \RuntimeException('The stored room-access list could not be authenticated.');
        }
    }

    /**
     * Produces unambiguous non-secret authenticated context for this exact record.
     *
     * @param string $source Pinned key source.
     * @param string $keyId Public derived key identifier.
     * @return string Encoded authenticated additional data.
     * @throws \JsonException When encoding fails.
     */
    private function additionalData(string $source, string $keyId): string
    {
        return json_encode(['rrze-rsvp:room-access', self::VERSION, $source, $keyId,
            $this->siteContext, $this->roomId, RoomAccess::META_KEY], JSON_THROW_ON_ERROR);
    }

    /**
     * Rejects noncanonical Base64 instead of ignoring malformed input.
     *
     * @param string $encoded Nonce or authenticated ciphertext in Base64.
     * @return string Decoded binary bytes.
     * @throws \RuntimeException On malformed encoding.
     */
    private static function decodeBase64(string $encoded): string
    {
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || base64_encode($decoded) !== $encoded) {
            throw new \RuntimeException('Invalid room-access encoding.');
        }
        return $decoded;
    }
}
