const test = require('node:test');
const assert = require('node:assert/strict');
const { webcrypto } = require('node:crypto');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const requestModel = require('../src/course-frontend/request-model');

const source = readFileSync(require.resolve('../src/course-frontend/index.js'), 'utf8').replace("import './style.scss';", '');
const storageKey = 'rrze-rsvp-course-attempt:/test-course/:12:0';

/** @returns {Object} Synthetic registration with no real participant data. */
function participant() { return { firstname: 'Test', lastname: 'Person', email: 'test@example.invalid', consent: 'on' }; }

/** @returns {Promise<void>} Let asynchronous availability refreshes settle. */
function settle() { return new Promise(setImmediate); }

/**
 * Run the production controller against a minimal DOM and a captured transport.
 *
 * @param {Map} storage Browser session state shared across page loads.
 * @param {boolean} full Initial server-rendered last-seat availability.
 * @param {Function} fetch Captured REST implementation.
 * @returns {Object} Form controls and async submission helper.
 */
function loadPage(storage, full, fetch) {
    const handlers = {};
    const radio = { disabled: full, checked: !full, value: '42', dataset: {} };
    const button = { disabled: true, textContent: '' };
    const result = {
        textContent: '', classList: {
            /** @returns {void} Styling is covered by browser acceptance. */
            toggle() {},
        },
        /** @returns {void} Accessibility attributes are not relevant to this retry fixture. */
        setAttribute() {},
        /** @returns {void} Focus is captured by the real browser acceptance test. */
        focus() {},
    };
    const display = { textContent: '' };
    const card = {
        dataset: { occurrenceId: '42' },
        /** @param {string} selector Selector used by the production controller. @returns {Object} Matching control. */
        querySelector(selector) { return selector === 'input' ? radio : display; },
    };
    const form = {
        dataset: { courseId: '12', formAllowed: '1', trusted: '0' }, fields: participant(),
        /** @param {string} selector Known form selector. @returns {Object|null} Matching element. */
        querySelector(selector) {
            if (selector === '.rrze-rsvp-course-result') { return result; }
            if (selector === '.rrze-rsvp-course-submit') { return button; }
            if (selector.startsWith('input[name="occurrenceId"]:checked')) {
                return radio.checked && (!selector.includes(':not(:disabled)') || !radio.disabled) ? radio : null;
            }
            throw new Error('Unexpected selector: ' + selector);
        },
        /** @param {string} selector Known multi-element selector. @returns {Array} Controls. */
        querySelectorAll(selector) { return selector === '.rrze-rsvp-course-date' ? [card] : [radio, button]; },
        /** @returns {boolean} Only valid synthetic fields are submitted. */
        reportValidity() { return true; },
        /** @param {string} name Event name. @param {Function} callback Event handler. @returns {void} */
        addEventListener(name, callback) { handlers[name] = callback; },
        /** @returns {void} The production controller may expose its busy state. */
        setAttribute() {},
        /** @returns {void} The production controller clears its busy state. */
        removeAttribute() {},
    };
    const labels = Object.fromEntries(['retryDetails', 'networkError', 'chooseDate', 'unavailable', 'refreshError', 'submitting', 'submit', 'success']
        .map(/** @param {string} value Message key. @returns {Array} Test-local translation pair. */ function label(value) { return [value, value]; }));
    /** FormData double reads only the fields captured by the production controller. */
    class FormData {
        /** @param {Object} value Fixture form. */
        constructor(value) { this.value = value; }
        /** @param {string} field Form field name. @returns {string} Value. */
        get(field) { return this.value.fields[field] || ''; }
    }
    vm.runInNewContext(source, {
        /** @returns {Object} Execute the real shared request model in the DOM fixture. */
        require() { return requestModel; },
        window: {
            rrzeRsvpCourses: { restUrl: '/wp-json/rrze-rsvp/v1/', restNonce: 'rest', nonce: 'form', labels },
            crypto: webcrypto, fetch, location: { pathname: '/test-course/' },
            sessionStorage: {
                /** @param {string} key Storage key. @returns {string|null} Serialized state. */
                getItem(key) { return storage.get(key) || null; },
                /** @param {string} key Storage key. @param {string} value State. @returns {void} */
                setItem(key, value) { storage.set(key, value); },
                /** @param {string} key Storage key. @returns {void} */
                removeItem(key) { storage.delete(key); },
            },
            /** @returns {void} Window focus is driven by real browser acceptance tests. */
            addEventListener() {},
        },
        document: {
            /** @returns {Array} A single course block. */
            querySelectorAll() { return [form]; },
        },
        FormData,
    });
    return { form, radio, result,
        /** @returns {Promise<void>} Submit exactly as the browser event would. */
        async submit() {
            await handlers.submit({ /** @returns {void} Suppress native navigation. */ preventDefault() {} });
            await settle();
        },
    };
}

