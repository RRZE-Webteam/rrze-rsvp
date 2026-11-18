<?php

/** Tests the real room-access cipher and policy with an isolated WordPress store. */
declare(strict_types=1);

use RRZE\RSVP\Access\EncryptionKey;
use RRZE\RSVP\Access\RoomAccess;
use RRZE\RSVP\Access\RoomAccessCipher;

/** Supplies the WordPress error contract without booting a site or database. */
final class WP_Error
{
    /**
     * Retains safe boundary error information for assertions.
     *
     * @param string $code Domain error code.
     * @param string $message Safe error message.
     * @param array<mixed> $data Structured error data.
     */
    public function __construct(public string $code, public string $message, public array $data = [])
    {
    }
}

/** @return string Translation-free fixture text. */
function __(string $message, string $domain = 'default'): string
{
    return $message;
}

/** @return mixed The fixture option, or its supplied default. */
function get_option(string $name, mixed $default = false): mixed
{
    return $GLOBALS['accessState']['options'][$name] ?? $default;
}

/** @return bool Whether a non-autoloading context option was inserted. */
function add_option(string $name, mixed $value, string $deprecated = '', bool $autoload = true): bool
{
    if (array_key_exists($name, $GLOBALS['accessState']['options'])) {
        return false;
    }
    $GLOBALS['accessState']['options'][$name] = $value;
    $GLOBALS['accessState']['autoload'][$name] = $autoload;
    return true;
}

/** @return object|null A post within the selected fixture site's post table. */
function get_post(int $id): ?object
{
    $post = $GLOBALS['accessState']['posts'][$id] ?? null;
    return $post === null ? null : (object) $post;
}

/** @return mixed Raw fixture metadata, preserving absent and malformed records. */
function get_post_meta(int $id, string $name, bool $single = false): mixed
{
    if (!empty($GLOBALS['accessInput']['failRead'])) {
        $GLOBALS['wpdb']->last_error = 'Synthetic database read failure.';
        return $single ? '' : [];
    }
    if (!array_key_exists($id, $GLOBALS['accessMetaCache'])) {
        $GLOBALS['accessMetaCache'][$id] = $GLOBALS['accessState']['meta'][$id] ?? [];
    }
    $values = $GLOBALS['accessMetaCache'][$id][$name] ?? [];
    return $single ? ($values[0] ?? '') : $values;
}

/** @return bool Whether the request-local WordPress metadata cache was invalidated. */
function wp_cache_delete(int|string $key, string $group): bool
{
    if ($group === 'post_meta') {
        unset($GLOBALS['accessMetaCache'][$key]);
        return true;
    }
    return false;
}

/** @return string WordPress-compatible slashing at the metadata write boundary. */
function wp_slash(string $value): string
{
    return addslashes($value);
}

/** @return int|false A synthetic metadata ID when the absent row can be inserted. */
function add_post_meta(int $id, string $name, string $value, bool $unique = false): int|false
{
    if (!empty($GLOBALS['accessInput']['failWrite']) || ($unique && get_post_meta($id, $name) !== [])) {
        return false;
    }
    $GLOBALS['accessState']['meta'][$id][$name][] = stripslashes($value);
    wp_cache_delete($id, 'post_meta');
    return 1;
}

/** @return bool Whether the exact observed metadata value was replaced. */
function update_post_meta(int $id, string $name, string $value, mixed $previous = ''): bool
{
    if (!empty($GLOBALS['accessInput']['failWrite']) || get_post_meta($id, $name, true) !== $previous) {
        return false;
    }
    $GLOBALS['accessState']['meta'][$id][$name] = [stripslashes($value)];
    wp_cache_delete($id, 'post_meta');
    return true;
}

/** @return bool Whether deletion matched the exact previously observed ciphertext. */
function delete_post_meta(int $id, string $name, mixed $previous = ''): bool
{
    if (!empty($GLOBALS['accessInput']['failWrite']) || get_post_meta($id, $name, true) !== $previous) {
        return false;
    }
    unset($GLOBALS['accessState']['meta'][$id][$name]);
    wp_cache_delete($id, 'post_meta');
    return true;
}

