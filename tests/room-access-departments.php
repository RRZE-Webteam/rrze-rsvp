<?php

/** Tests optional department assignments, legacy compatibility and catalog conflicts. */
declare(strict_types=1);

define('RRZE_RSVP_ROOM_ACCESS_TEST_BOOTSTRAP_ONLY', true);
require __DIR__ . '/room-access-storage.php';

$catalogKey = 'rrze_rsvp_department_catalog';
$metaKey = '_rrze_rsvp_room_access';
$contextKey = 'rrze_rsvp_room_access_context';
$prefix = 'rrze-rsvp:room-access:encrypted:';
$identity = ['source' => 'sso', 'email' => 'person@example.test'];
$resolve = ['action' => 'resolve', 'identity' => $identity];
$created = accessRun(['operations' => [['action' => 'catalog_read'],
    ['action' => 'catalog_create', 'name' => "  Forschung\n\tÄ  "],
    ['action' => 'catalog_create', 'name' => 'Verwaltung'], ['action' => 'catalog_read']]]);
accessAssert($created['results'][0]['value'] === [], 'Missing catalog must read empty without initialization.');
$first = $created['results'][1]['value'];
$second = $created['results'][2]['value'];
accessAssert($first['name'] === 'Forschung Ä' && !$first['archived'], 'Create normalizes Unicode whitespace and starts active.');
accessAssert(preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $first['id']) === 1
    && $first['id'] !== $second['id'], 'Department identities must be distinct stable UUIDv4 values.');
accessAssert($created['state']['autoload'][$catalogKey] === false && !isset($created['state']['options'][$contextKey]), 'Catalog does not autoload or create a cryptographic site context.');
$duplicate = accessRun(['state' => $created['state'], 'operations' => [['action' => 'catalog_create', 'name' => 'forschung ä']]]);
accessAssert($duplicate['results'][0]['error'] === 'department_duplicate_name' && $duplicate['state'] === $created['state'], 'Unicode case-insensitive duplicates must not change the catalog.');
foreach (['', '   ', '<b>Department</b>', "Bad\0name", str_repeat('Ä', 201)] as $name) {
    $invalid = accessRun(['state' => $created['state'], 'operations' => [['action' => 'catalog_create', 'name' => $name]]]);
    accessAssert($invalid['results'][0]['error'] === 'department_invalid_name' && $invalid['state'] === $created['state'], 'Invalid department names must preserve existing catalog.');
}
$invalidUtf8 = accessRun(['state' => $created['state'], 'operations' => [['action' => 'catalog_create', 'name_base64' => base64_encode("Invalid\xFF")]]]);
accessAssert($invalidUtf8['results'][0]['error'] === 'department_invalid_name' && $invalidUtf8['state'] === $created['state'], 'Malformed UTF-8 must not be coerced into a catalog name.');
$limit = accessRun(['operations' => [['action' => 'catalog_create', 'name' => str_repeat('Ä', 200)]]]);
accessAssert(isset($limit['results'][0]['value']), 'Name limit counts Unicode characters, not UTF-8 bytes.');
$rename = accessRun(['state' => $created['state'], 'operations' => [
    ['action' => 'catalog_rename', 'id' => $first['id'], 'name' => 'Renamed unit'], ['action' => 'catalog_read']]]);
accessAssert($rename['results'][0]['value'] === true && $rename['results'][1]['value'][0]['id'] === $first['id']
    && $rename['results'][1]['value'][0]['name'] === 'Renamed unit', 'Renaming must retain the department identity.');
$duplicateRename = accessRun(['state' => $created['state'], 'operations' => [
    ['action' => 'catalog_rename', 'id' => $first['id'], 'name' => 'VERWALTUNG']]]);
accessAssert($duplicateRename['results'][0]['error'] === 'department_duplicate_name' && $duplicateRename['state'] === $created['state'], 'Rename cannot take another department name.');
$unknownId = '11111111-1111-4111-8111-111111111111';
$unknown = accessRun(['state' => $created['state'], 'operations' => [
    ['action' => 'catalog_rename', 'id' => $unknownId, 'name' => 'Unknown'],
    ['action' => 'catalog_archive', 'id' => $unknownId, 'archived' => true]]]);
