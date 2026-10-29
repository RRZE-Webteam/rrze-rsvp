/**
 * Cancel obsolete reads and ignore late responses even if transport ignores abort.
 *
 * @param {Function} send Async transport accepting a payload and AbortSignal.
 * @returns {Function} Request function returning success, error or stale results.
 */
function latestRequest(send) {
    let revision = 0;
    let controller;

    /**
     * Run a read whose result may only update the latest selection.
     *
     * @param {Object} payload Calendar or availability request.
     * @returns {Promise<Object>} Result tagged with its applicability.
     */
    return async function request(payload) {
        const current = ++revision;
        if (controller) controller.abort();
        controller = new AbortController();
        try {
            const data = await send(payload, controller.signal);
            return current === revision ? { status: 'success', data } : { status: 'stale' };
        } catch (error) {
            return current === revision ? { status: 'error', error } : { status: 'stale' };
        }
    };
}

module.exports = { latestRequest };