/** @return string Authentication salt; invalid configuration must never reach this stub. */
function wp_salt(string $scheme): string
{
    $GLOBALS['accessSaltCalls']++;
    return AUTH_KEY . AUTH_SALT;
}

/** @return string|false Fixture equivalent of WordPress email validation for test addresses. */
function is_email(string $email): string|false
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : false;
}

/**
 * Runs fixture operations using real encryption and policy classes.
 *
 * @param array<mixed> $input Public synthetic fixture configuration.
 * @return array<mixed> Results and raw synthetic storage for inspection.
 */
function accessFixture(array $input): array
{
    define('ABSPATH', dirname(__DIR__) . '/');
    $GLOBALS['accessInput'] = $input;
    $GLOBALS['accessSaltCalls'] = 0;
    $GLOBALS['accessMetaCache'] = [];
    $GLOBALS['wpdb'] = (object) ['last_error' => ''];
    $GLOBALS['accessState'] = $input['state'] ?? accessDefaultState();
    $constants = $input['constants'] ?? ['AUTH_KEY' => str_repeat('K', 64), 'AUTH_SALT' => str_repeat('S', 64)];
    foreach ($constants as $name => $value) {
        define($name, $value);
    }
    require_once dirname(__DIR__) . '/includes/Access/EncryptionKey.php';
    require_once dirname(__DIR__) . '/includes/Access/RoomAccessCipher.php';
    require_once dirname(__DIR__) . '/includes/Access/RoomAccess.php';

    $results = [];
    foreach ($input['operations'] as $operation) {
        $room = $operation['room'] ?? 10;
        try {
            $result = match ($operation['action']) {
                'save' => RoomAccess::save($room, $operation['emails']),
                'read' => RoomAccess::read($room),
                'authorize' => RoomAccess::authorize($room, $operation['identity'] ?? null),
                'enabled' => RoomAccess::isEnabled(),
                'encrypt' => (new RoomAccessCipher($operation['context'], $room))->encrypt($operation['emails']),
                'debug' => accessKeyDebug(),
                'external_replace' => accessExternalReplace($room, $operation['value']),
                default => throw new RuntimeException('Unknown test operation.'),
            };
            $results[] = $result instanceof WP_Error
                ? ['error' => $result->code, 'message' => $result->message, 'status' => $result->data['status'] ?? null]
                : ['value' => $result];
        } catch (Throwable $exception) {
            $results[] = ['exception' => get_class($exception), 'message' => $exception->getMessage()];
        }
    }
    return ['results' => $results, 'state' => $GLOBALS['accessState'], 'saltCalls' => $GLOBALS['accessSaltCalls']];
}

/**
 * Simulates a parallel request changing storage after this request cached a room.
 *
 * @param int $roomId Room modified by the simulated other request.
 * @param string $value New ciphertext persisted outside the current request's cache.
 * @return true Whether the synthetic write completed.
 */
function accessExternalReplace(int $roomId, string $value): true
{
    $GLOBALS['accessState']['meta'][$roomId]['_rrze_rsvp_room_access'] = [$value];
    return true;
}

/** @return array{debug: string, serializable: bool} Key diagnostics without secret material. */
function accessKeyDebug(): array
{
    $key = EncryptionKey::forWriting();
    ob_start();
    var_dump($key);
    $debug = ob_get_clean();
    try {
        serialize($key);
        $serializable = true;
    } catch (RuntimeException) {
        $serializable = false;
    }
    return ['debug' => $debug, 'serializable' => $serializable];
}