accessAssert($unknown['results'][0]['error'] === 'department_not_found' && $unknown['results'][1]['error'] === 'department_not_found', 'Unknown IDs cannot create catalog records through update actions.');
foreach (['catalogConflict' => 'department_conflict', 'failCatalogWrite' => 'department_unavailable', 'failCatalogRead' => 'department_unavailable'] as $flag => $code) {
    $failed = accessRun(['state' => $created['state'], $flag => true, 'operations' => [['action' => 'catalog_create', 'name' => 'New unit']]]);
    accessAssert($failed['results'][0]['error'] === $code && $failed['state'] === $created['state'], 'Catalog failures and concurrent changes must preserve previous records.');
}

$entries = [['email' => ' Person@Example.TEST ', 'department_id' => $first['id']],
    ['email' => 'unassigned@example.test', 'department_id' => null]];
$saved = accessRun(['state' => $created['state'], 'operations' => [['action' => 'save_entries', 'entries' => $entries],
    ['action' => 'read_entries'], ['action' => 'read'], $resolve,
    ['action' => 'resolve', 'identity' => ['source' => 'sso', 'email' => 'unassigned@example.test']], ['action' => 'revision']]]);
accessAssert($saved['results'][0]['value'] === true, 'Structured assignments must save successfully.');
$expectedEntries = [['email' => 'person@example.test', 'department_id' => $first['id']], ['email' => 'unassigned@example.test', 'department_id' => null]];
accessAssert($saved['results'][1]['value'] === $expectedEntries && $saved['results'][2]['value'] === ['person@example.test', 'unassigned@example.test'], 'Structured and legacy readers must agree about normalized emails.');
accessAssert($saved['results'][3]['value'] === ['department' => ['id' => $first['id'], 'name' => 'Forschung Ä']]
    && $saved['results'][4]['value'] === ['department' => null], 'Only matched assigned entries resolve a department snapshot.');
$stored = $saved['state']['meta'][10][$metaKey][0];
$envelope = json_decode(substr($stored, strlen($prefix)), true);
accessAssert($envelope['version'] === 2, 'Explicit saves must write the new structured envelope version.');
accessAssert(!str_contains(json_encode($saved['state']), 'person@example.test') && !str_contains($stored, $first['id']), 'Email-to-department association must exist only in encrypted storage.');
$readOnly = accessRun(['state' => $saved['state'], 'operations' => [['action' => 'read_entries'], $resolve]]);
accessAssert($readOnly['state'] === $saved['state'], 'Reads and resolution must never migrate or rewrite ciphertext.');
$legacySave = accessRun(['state' => $saved['state'], 'operations' => [
    ['action' => 'save', 'emails' => ['PERSON@example.test', 'new@example.test']], ['action' => 'read_entries']]]);
accessAssert($legacySave['results'][1]['value'] === [['email' => 'new@example.test', 'department_id' => null],
    ['email' => 'person@example.test', 'department_id' => $first['id']]], 'Legacy email-only saves retain departments for retained normalized addresses.');
$changed = accessRun(['state' => $saved['state'], 'operations' => [['action' => 'save_entries', 'entries' => [['email' => 'person@example.test', 'department_id' => $second['id']]]]]]);
$stale = accessRun(['state' => $changed['state'], 'operations' => [['action' => 'save_entries', 'entries' => [], 'revision' => $saved['results'][5]['value']]]]);
accessAssert($stale['results'][0]['error'] === 'room_access_conflict' && $stale['state'] === $changed['state'], 'Stale editor cannot clear a changed encrypted record.');
$freshRevision = accessRun(['state' => $saved['state'], 'operations' => [['action' => 'save_entries', 'entries' => $expectedEntries, 'revision' => $saved['results'][5]['value']]]]);
accessAssert($freshRevision['results'][0]['value'] === true, 'Matching revision permits explicit replacement.');
foreach ([[['email' => 'person@example.test', 'department_id' => $unknownId]],
    [['email' => 'person@example.test', 'department_id' => 'invalid']],
    [['email' => 'person@example.test', 'department_id' => $first['id']], ['email' => 'PERSON@example.test', 'department_id' => $second['id']]]] as $invalidEntries) {
    $invalid = accessRun(['state' => $saved['state'], 'operations' => [['action' => 'save_entries', 'entries' => $invalidEntries]]]);
    accessAssert(isset($invalid['results'][0]['error']) && $invalid['state'] === $saved['state'], 'Unknown, malformed and conflicting duplicate assignments must reject the entire edit.');
}

