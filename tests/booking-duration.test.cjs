const test = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const {JSDOM} = require('jsdom');

/**
 * Return the server's native select including its authoritative selected option.
 * @param {string} selected Submitted booking duration.
 * @returns {string} HTML returned in the duration fragment.
 */
function durationSelect(selected) {
    const options = ['', '20', '40', '60', '80'].map(
        /** @param {string} value Duration option. @returns {string} Option markup. */
        value => '<option value="' + value + '"' + (value === selected ? ' selected' : '') + '>' + value + '</option>'
    );
    return '<select name="rsvp_duration">' + options.join('') + '</select>';
}

test('the shipping picker preserves a middle duration and keyboard focus when AJAX replaces options',
    /** @returns {Promise<void>} Exercise the native select and the asynchronous frontend bundle. */
    async function retainDuration() {
        const dom = new JSDOM(`<form><input name="rsvp_room" value="77"><fieldset class="rsvp-booking-picker">
            <fieldset class="rsvp-date-container"><input type="radio" name="rsvp_date" value="2026-11-13" checked></fieldset>
            <fieldset class="rsvp-duration-container"><div class="rsvp-duration-options">${durationSelect('20')}</div></fieldset>
            <fieldset class="rsvp-time-container"><div class="rsvp-time-panel"><div class="rsvp-time-loading">
                <span class="rsvp-loading-label"></span><span class="rsvp-loading-date"></span></div>
                <div class="rsvp-time-select"><input type="radio" name="rsvp_time" value="09:20" checked></div></div></fieldset>
            <span class="rsvp-picker-status"></span><button class="rsvp-picker-retry" hidden></button>
            <span class="rsvp-picker-summary" hidden></span></fieldset><button type="submit">Book</button></form>`,
            {runScripts: 'outside-only'});
        try {
            const {window} = dom;
            await new Promise(resolve => window.addEventListener('load', resolve));
            const {document} = window;
            const payloads = [];
            window.rsvp_booking = {picker: {}, nonce: 'fixture'};
            window.fetch = /** @param {string} url Endpoint. @param {Object} request AJAX payload. @returns {Promise<Object>} Fixture reply. */
                async function updateForm(url, request) {
                    payloads.push(request.body);
                    return {ok: true, json: async () => ({
                        duration: durationSelect(request.body.get('rsvp_duration')),
                        time: '<div class="rsvp-time-select"></div>',
                    })};
                };
            window.eval(readFileSync(require.resolve('../build/shortcode.js'), 'utf8'));
            const select = document.querySelector('select');
            select.focus();
            select.value = '60';
            select.dispatchEvent(new window.Event('change', {bubbles: true}));
            await new Promise(resolve => setImmediate(resolve));
            assert.equal(payloads[0].get('rsvp_duration'), '60');
            assert.equal(payloads[0].get('time'), '');
            assert.equal(document.activeElement, select);
            assert.equal(select.value, '60');
            assert.equal(document.querySelector('[type="submit"]').disabled, true);
        } finally {
            dom.window.close();
        }
    }
);