/** @return array<mixed> One site's initial rooms and access configuration. */
function accessDefaultState(): array
{
    return [
        'options' => ['rrze_rsvp' => ['general_room_access_enabled' => 'on', 'ldap_server' => 'ldap.example.test']],
        'meta' => [10 => ['rrze-rsvp-room-sso-required' => ['on']], 20 => ['rrze-rsvp-room-ldap-required' => ['on']]],
        'posts' => [
            10 => ['post_type' => 'room', 'post_status' => 'publish'],
            20 => ['post_type' => 'room', 'post_status' => 'draft'],
            30 => ['post_type' => 'rsvp_course', 'post_status' => 'publish'],
            40 => ['post_type' => 'room', 'post_status' => 'trash'],
        ],
    ];
}

/**
 * Starts a fresh PHP process so key additions/removals behave like separate requests.
 *
 * @param array<mixed> $input Synthetic state and operations.
 * @param list<string> $phpOptions Additional flags for missing-extension tests.
 * @return array<mixed> Decoded fixture result.
 */
function accessRun(array $input, array $phpOptions = []): array
{
    $process = proc_open([PHP_BINARY, ...$phpOptions, __FILE__, '--fixture'],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start room-access fixture.');
    }
    fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || $errors !== '') {
        throw new RuntimeException('Room-access fixture failed: ' . $errors);
    }
    return json_decode($output, true, 32, JSON_THROW_ON_ERROR);
}

/**
 * Fails a meaningful behavior assertion with a stable diagnostic.
 *
 * @param bool $condition Expected invariant.
 * @param string $message Test failure explanation.
 * @return void
 */
