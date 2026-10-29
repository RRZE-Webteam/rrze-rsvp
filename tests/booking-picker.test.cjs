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

test('a late date response cannot overwrite the latest date or month',
    /** @returns {Promise<void>} Resolve reads in reverse order, as a slow server might. */
    async function latestSelectionWins() {
        const server = transport();
        const request = latestRequest(server.send);
        const first = request({ action: 'UpdateForm', date: '2026-10-30' });
        const second = request({ action: 'UpdateCalendar', month: '2026-11' });
        assert.equal(server.calls[0].signal.aborted, true);
        server.calls[1].resolve('November');
        assert.deepEqual(await second, { status: 'success', data: 'November' });
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
