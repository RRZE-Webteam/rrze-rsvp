<?php

/** Standalone tests of the shared course state/count rules, without WordPress. */
declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . '/');
require_once dirname(__DIR__) . '/includes/Courses/Model.php';

use RRZE\RSVP\Courses\Model;

/**
 * Fail a standalone assertion with a useful message.
 *
 * @param bool $condition Expected condition.
 * @param string $message Failure explanation.
 * @return void
 */
function courseAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$now = 1800000000;
$bookings = [];
for ($index = 0; $index < 14; $index++) {
    $bookings[] = ['status' => 'confirmed'];
}
for ($index = 0; $index < 4; $index++) {
    $bookings[] = ['status' => 'customer-confirmed'];
}
$counts = Model::counts($bookings, 20, $now);
courseAssert($counts === ['capacity' => 20, 'confirmed' => 14, 'pending' => 4, 'held' => 18, 'free' => 2], '14 confirmations + 4 holds must leave exactly 2 places.');
courseAssert(!Model::holdsPlace(['status' => 'booked', 'expires_at' => $now], $now), 'A hold expires at its exact deadline.');
courseAssert(Model::holdsPlace(['status' => 'booked', 'expires_at' => $now + 1], $now), 'A verification hold reserves a place before expiry.');
courseAssert(!Model::holdsPlace(['status' => 'cancelled'], $now), 'Cancellation releases a place.');
courseAssert(!Model::holdsPlace(['status' => 'confirmed', 'post_status' => 'trash'], $now), 'Trashed records cannot occupy capacity.');
courseAssert(Model::verifiedStatus(['auto_confirm' => false]) === 'customer-confirmed', 'Manual approvals require a distinct state.');
courseAssert(Model::verifiedStatus(['auto_confirm' => true]) === 'confirmed', 'Automatic approval follows identity verification.');
courseAssert(Model::normalizeEmail(' Person@Example.ORG ') === 'person@example.org', 'Duplicate matching normalizes case and whitespace.');
courseAssert(Model::normalizeEmailDomains(" @FAU.de, uk-erlangen.de;\n@fau.de\r\n") === ['fau.de', 'uk-erlangen.de'], 'Domain input accepts separators and optional @, normalizes case and removes duplicates.');
courseAssert(Model::normalizeEmailDomains([' @FAU.de ', 'xn--bcher-kva.example']) === ['fau.de', 'xn--bcher-kva.example'], 'Arrays and ASCII punycode domains are supported.');
foreach (['', " \r\n", []] as $emptyDomains) {
    courseAssert(Model::normalizeEmailDomains($emptyDomains) === [], 'Empty input disables the optional restriction.');
}
foreach (['fau.de, bad', '*.fau.de', 'person@fau.de', 'https://fau.de', '@@fau.de', 'fau.de.', 'fau..de', '-fau.de', 'fau_.de', str_repeat('a', 64) . '.de', ['fau.de', []], [''], null, false] as $invalidDomains) {
    courseAssert(Model::normalizeEmailDomains($invalidDomains) === null, 'Invalid entries must reject the complete configuration instead of weakening it.');
}
$domainSettings = array_merge(Model::defaults(), ['allowed_email_domains' => ['fau.de', 'uk-erlangen.de']]);
foreach ([' Person@FAU.DE ', 'person+course@uk-erlangen.de'] as $allowedEmail) {
    courseAssert(Model::allowsEmail($allowedEmail, $domainSettings), 'Every explicitly allowed domain is accepted regardless of case.');
}
foreach (['person@sub.fau.de', 'person@fau.de.example.org', 'person@notfau.de', 'person@example.org', 'person@@fau.de', '@fau.de', 'fau.de'] as $deniedEmail) {
    courseAssert(!Model::allowsEmail($deniedEmail, $domainSettings), 'Exact matching must reject other domains, subdomains, suffix tricks and malformed addresses.');
}
courseAssert(Model::allowsEmail('person@example.org', Model::defaults()) && Model::allowsEmail('person@example.org', []), 'New unrestricted courses and old courses without the setting remain open.');
courseAssert(!Model::allowsEmail('person@fau.de', ['allowed_email_domains' => null]), 'Malformed persisted settings must fail closed.');
courseAssert(Model::counts($bookings, 1, $now)['free'] === 0, 'Availability never displays negative capacity.');
echo "Course core state/count and email-domain tests passed.\n";
