const test = require('node:test');
const assert = require('node:assert/strict');
const { latestRequest } = require('../src/shortcode/latest-request');

/** @returns {Object} Controllable transport preserving request and cancellation evidence. */
function transport() {
    const calls = [];
    /** @param {Object} payload Request data. @param {AbortSignal} signal Cancellation. @returns {Promise<Object>} Deferred response. */
    function send(payload, signal) {
        return new Promise(
            /** @param {Function} resolve Resolve response. @param {Function} reject Reject response. @returns {void} */
            (resolve, reject) => calls.push({ payload, signal, resolve, reject })
        );
    }
    return { calls, send };
}

test('a late date response cannot overwrite the latest selected date',
    /** @returns {Promise<void>} Resolve reads in reverse order, as a slow server might. */
    async function latestSelectionWins() {
        const server = transport();
        const request = latestRequest(server.send);
        const first = request({ action: 'UpdateForm', date: '2026-10-30' });
        const second = request({ action: 'UpdateForm', date: '2026-11-02' });
        assert.equal(server.calls[0].signal.aborted, true);
        server.calls[1].resolve('November slots');
        assert.deepEqual(await second, { status: 'success', data: 'November slots' });
        server.calls[0].resolve('October slots');
        assert.deepEqual(await first, { status: 'stale' });
    }
);

test('an obsolete failure does not replace a newer successful selection',
    /** @returns {Promise<void>} Simulate cancellation failing after the next request succeeds. */
    async function obsoleteFailure() {
        const server = transport();
        const request = latestRequest(server.send);
        const first = request({ date: '2026-10-30' });
        const second = request({ date: '2026-10-31' });
        server.calls[1].resolve('Current slots');
        assert.equal((await second).status, 'success');
        server.calls[0].reject(new Error('Aborted'));
        assert.equal((await first).status, 'stale');
    }
);

test('current failures are recoverable with a fresh request',
    /** @returns {Promise<void>} Retry a read after a temporary network failure. */
    async function retryFailure() {
        const server = transport();
        const request = latestRequest(server.send);
        const failed = request({ date: '2026-10-30' });
        server.calls[0].reject(new Error('Offline'));
        assert.equal((await failed).status, 'error');
        const retry = request({ date: '2026-10-30' });
        assert.equal(server.calls[1].signal.aborted, false);
        server.calls[1].resolve('Available');
        assert.deepEqual(await retry, { status: 'success', data: 'Available' });
    }
);

test('separate month and appointment reads can complete without cancelling each other',
    /** @returns {Promise<void>} A month change must not strand pending time controls. */
    async function independentRequests() {
        const server = transport();
        const options = latestRequest(server.send);
        const calendar = latestRequest(server.send);
        const day = options({ action: 'UpdateForm', date: '2026-10-30' });
        const month = calendar({ action: 'UpdateCalendar', month: '2026-11' });
        assert.equal(server.calls[0].signal.aborted, false);
        server.calls[1].resolve('November');
        assert.equal((await month).status, 'success');
        server.calls[0].resolve('October slots');
        assert.equal((await day).status, 'success');
    }
);

const { calendarSelection } = require('../src/shortcode/calendar-selection');

/**
 * Model calendar DOM replacement and the form's successful date controls.
 * @param {string} date Day represented by a radio.
 * @param {boolean} checked Initial selection.
 * @returns {Object} Minimal native radio state.
 */
function dayRadio(date, checked = false) {
    return { type: 'radio', name: 'rsvp_date', value: date, checked, required: true, dataset: { label: date } };
}

/** @param {Object[]} radios Initial month. @returns {Object} Stable fieldset with replaceable radios. */
function calendarHost(radios) {
    return {
        radios, persistent: [],
        ownerDocument: {
            /** @returns {Object} An input that survives calendar replacement. */
            createElement() { return { dataset: {} }; },
        },
        /** @returns {Object|undefined} Initially checked date radio. */
        querySelector() { return this.radios.find(
            /** @param {Object} radio Date control. @returns {boolean} Selected state. */
            (radio) => radio.checked
        ); },
        /** @returns {Object[]} Radios belonging to the currently displayed month. */
        querySelectorAll() { return this.radios; },
        /** @param {Object} input Persistent form control. @returns {void} */
        append(input) { this.persistent.push(input); },
    };
}

