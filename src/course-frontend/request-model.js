/**
 * Produce a high-entropy request ID supported by the shared booking service.
 *
 * @param {Crypto} cryptoApi Browser or test Web Crypto implementation.
 * @returns {string} UUID or 128-bit hexadecimal request ID.
 */
function createRequestId(cryptoApi) {
    if (typeof cryptoApi.randomUUID === 'function') {
        return cryptoApi.randomUUID();
    }
    const bytes = new Uint8Array(16);
    cryptoApi.getRandomValues(bytes);
    /** @param {number} byte Random byte. @returns {string} Two hexadecimal digits. */
    function hex(byte) { return byte.toString(16).padStart(2, '0'); }
    return Array.from(bytes, hex).join('');
}

/**
 * Hash a canonical registration payload without persisting names or email.
 *
 * @param {Object} payload Occurrence ID and normalized participant values.
 * @param {Crypto} cryptoApi Web Crypto implementation.
 * @returns {Promise<string>} SHA-256 fingerprint for the pending attempt.
 */
async function fingerprint(payload, cryptoApi) {
    const person = payload.participant;
    const canonical = JSON.stringify([payload.occurrenceId, person.firstname || '', person.lastname || '',
        person.email || '', person.consent === true, payload.trusted === true]);
    const digest = await cryptoApi.subtle.digest('SHA-256', new TextEncoder().encode(canonical));
    /** @param {number} byte Digest byte. @returns {string} Two hexadecimal digits. */
    function hex(byte) { return byte.toString(16).padStart(2, '0'); }
    return Array.from(new Uint8Array(digest), hex).join('');
}

/**
 * Identify the exact occurrence of a structurally valid uncertain attempt.
 *
 * @param {Object|null} previous Restored pending state.
 * @param {number} occurrenceId Candidate occurrence.
 * @returns {boolean} Whether the date may offer an idempotent retry.
 */
function isPendingOccurrence(previous, occurrenceId) {
    return Boolean(previous && Number.isSafeInteger(previous.occurrenceId) && previous.occurrenceId > 0
        && previous.occurrenceId === Number(occurrenceId)
        && typeof previous.fingerprint === 'string' && /^[a-f0-9]{64}$/.test(previous.fingerprint)
        && typeof previous.requestId === 'string' && /^[A-Za-z0-9_-]{16,128}$/.test(previous.requestId));
}

/**
 * Reuse a pending ID only when the exact registration has not changed.
 *
 * @param {Object} payload Current submitted registration.
 * @param {Object|null} previous Last pending attempt, including after a reload.
 * @param {Crypto} cryptoApi Web Crypto implementation.
 * @param {boolean} retryOnly Reject new or changed registrations on unavailable dates.
 * @returns {Promise<Object|null>} Non-identifying attempt or null when a retry does not match.
 */
async function requestAttempt(payload, previous, cryptoApi, retryOnly = false) {
    const signature = await fingerprint(payload, cryptoApi);
    if (isPendingOccurrence(previous, payload.occurrenceId) && previous.fingerprint === signature) {
        return previous;
    }
    if (retryOnly) { return null; }
    return { occurrenceId: payload.occurrenceId, fingerprint: signature, requestId: createRequestId(cryptoApi) };
}

module.exports = { createRequestId, fingerprint, isPendingOccurrence, requestAttempt };