$archived = accessRun(['state' => $saved['state'], 'operations' => [
    ['action' => 'catalog_archive', 'id' => $first['id'], 'archived' => true], $resolve,
    ['action' => 'save_entries', 'entries' => $expectedEntries], ['action' => 'catalog_create', 'name' => 'forschung ä']]]);
accessAssert($archived['results'][0]['value'] === true && $archived['results'][1]['value']['department']['id'] === $first['id']
    && $archived['results'][2]['value'] === true, 'Archiving must preserve existing assignments and their booking eligibility.');
accessAssert($archived['results'][3]['error'] === 'department_duplicate_name', 'Archived names remain reserved.');
foreach ([['email' => 'new@example.test', 'department_id' => $first['id']],
    ['email' => 'unassigned@example.test', 'department_id' => $first['id']]] as $newAssignment) {
    $desired = [];
    foreach ($expectedEntries as $existingEntry) {
        if ($existingEntry['email'] !== $newAssignment['email']) {
            $desired[] = $existingEntry;
        }
    }
    $desired[] = $newAssignment;
    $invalid = accessRun(['state' => $archived['state'], 'operations' => [['action' => 'save_entries', 'entries' => $desired]]]);
    accessAssert($invalid['results'][0]['error'] === 'room_access_department_archived' && $invalid['state'] === $archived['state'], 'Archived departments cannot be newly assigned or transferred to another email.');
}
$removedAssignment = accessRun(['state' => $archived['state'], 'operations' => [['action' => 'save_entries', 'entries' => [['email' => 'person@example.test', 'department_id' => null]]], $resolve]]);
accessAssert($removedAssignment['results'][0]['value'] === true && $removedAssignment['results'][1]['value'] === ['department' => null], 'Existing archived assignments can be explicitly removed.');
$reactivated = accessRun(['state' => $archived['state'], 'operations' => [
    ['action' => 'catalog_archive', 'id' => $first['id'], 'archived' => false],
    ['action' => 'save_entries', 'entries' => [['email' => 'new@example.test', 'department_id' => $first['id']]]]]]);
accessAssert($reactivated['results'][1]['value'] === true, 'Reactivating a department permits new assignments without replacing its ID.');
$renamedAssigned = accessRun(['state' => $saved['state'], 'operations' => [
    ['action' => 'catalog_rename', 'id' => $first['id'], 'name' => 'New label'], $resolve]]);
accessAssert($renamedAssigned['results'][1]['value']['department']['name'] === 'New label'
    && $renamedAssigned['state']['meta'][10][$metaKey][0] === $stored, 'Resolution uses current label without rewriting room entries.');