/**
 * Return the public shape expected from a live last-seat availability request.
 *
 * @param {boolean} full Whether the only place has been taken.
 * @returns {Object} Fetch response fixture.
 */
function availability(full) {
    return { ok: true,
        /** @returns {Promise<Object>} Public occurrence response. */
        async json() { return { occurrences: [{ occurrence_id: 42, bookable: !full, message: full ? 'Fully booked' : '1 place available', label: 'Test date', location: 'Test room' }] }; },
    };
}

test('lost last-seat response survives reload and replays only the same participant request',
    /** @returns {Promise<void>} Exercise the production form controller across a simulated page reload. */
    async function reloadLastSeat() {
        const storage = new Map();
        const postedIds = [];
        let full = false;
        let bookings = 0;
        /** @param {string} url Endpoint. @param {Object} options Fetch options. @returns {Promise<Object>} Captured server result. */
        async function transport(url, options) {
            if (options.method !== 'POST') { return availability(full); }
            const payload = JSON.parse(options.body);
            postedIds.push(payload.requestId);
            if (!full) {
                full = true;
                bookings++;
                throw new TypeError('The server committed, but its response was lost.');
            }
            assert.equal(payload.requestId, postedIds[0], 'A last-seat retry must reuse the committed request identity.');
            return { ok: true,
                /** @returns {Promise<Object>} The existing committed booking. */
                async json() { return { id: 71, status: 'booked', created: false, message: 'Recovered registration' }; },
            };
        }
        const first = loadPage(storage, false, transport);
        await settle();
        await first.submit();
        const uncertain = storage.get(storageKey);
        assert.ok(uncertain, 'An unanswered request remains in session storage.');
        assert.equal(JSON.parse(uncertain).occurrenceId, 42);
        assert.equal(bookings, 1);

        const changed = loadPage(new Map([[storageKey, uncertain]]), true, transport);
        await settle();
        changed.form.fields.email = 'different@example.invalid';
        await changed.submit();
        assert.equal(postedIds.length, 1, 'Changed identity must not send a new request on the full date.');
        assert.equal(changed.result.textContent, 'retryDetails');

        const reloaded = loadPage(storage, true, transport);
        await settle();
        assert.equal(reloaded.radio.disabled, false, 'The previously full disabled date becomes retryable.');
        assert.equal(reloaded.radio.checked, true, 'The pending occurrence is restored.');
        assert.equal(reloaded.result.textContent, 'retryDetails');
        await reloaded.submit();
        assert.equal(postedIds.length, 2);
        assert.equal(postedIds[1], postedIds[0]);
        assert.equal(bookings, 1, 'Replay does not create another booking.');
        assert.equal(storage.has(storageKey), false, 'An acknowledged replay clears the uncertain state.');
        assert.equal(reloaded.result.textContent, 'Recovered registration');
    }
);

test('an authoritative full rejection clears the uncertain retry and disables the full date',
    /** @returns {Promise<void>} Do not preserve a retry exception after a definitive rejection. */
    async function definitiveFull() {
        const payload = { occurrenceId: 42, participant: { ...participant(), consent: true }, trusted: false };
        const attempt = await requestModel.requestAttempt(payload, null, webcrypto);
        const storage = new Map([[storageKey, JSON.stringify(attempt)]]);
        /** @param {string} url Endpoint. @param {Object} options Fetch options. @returns {Promise<Object>} Definitive rejection. */
        async function transport(url, options) {
            if (options.method !== 'POST') { return availability(true); }
            assert.equal(JSON.parse(options.body).requestId, attempt.requestId);
            return { ok: false,
                /** @returns {Promise<Object>} Shared service's authoritative full error. */
                async json() { return { code: 'course_full', message: 'Fully booked' }; },
            };
        }
        const page = loadPage(storage, true, transport);
        await settle();
        await page.submit();
        assert.equal(storage.has(storageKey), false);
        assert.equal(page.radio.disabled, true);
        assert.equal(page.radio.checked, false);
    }
);
