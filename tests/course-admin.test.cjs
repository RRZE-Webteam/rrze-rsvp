const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const script = fs.readFileSync(path.join(__dirname, '../src/course-admin/script.js'), 'utf8');

/**
 * Create the minimal browser boundary needed to exercise delegated form submission.
 *
 * @param {Function} fetcher Configured fetch response.
 * @returns {Object} Captured handlers and editable fields.
 */
function fixture(fetcher) {
    const handlers = {};
    const attributes = {};
    const errors = new Set();
    const feedback = {
        textContent: '',
        classList: {
            /** @param {string} name Class name. @param {boolean} active State. */
            toggle(name, active) { active ? errors.add(name) : errors.delete(name); },
        },
    };
    const input = {
        value: '0', focused: false, invalid: false,
        /** @param {string} name Attribute name. @param {string} value Attribute value. */
        setAttribute(name, value) { this.invalid = name === 'aria-invalid' && value === 'true'; },
        /** Mark focus without requiring a browser. */
        focus() { this.focused = true; },
    };
    const button = {disabled: false};
    const region = {
        /** @param {string} name Attribute name. @returns {string|undefined} Value. */
        getAttribute(name) { return attributes[name]; },
        /** @param {string} name Attribute name. @param {string} value Attribute value. */
        setAttribute(name, value) { attributes[name] = value; },
        /** @param {string} name Attribute name. */
        removeAttribute(name) { delete attributes[name]; },
        /** @param {string} selector DOM query. @returns {Object|null} Matching element. */
        querySelector(selector) {
            if (selector === '.rsvp-course-feedback') return feedback;
            if (selector === '[name="occurrence[capacity]"]') return input;
            return null;
        },
        /** @param {string} selector DOM query. @returns {Array} Matching controls. */
        querySelectorAll(selector) { return selector === 'button[type="submit"]' ? [button] : []; },
    };
    const form = {
        dataset: {operation: 'save_date', target: '87'},
        /** @param {string} selector DOM match. @returns {boolean} Whether this is a course operation. */
        matches(selector) { return selector === '.rsvp-course-action-form'; },
    };
    const document = {
        /** @param {string} name Event name. @param {Function} handler Listener. */
        addEventListener(name, handler) { handlers[name] = handler; },
        /** @param {string} selector DOM query. @returns {Object|null} Stable row. */
        querySelector(selector) { return selector === '[data-course-date="87"]' ? region : null; },
    };
    const event = {
        target: form, submitter: {dataset: {}}, prevented: false,
        /** Capture cancellation of the surrounding native request. */
        preventDefault() { this.prevented = true; },
    };
    class FormData {
        /** @param {Object} source External form owning the controls. */
        constructor(source) { this.source = source; this.capacity = input.value; }
    }
    vm.runInNewContext(script, {
        window: {rsvpCourseAdmin: {ajaxUrl: '/admin-ajax.php', saving: 'Saving…', failed: 'Values retained.'},
            /** @returns {boolean} Approve the explicit destructive-action prompt in test. */
            confirm() { return true; }},
        document, FormData, fetch: fetcher,
    });
    return {handlers, event, form, input, button, feedback, errors, attributes};
}

test('invalid date input stays editable and receives accessible server feedback',
    /** @returns {Promise<void>} Test completion. */
    async function () {
        let submitted;
        const browser = fixture(
            /** @param {string} url Endpoint. @param {Object} options Request. @returns {Promise<Object>} Validation response. */
            async function (url, options) {
                submitted = options;
                return {ok: false,
                    /** @returns {Promise<Object>} Decoded error payload. */
                    async json() { return {success: false, data: {message: 'Capacity must cover existing bookings.', field: 'capacity'}}; }};
            }
        );
        await browser.handlers.submit(browser.event);
        assert.equal(browser.event.prevented, true);
        assert.equal(submitted.method, 'POST');
        assert.equal(submitted.body.source, browser.form, 'Only the independent external form is submitted.');
        assert.equal(browser.input.value, '0', 'Rejected field content remains available for correction.');
        assert.equal(browser.input.invalid, true);
        assert.equal(browser.input.focused, true);
        assert.equal(browser.feedback.textContent, 'Capacity must cover existing bookings.');
        assert.equal(browser.errors.has('rsvp-course-error'), true);
        assert.equal(browser.button.disabled, false);
    }
);

test('double submit waits for the pending request and transport failure keeps entered data',
    /** @returns {Promise<void>} Test completion. */
    async function () {
        let requests = 0;
        let rejectRequest;
        const browser = fixture(
            /** @returns {Promise<Object>} Deferred response. */
            function () {
                requests++;
                return new Promise(
                    /** @param {Function} resolve Promise resolver. @param {Function} reject Promise rejection. */
                    function (resolve, reject) { rejectRequest = reject; }
                );
            }
        );
        const first = browser.handlers.submit(browser.event);
        assert.equal(browser.attributes['aria-busy'], 'true');
        assert.equal(browser.button.disabled, true);
        await browser.handlers.submit(browser.event);
        assert.equal(requests, 1, 'A second submit of the same date cannot dispatch another request.');
        rejectRequest(new Error('Network unavailable'));
        await first;
        assert.equal(browser.input.value, '0');
        assert.equal(browser.feedback.textContent, 'Values retained.');
        assert.equal(browser.button.disabled, false);
        assert.equal(browser.attributes['aria-busy'], undefined);
    }
);