test('the submitted date survives browsing away and is marked again on return',
    /** @returns {void} Keep one form value even when the selected radio is not rendered. */
    function retainDateAcrossMonths() {
        const host = calendarHost([dayRadio('2026-10-30', true)]);
        const selection = calendarSelection(host);
        host.radios = [dayRadio('2026-11-02')];
        selection.sync();
        assert.equal(selection.input.value, '2026-10-30');
        assert.equal(selection.input.dataset.label, '2026-10-30');
        assert.equal(host.radios[0].checked, false);
        assert.equal(host.radios[0].required, false, 'An unselected visible month must not block native form validation.');
        assert.equal(host.radios[0].name, 'rsvp_calendar_date', 'Only the persistent field submits rsvp_date.');
        assert.deepEqual(host.persistent, [selection.input]);
        host.radios = [dayRadio('2026-10-30')];
        selection.sync();
        assert.equal(host.radios[0].checked, true);
    }
);

test('a newly chosen date wins over a later month response, with no default auto-selection',
    /** @returns {void} Month rendering cannot restore the date captured before the request. */
    function latestDateSurvivesCalendarResponse() {
        const host = calendarHost([dayRadio('2026-11-02')]);
        const selection = calendarSelection(host);
        assert.equal(selection.input.value, '');
        assert.equal(host.radios[0].checked, false);
        selection.select(host.radios[0]);
        host.radios = [dayRadio('2026-11-02'), dayRadio('2026-11-03', true)];
        selection.sync();
        assert.equal(selection.input.value, '2026-11-02');
        assert.equal(host.radios[0].checked, true);
        assert.equal(host.radios[1].checked, false);
    }
);

const { revealTimeChoice } = require('../src/shortcode/time-options');

/**
 * Simulate a bounded time list independently of page scroll or server rendering.
 * @param {number} top Tile top.
 * @param {number} bottom Tile bottom.
 * @returns {Object} Input and observable scrolling viewport.
 */
function timeChoice(top, bottom) {
    const viewport = {
        scrollTop: 500,
        /** @returns {Object} Visible region on screen. */
        getBoundingClientRect() { return { top: 100, bottom: 420 }; },
    };
    const tile = {
        /** @returns {Object} Full label bounds, larger than the hidden radio input. */
        getBoundingClientRect() { return { top, bottom }; },
    };
    return { viewport, input: {
        /** @param {string} selector Component selector. @returns {Object} Matching ancestor. */
        closest(selector) { return selector === '.rsvp-time-options-scroll' ? viewport : tile; },
    } };
}

test('keyboard navigation reveals the entire time tile at either list edge',
    /** @returns {void} Keep labels and focus outlines inside the scrolling region. */
    function keyboardVisibility() {
        const below = timeChoice(400, 444);
        revealTimeChoice(below.input);
        assert.equal(below.viewport.scrollTop, 528);
        const above = timeChoice(80, 124);
        revealTimeChoice(above.input);
        assert.equal(above.viewport.scrollTop, 476);
    }
);

test('visible choices retain their scroll position, and small lists are unaffected',
    /** @returns {void} Avoid jumps after AJAX restores a selection. */
    function preserveVisibleSelection() {
        const visible = timeChoice(200, 244);
        revealTimeChoice(visible.input);
        assert.equal(visible.viewport.scrollTop, 500);
        assert.doesNotThrow(
            /** @returns {void} An unselected day needs no scrolling. */
            () => revealTimeChoice(null)
        );
        revealTimeChoice({
            /** @returns {null} A short list has no scrolling ancestor. */
            closest() { return null; },
        });
    }
);
