const test = require('node:test');
const assert = require('node:assert/strict');
const { webcrypto } = require('node:crypto');
const { createRequestId, fingerprint, requestAttempt } = require('../src/course-frontend/request-model');

/** @returns {Object} Representative guest registration with no real identity. */
function registration() {
    return { occurrenceId: 42, participant: { firstname: 'Test', lastname: 'Person', email: 'test@example.invalid', consent: true }, trusted: false };
}

test('repeated requests and a restored browser attempt keep the same ID',
    /** @returns {Promise<void>} Retry an unchanged payload before and after storage serialization. */
    async function retry() {
        const payload = registration();
        const initial = await requestAttempt(payload, null, webcrypto);
        const retry = await requestAttempt(payload, initial, webcrypto);
        const restored = await requestAttempt(payload, JSON.parse(JSON.stringify(initial)), webcrypto);
        assert.equal(retry.requestId, initial.requestId);
        assert.equal(restored.requestId, initial.requestId);
        assert.match(initial.requestId, /^[A-Za-z0-9_-]{16,128}$/);
    }
);

test('different participant, date, consent or authentication starts a separate request',
    /** @returns {Promise<void>} Avoid accidentally replaying another submitted registration. */
    async function changedPayload() {
        const original = registration();
        const pending = await requestAttempt(original, null, webcrypto);
        for (const modified of [
            { ...original, occurrenceId: 99 },
            { ...original, participant: { ...original.participant, email: 'other@example.invalid' } },
            { ...original, participant: { ...original.participant, firstname: 'Another' } },
            { ...original, participant: { ...original.participant, consent: false } },
            { ...original, trusted: true },
        ]) {
            assert.notEqual((await requestAttempt(modified, pending, webcrypto)).requestId, pending.requestId);
        }
    }
);

test('pending storage contains only a public date ID, hash and random request identity',
    /** @returns {Promise<void>} Do not retain raw participant details in session storage. */
    async function storagePrivacy() {
        const pending = await requestAttempt(registration(), null, webcrypto);
        assert.deepEqual(Object.keys(pending).sort(), ['fingerprint', 'occurrenceId', 'requestId']);
        assert.match(pending.fingerprint, /^[a-f0-9]{64}$/);
        assert.ok(!JSON.stringify(pending).includes('example.invalid'));
        const reordered = { trusted: false, participant: { email: 'test@example.invalid', consent: true, lastname: 'Person', firstname: 'Test' }, occurrenceId: 42 };
        assert.equal(await fingerprint(reordered, webcrypto), pending.fingerprint);
    }
);

test('malformed stored IDs are replaced and secure random fallback remains valid',
    /** @returns {Promise<void>} Reject invalid restored state without weak randomness. */
    async function invalidStoredState() {
        const payload = registration();
        const previous = { fingerprint: await fingerprint(payload, webcrypto), requestId: 'invalid' };
        assert.notEqual((await requestAttempt(payload, previous, webcrypto)).requestId, previous.requestId);
        const fallback = { getRandomValues: webcrypto.getRandomValues.bind(webcrypto) };
        assert.match(createRequestId(fallback), /^[a-f0-9]{32}$/);
        assert.notEqual(createRequestId(fallback), createRequestId(fallback));
    }
);