function accessAssert(bool $condition, string $message): void
{
    $GLOBALS['accessChecks'] = ($GLOBALS['accessChecks'] ?? 0) + 1;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

if (($argv[1] ?? '') === '--fixture') {
    echo json_encode(accessFixture(json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR);
    exit;
}

$metaKey = '_rrze_rsvp_room_access';
$contextKey = 'rrze_rsvp_room_access_context';
$appointmentName = 'RRZE_APPOINTMENT_ENCRYPTION_KEY';
$prefix = 'rrze-rsvp:room-access:encrypted:';
$save = ['action' => 'save', 'emails' => [' Person@Example.TEST ', 'person@example.test', 'second@example.test']];
$base = accessRun(['operations' => [$save, ['action' => 'read'], ['action' => 'authorize', 'identity' => ['source' => 'sso', 'email' => 'PERSON@example.test']],
    ['action' => 'authorize', 'identity' => ['source' => 'sso', 'email' => 'other@example.test']], ['action' => 'authorize'],
    ['action' => 'authorize', 'identity' => ['source' => 'posted', 'email' => 'person@example.test']]]]);
accessAssert($base['results'][0]['value'] === true, 'Valid SSO room list must save.');
accessAssert($base['results'][1]['value'] === ['person@example.test', 'second@example.test'], 'Addresses must normalize and deduplicate.');
accessAssert($base['results'][2]['value'] === true, 'Verified allowed SSO identity must pass.');
accessAssert($base['results'][3]['error'] === 'room_access_denied', 'Other identities must be denied.');
accessAssert($base['results'][4]['error'] === 'room_access_identity_required', 'Anonymous callers must not pass a restricted room.');
accessAssert($base['results'][5]['error'] === 'room_access_identity_required', 'Only a verified SSO or LDAP identity is accepted.');
accessAssert($base['state']['autoload'][$contextKey] === false, 'Site context must not autoload.');
$stored = $base['state']['meta'][10][$metaKey][0];
accessAssert(str_starts_with($stored, $prefix) && !str_contains($stored, 'person@example.test'), 'Database values must contain ciphertext only.');
$again = accessRun(['state' => $base['state'], 'operations' => [$save]]);
accessAssert($again['state']['meta'][10][$metaKey][0] !== $stored, 'Every save must use a fresh nonce.');
$revoked = accessRun(['state' => $base['state'], 'operations' => [['action' => 'save', 'emails' => ['new@example.test']]]]);
$freshCheck = accessRun(['state' => $base['state'], 'operations' => [['action' => 'read'],
    ['action' => 'external_replace', 'value' => $revoked['state']['meta'][10][$metaKey][0]],
    ['action' => 'authorize', 'identity' => ['source' => 'sso', 'email' => 'person@example.test']]]]);
accessAssert($freshCheck['results'][2]['error'] === 'room_access_denied', 'Final authorization must bypass an earlier request-local metadata cache after a parallel revocation.');
$debug = accessRun(['operations' => [['action' => 'debug']]])['results'][0]['value'];
accessAssert(!$debug['serializable'] && !str_contains($debug['debug'], str_repeat('K', 64)), 'Key debug and serialization must not reveal material.');

$open = accessRun(['operations' => [['action' => 'read'], ['action' => 'authorize']]]);
accessAssert($open['results'][0]['value'] === [] && $open['results'][1]['value'] === true, 'An absent list must leave the room open.');
$cleared = accessRun(['state' => $base['state'], 'operations' => [['action' => 'save', 'emails' => []], ['action' => 'authorize']]]);
accessAssert($cleared['results'][0]['value'] === true && $cleared['results'][1]['value'] === true, 'Explicitly clearing readable metadata must reopen the room.');
accessAssert(!isset($cleared['state']['meta'][10][$metaKey]), 'Clearing a restriction must remove its metadata.');
$offState = $base['state'];
$offState['options']['rrze_rsvp']['general_room_access_enabled'] = 'off';
$off = accessRun(['state' => $offState, 'operations' => [['action' => 'authorize']]]);
accessAssert($off['results'][0]['value'] === true && $off['state']['meta'][10][$metaKey][0] === $stored, 'Feature off must bypass without deleting lists.');

foreach ([['bad-address'], [''], ['person@example.test', false], ['key' => 'person@example.test']] as $emails) {
    $invalid = accessRun(['state' => $base['state'], 'operations' => [['action' => 'save', 'emails' => $emails]]]);
    accessAssert($invalid['results'][0]['error'] === 'room_access_invalid_emails' && $invalid['state']['meta'][10][$metaKey][0] === $stored,
        'Any invalid address must reject the complete save without replacing data.');
}
foreach ([0, 30, 40, 999] as $room) {
    $invalid = accessRun(['operations' => [['action' => 'save', 'room' => $room, 'emails' => ['person@example.test']]]]);
    accessAssert($invalid['results'][0]['error'] === 'room_access_invalid_room', 'Only current-site existing rooms may receive lists.');
}
$unconfigured = $base['state'];
$unconfigured['meta'][10]['rrze-rsvp-room-sso-required'] = ['off'];
$invalid = accessRun(['state' => $unconfigured, 'operations' => [$save, ['action' => 'authorize', 'identity' => ['source' => 'sso', 'email' => 'person@example.test']]]]);
accessAssert($invalid['results'][0]['error'] === 'room_access_login_required' && $invalid['results'][1]['error'] === 'room_access_login_required', 'Removing trusted login must fail closed.');
$ldap = accessRun(['operations' => [['action' => 'save', 'room' => 20, 'emails' => ['person@example.test']],
    ['action' => 'authorize', 'room' => 20, 'identity' => ['source' => 'ldap', 'email' => 'person@example.test']],
    ['action' => 'authorize', 'room' => 20, 'identity' => ['source' => 'sso', 'email' => 'person@example.test']]]]);
accessAssert($ldap['results'][1]['value'] === true, 'Configured LDAP rooms must accept verified LDAP identities.');
accessAssert($ldap['results'][2]['value'] === true, 'SSO identity priority must survive an LDAP-configured room when the caller separately enforces the room login requirement.');
$noServer = $ldap['state'];
$noServer['options']['rrze_rsvp']['ldap_server'] = '';
$invalid = accessRun(['state' => $noServer, 'operations' => [['action' => 'authorize', 'room' => 20, 'identity' => ['source' => 'ldap', 'email' => 'person@example.test']]]]);
accessAssert($invalid['results'][0]['error'] === 'room_access_login_required', 'LDAP without a server must not authorize.');

$envelope = json_decode(substr($stored, strlen($prefix)), true, 8, JSON_THROW_ON_ERROR);
foreach ([['version' => 2], ['key_source' => 'appointment'], ['key_id' => str_repeat('0', 32)],
    ['nonce' => base64_encode(str_repeat('N', 24))], ['ciphertext' => base64_encode(str_repeat('C', 30))],
    ['nonce' => '!'], ['extra' => 'unexpected']] as $change) {
    $tampered = $base['state'];
    $tampered['meta'][10][$metaKey] = [$prefix . json_encode(array_merge($envelope, $change), JSON_THROW_ON_ERROR)];
    $result = accessRun(['state' => $tampered, 'operations' => [['action' => 'read'], ['action' => 'save', 'emails' => []], ['action' => 'authorize']]]);
    accessAssert($result['results'][0]['error'] === 'room_access_unavailable' && $result['results'][1]['error'] === 'room_access_unavailable'
        && $result['results'][2]['error'] === 'room_access_unavailable' && $result['state'] === $tampered,
        'Tampering must block reading, clearing and authorization without data loss.');
}
foreach ([[''], [$stored, $stored], [false]] as $values) {
    $tampered = $base['state'];
    $tampered['meta'][10][$metaKey] = $values;
    $result = accessRun(['state' => $tampered, 'operations' => [['action' => 'read']]]);
    accessAssert($result['results'][0]['error'] === 'room_access_unavailable', 'Present malformed metadata must never mean unrestricted.');
}
$otherRoom = $base['state'];
$otherRoom['meta'][20][$metaKey] = [$stored];
$result = accessRun(['state' => $otherRoom, 'operations' => [['action' => 'read', 'room' => 20]]]);
accessAssert($result['results'][0]['error'] === 'room_access_unavailable', 'Ciphertext cannot be transplanted to another room.');
foreach ([null, 'invalid', str_repeat('0', 32)] as $context) {
    $otherSite = $base['state'];
    $otherSite['options'][$contextKey] = $context;
    $result = accessRun(['state' => $otherSite, 'operations' => [['action' => 'read'], $save]]);
    accessAssert($result['results'][0]['error'] === 'room_access_unavailable' && $result['state'] === $otherSite, 'Missing or changed site context must not be regenerated for existing data.');
}

$saltConstants = ['AUTH_KEY' => str_repeat('K', 64), 'AUTH_SALT' => str_repeat('S', 64)];
foreach ([32, 64] as $size) {
    $constants = $saltConstants + [$appointmentName => base64_encode(str_repeat('A', $size))];
    $result = accessRun(['constants' => $constants, 'operations' => [$save, ['action' => 'read']]]);
    accessAssert($result['results'][1]['value'] === ['person@example.test', 'second@example.test'], 'Both supported Appointment key lengths must work.');
}
$appointmentConstants = $saltConstants + [$appointmentName => " \t" . chunk_split(base64_encode(str_repeat('A', 64)), 64, "\r\n")];
$upgraded = accessRun(['constants' => $appointmentConstants, 'state' => $base['state'], 'operations' => [['action' => 'read'], $save]]);
accessAssert($upgraded['results'][0]['value'] === ['person@example.test', 'second@example.test'], 'Adding the constant must preserve reading older salt-based lists.');
$upgradedEnvelope = json_decode(substr($upgraded['state']['meta'][10][$metaKey][0], strlen($prefix)), true);
accessAssert($upgradedEnvelope['key_source'] === 'appointment', 'Explicit saving after configuring Appointment must use that source.');
$removed = accessRun(['state' => $upgraded['state'], 'operations' => [['action' => 'read'], $save, ['action' => 'save', 'emails' => []]]]);
accessAssert($removed['results'][0]['error'] === 'room_access_unavailable' && $removed['results'][1]['error'] === 'room_access_unavailable'
    && $removed['results'][2]['error'] === 'room_access_unavailable' && $removed['state'] === $upgraded['state'], 'Removing the Appointment key must never silently fall back or overwrite lists.');
foreach (['AUTH_KEY', 'AUTH_SALT'] as $name) {
    $changed = $saltConstants;
    $changed[$name] = str_repeat('Z', 64);
    $result = accessRun(['constants' => $changed, 'state' => $base['state'], 'operations' => [['action' => 'read'], $save]]);
    accessAssert($result['results'][0]['error'] === 'room_access_unavailable' && $result['state'] === $base['state'], 'Salt rotation must preserve unreadable ciphertext and fail closed.');
}
foreach ([false, '', 'not-base64', base64_encode(str_repeat('A', 16)), base64_encode(str_repeat('A', 48))] as $key) {
    $result = accessRun(['constants' => $saltConstants + [$appointmentName => $key], 'state' => $base['state'], 'operations' => [['action' => 'read'], $save]]);
    accessAssert(isset($result['results'][0]['value']) && $result['results'][1]['error'] === 'room_access_unavailable'
        && $result['state'] === $base['state'], 'An invalid defined Appointment key must block writes without blocking existing salt reads.');
}
foreach ([[], ['AUTH_KEY' => 'put your unique phrase here', 'AUTH_SALT' => 'distinct'],
    ['AUTH_KEY' => '0', 'AUTH_SALT' => 'distinct'],
    ['AUTH_KEY' => 'same', 'AUTH_SALT' => 'same'], $saltConstants + ['LOGGED_IN_KEY' => str_repeat('K', 64)]] as $constants) {
    $result = accessRun(['constants' => $constants, 'operations' => [$save]]);
    accessAssert($result['results'][0]['error'] === 'room_access_unavailable' && $result['saltCalls'] === 0,
        'Invalid or duplicated WP constants must never trigger a DB-backed wp_salt fallback.');
}
$withoutSodium = accessRun(['state' => $base['state'], 'operations' => [['action' => 'read'], $save]],
    ['-d', 'disable_functions=sodium_crypto_aead_xchacha20poly1305_ietf_encrypt']);
accessAssert($withoutSodium['results'][0]['error'] === 'room_access_unavailable' && $withoutSodium['state'] === $base['state'], 'Missing Sodium must return a safe error without fatal or overwrite.');
$failedWrite = accessRun(['state' => $base['state'], 'failWrite' => true, 'operations' => [$save, ['action' => 'save', 'emails' => []]]]);
accessAssert($failedWrite['results'][0]['error'] === 'room_access_save_failed' && $failedWrite['results'][1]['error'] === 'room_access_save_failed'
    && $failedWrite['state'] === $base['state'], 'Failed DB writes and deletions must not report success.');
$failedRead = accessRun(['state' => $base['state'], 'failRead' => true, 'operations' => [['action' => 'read'], ['action' => 'authorize'], $save]]);
accessAssert($failedRead['results'][0]['error'] === 'room_access_unavailable' && $failedRead['results'][1]['error'] === 'room_access_unavailable'
    && $failedRead['results'][2]['error'] === 'room_access_unavailable' && $failedRead['state'] === $base['state'], 'A database read failure must never look like an unrestricted room.');
$changedAppointment = $saltConstants + [$appointmentName => base64_encode(str_repeat('B', 64))];
$changedKey = accessRun(['state' => $upgraded['state'], 'constants' => $changedAppointment, 'operations' => [['action' => 'read'], $save]]);
accessAssert($changedKey['results'][0]['error'] === 'room_access_unavailable' && $changedKey['state'] === $upgraded['state'], 'Changing the Appointment key must preserve old ciphertext and fail closed.');
$malformed = accessRun(['operations' => [['action' => 'encrypt', 'context' => $base['state']['options'][$contextKey], 'emails' => ['bad-email']]]]);
$malformedState = $base['state'];
$malformedState['meta'][10][$metaKey] = [$malformed['results'][0]['value']];
$result = accessRun(['state' => $malformedState, 'operations' => [['action' => 'read']]]);
accessAssert($result['results'][0]['error'] === 'room_access_unavailable', 'Even authenticated ciphertext must satisfy the email-list schema.');

echo 'Room-access storage and policy tests passed (' . $GLOBALS['accessChecks'] . " checks).\n";