foreach ([null, ['version' => 1, 'departments' => [$second]], ['version' => 1, 'departments' => [['id' => $first['id'], 'name' => '<bad>', 'archived' => false]]]] as $catalog) {
    $broken = $saved['state'];
    if ($catalog === null) {
        unset($broken['options'][$catalogKey]);
    } else {
        $broken['options'][$catalogKey] = $catalog;
    }
    $result = accessRun(['state' => $broken, 'operations' => [['action' => 'read_entries'], $resolve]]);
    accessAssert($result['results'][0]['value'] === $expectedEntries && $result['results'][1]['error'] === 'room_access_department_unavailable'
        && $result['state'] === $broken, 'Unresolvable catalog references stay editable but must fail closed when booking.');
}
$malformedCatalog = $saved['state'];
$malformedCatalog['options'][$catalogKey] = ['version' => 99, 'departments' => []];
$unassigned = accessRun(['state' => $malformedCatalog, 'operations' => [['action' => 'resolve', 'identity' => ['source' => 'sso', 'email' => 'unassigned@example.test']]]]);
accessAssert($unassigned['results'][0]['value'] === ['department' => null], 'Unassigned identities do not depend on an unrelated catalog.');
$repair = accessRun(['state' => $malformedCatalog, 'operations' => [['action' => 'save_entries', 'entries' => [['email' => 'person@example.test', 'department_id' => null]]]]]);
accessAssert($repair['results'][0]['value'] === true, 'Admins can remove a broken optional reference without resetting unreadable encrypted data.');
$disabled = $saved['state'];
$disabled['options']['rrze_rsvp']['general_room_access_enabled'] = 'off';
$off = accessRun(['state' => $disabled, 'operations' => [$resolve]]);
accessAssert($off['results'][0]['value'] === ['department' => null] && $off['state'] === $disabled, 'Disabling access returns no department while retaining catalog and assignments.');

$context = $saved['state']['options'][$contextKey];
$legacy = accessRun(['operations' => [['action' => 'legacy_encrypt', 'context' => $context, 'emails' => ['person@example.test']]]]);
$legacyState = $saved['state'];
$legacyState['meta'][10][$metaKey] = [$legacy['results'][0]['value']];
$oldRead = accessRun(['state' => $legacyState, 'operations' => [['action' => 'read_entries'], $resolve]]);
accessAssert($oldRead['results'][0]['value'] === [['email' => 'person@example.test', 'department_id' => null]]
    && $oldRead['results'][1]['value'] === ['department' => null] && $oldRead['state'] === $legacyState, 'Legacy v1 records read as unassigned without migration.');
$migrated = accessRun(['state' => $legacyState, 'operations' => [['action' => 'save_entries', 'entries' => [['email' => 'person@example.test', 'department_id' => $first['id']]]]]]);
accessAssert(json_decode(substr($migrated['state']['meta'][10][$metaKey][0], strlen($prefix)), true)['version'] === 2, 'Only an explicit legacy edit upgrades the stored payload.');
$constants = ['AUTH_KEY' => str_repeat('K', 64), 'AUTH_SALT' => str_repeat('S', 64), 'RRZE_APPOINTMENT_ENCRYPTION_KEY' => base64_encode(str_repeat('A', 64))];
$sourceUpgrade = accessRun(['state' => $saved['state'], 'constants' => $constants, 'operations' => [$resolve,
    ['action' => 'save_entries', 'entries' => $expectedEntries], $resolve]]);
accessAssert($sourceUpgrade['results'][0]['value'] === $sourceUpgrade['results'][2]['value'], 'Explicit key-source upgrade must preserve assignments and resolved labels.');
$sourceRemoved = accessRun(['state' => $sourceUpgrade['state'], 'operations' => [$resolve, ['action' => 'save_entries', 'entries' => []]]]);
accessAssert($sourceRemoved['results'][0]['error'] === 'room_access_unavailable' && $sourceRemoved['state'] === $sourceUpgrade['state'], 'Removing the structured record key must fail closed and preserve associations.');
$keyPurposes = accessRun(['operations' => [['action' => 'key_separation', 'context' => $context]]]);
accessAssert($keyPurposes['results'][0]['value'] === ['keys_differ' => true, 'identifiers_differ' => true, 'snapshot_bytes' => 32], 'Booking snapshots need independent keys and identifiers.');
$missingContext = accessRun(['operations' => [['action' => 'context']]]);
accessAssert(isset($missingContext['results'][0]['exception']) && !isset($missingContext['state']['options'][$contextKey]), 'Snapshot context lookup must never create a new context.');

echo 'Room-access department tests passed (' . $GLOBALS['accessChecks'] . " checks).\n";
