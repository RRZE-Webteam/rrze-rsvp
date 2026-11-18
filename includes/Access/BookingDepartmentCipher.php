<?php

declare(strict_types=1);

namespace RRZE\RSVP\Access;

defined('ABSPATH') || exit;

/** Authenticates immutable department snapshots for one booking on one website. */
final class BookingDepartmentCipher
{
    public const PREFIX = 'rrze-rsvp:booking-department:encrypted:';
    private const VERSION = 1;

    /**
     * Bind the codec to the existing website context and an already allocated booking.
     *
     * @param string $siteContext Persistent non-secret website context.
     * @param int $bookingId Booking ID whose metadata will contain the ciphertext.
     * @throws \RuntimeException When Sodium or the binding context is unavailable.
     */
    public function __construct(private readonly string $siteContext, private readonly int $bookingId)
    {
        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
            || !function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')
            || !preg_match('/\A[a-f0-9]{32}\z/', $siteContext) || $bookingId < 1) {
            throw new \RuntimeException('Booking-department encryption is unavailable.');
        }
    }

    /**
     * Encrypt the exact authorized department, retaining an authenticated historical room ID.
     *
     * @param array{id:string,name:string} $department Validated department snapshot.
     * @param int $roomId Room selected when this booking was created.
     * @return string Versioned ciphertext with a fresh random nonce and pinned key source.
     * @throws \RuntimeException When encoding or encryption fails.
     */
    public function encrypt(#[\SensitiveParameter] array $department, int $roomId): string
    {
        try {
            if ($roomId < 1) {
                throw new \RuntimeException();
            }
            $key = EncryptionKey::forWriting();
            $keyId = $key->bookingDepartmentIdentifier($this->siteContext);
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                json_encode($department, JSON_THROW_ON_ERROR),
                $this->additionalData($key->source, $keyId, $roomId),
                $nonce,
                $key->forBookingDepartment($this->siteContext)
            );
            return self::PREFIX . json_encode([
                'version' => self::VERSION,
                'key_source' => $key->source,
                'key_id' => $keyId,
                'room_id' => $roomId,
                'nonce' => base64_encode($nonce),
                'ciphertext' => base64_encode($ciphertext),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable) {
            throw new \RuntimeException('The booking department could not be encrypted.');
        }
    }

    /**
     * Authenticate a historical snapshot without consulting the current seat, room or catalog.
     *
     * @param string $stored Versioned ciphertext from this booking's protected metadata.
     * @return array<mixed> Decrypted payload; the repository validates its schema.
     * @throws \RuntimeException On missing keys, malformed data or authentication failure.
     */
    public function decrypt(string $stored): array
    {
        try {
            if (!str_starts_with($stored, self::PREFIX)) {
                throw new \RuntimeException();
            }
            $envelope = json_decode(substr($stored, strlen(self::PREFIX)), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($envelope) || count($envelope) !== 6
                || ($envelope['version'] ?? null) !== self::VERSION
                || !is_string($envelope['key_source'] ?? null)
                || !in_array($envelope['key_source'], [EncryptionKey::APPOINTMENT, EncryptionKey::WORDPRESS], true)
                || !is_string($envelope['key_id'] ?? null)
                || !preg_match('/\A[a-f0-9]{32}\z/', $envelope['key_id'])
                || !is_int($envelope['room_id'] ?? null) || $envelope['room_id'] < 1
                || !is_string($envelope['nonce'] ?? null) || !is_string($envelope['ciphertext'] ?? null)) {
                throw new \RuntimeException();
            }
            $key = EncryptionKey::fromSource($envelope['key_source']);
            $nonce = self::decodeBase64($envelope['nonce']);
            $ciphertext = self::decodeBase64($envelope['ciphertext']);
            if (strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
                || strlen($ciphertext) < SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES
                || !hash_equals($key->bookingDepartmentIdentifier($this->siteContext), $envelope['key_id'])) {
                throw new \RuntimeException();
            }
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $ciphertext,
                $this->additionalData($envelope['key_source'], $envelope['key_id'], $envelope['room_id']),
                $nonce,
                $key->forBookingDepartment($this->siteContext)
            );
            if ($plaintext === false) {
                throw new \RuntimeException();
            }
            $department = json_decode($plaintext, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($department)) {
                throw new \RuntimeException();
            }
            return $department;
        } catch (\Throwable) {
            throw new \RuntimeException('The stored booking department could not be authenticated.');
        }
    }

    /**
     * Bind ciphertext to its purpose, source, website, historical room and exact booking.
     *
     * @param string $source Pinned key source.
     * @param string $keyId Public purpose-specific key identifier.
     * @param int $roomId Authenticated original room ID from the envelope.
     * @return string Unambiguous authenticated additional data.
     * @throws \JsonException When encoding fails.
     */
    private function additionalData(string $source, string $keyId, int $roomId): string
    {
        return json_encode(['rrze-rsvp:booking-department', self::VERSION, $source, $keyId,
            $this->siteContext, $roomId, $this->bookingId, BookingDepartment::META_KEY], JSON_THROW_ON_ERROR);
    }

    /**
     * Decode canonical Base64 without silently accepting malformed ciphertext fields.
     *
     * @param string $encoded Encoded nonce or ciphertext.
     * @return string Binary bytes.
     * @throws \RuntimeException When the encoded representation is invalid.
     */
    private static function decodeBase64(string $encoded): string
    {
        $decoded = base64_decode($encoded, true);
        if ($decoded === false || base64_encode($decoded) !== $encoded) {
            throw new \RuntimeException('Invalid booking-department encoding.');
        }
        return $decoded;
    }
}
