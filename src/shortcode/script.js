import { latestRequest } from './latest-request';

/**
 * Read calendar HTML or dependent form controls from the existing WP endpoints.
 *
 * @param {Object} payload Request parameters, excluding the shared nonce.
 * @param {AbortSignal} signal Cancellation for a superseded selection.
 * @returns {Promise<string|Object>} Validated endpoint response.
 */
async function sendRequest(payload, signal) {
    const response = await fetch(rsvp_booking.ajax_url, {
        method: 'POST',
        credentials: 'same-origin',
        body: new URLSearchParams({ ...payload, nonce: rsvp_booking.nonce }),
        signal,
    });
    if (!response.ok) throw new Error('Availability request failed');
    const result = payload.action === 'UpdateCalendar' ? await response.text() : await response.json();
    if (payload.action === 'UpdateCalendar' ? !result.includes('class="rsvp-calendar"') : typeof result.time !== 'string') {
        throw new Error('Invalid availability response');
    }
    return result;
}

/**
 * Enhance one booking picker without affecting other forms or global AJAX calls.
 *
 * @param {HTMLFieldSetElement} picker Server-rendered booking controls.
 * @returns {void}
 */
function initializePicker(picker) {
    const form = picker.closest('form');
    const calendarHost = picker.querySelector('.rsvp-date-container');
    const timeHost = picker.querySelector('.rsvp-time-container');
    const seatHost = picker.querySelector('.rsvp-seat-container');
    const status = picker.querySelector('.rsvp-picker-status');
    const retry = picker.querySelector('.rsvp-picker-retry');
    const summary = picker.querySelector('.rsvp-picker-summary');
    const submit = form.querySelector('[type="submit"]');
    const labels = rsvp_booking.picker;
    const request = latestRequest(sendRequest);
    let busy = false;
    let failed = false;
    let retryAction;

    /** @param {string} name Form field name. @returns {HTMLInputElement|HTMLSelectElement|null} Selected control. */
    function selected(name) {
        return picker.querySelector(`input[name="${name}"]:checked, select[name="${name}"]`);
    }

    /** @param {string} name Form field name. @returns {string} Current submitted value. */
    function value(name) {
        return selected(name)?.value || '';
    }

    /** @param {string} name Field name. @returns {string} Human-readable current value. */
    function label(name) {
        const input = selected(name);
        return input?.dataset.label || (input?.value ? input.selectedOptions?.[0]?.textContent : '') || '';
    }

    /** @param {string} name Field name whose old dependent choice is invalidated. @returns {void} */
    function clear(name) {
        const input = selected(name);
        if (!input) return;
        if (input.tagName === 'SELECT') input.value = '';
        else input.checked = false;
    }

    /** @returns {boolean} Whether all required booking choices are complete. */
    function complete() {
        return Boolean(value('rsvp_date') && value('rsvp_time') && (!seatHost || value('rsvp_seat')));
    }

    /** @returns {void} Refresh the visible confirmation and submission readiness. */
    function updateSummary() {
        const ready = complete();
        summary.hidden = !ready || busy || failed;
        if (ready) summary.textContent = `${labels.selection}: ${[label('rsvp_date'), label('rsvp_time'), label('rsvp_seat')].filter(Boolean).join(' · ')}`;
        submit.disabled = busy || failed || !ready;
    }

    /**
     * Preserve the current view during loading, with a recoverable inline failure.
     *
     * @param {Object} payload Endpoint parameters.
     * @param {Function} apply Apply the latest successful response.
     * @returns {Promise<void>}
     */
    async function load(payload, apply) {
        const initialFocus = document.activeElement;
        busy = true;
        failed = false;
        retry.hidden = true;
        status.textContent = labels.loading;
        picker.setAttribute('aria-busy', 'true');
        // Disable dependent controls while keeping date navigation available.
        timeHost.disabled = true;
        if (seatHost) seatHost.disabled = true;
        updateSummary();
        const result = await request(payload);
        if (result.status === 'stale') return;
        busy = false;
        picker.setAttribute('aria-busy', 'false');
        if (result.status === 'error') {
            failed = true;
            status.textContent = labels.error;
            retry.hidden = false;
            /** @returns {Promise<void>} Retry the failed read using the same requested selection. */
            retryAction = () => load(payload, apply);
        } else {
            const focused = document.activeElement === document.body ? initialFocus : document.activeElement;
            const focusId = picker.contains(focused) ? focused.id : '';
            apply(result.data);
            timeHost.disabled = false;
            if (seatHost) seatHost.disabled = false;
            status.textContent = labels.updated;
            // Only restore a control replaced by AJAX, never steal focus from personal data.
            if (focusId && !focused.isConnected) {
                const replacement = Array.from(picker.querySelectorAll('[id]')).find(
                    /** @param {HTMLElement} node Candidate replacement. @returns {boolean} Whether the ID matches. */
                    (node) => node.id === focusId
                );
                replacement?.focus({ preventScroll: true });
            }
        }
        updateSummary();
    }

    /** @returns {void} Request the options belonging to the current date, time and seat. */
    function updateOptions() {
        load({ action: 'UpdateForm', room: form.elements.rsvp_room.value, date: value('rsvp_date'), time: value('rsvp_time'), seat: value('rsvp_seat') },
            /** @param {Object} result Server-rendered dependent controls. @returns {void} */
            function applyOptions(result) {
                timeHost.querySelector('.rsvp-time-select').outerHTML = result.time;
                if (seatHost) seatHost.querySelector('.rsvp-seat-options').innerHTML = result.seat;
            });
    }

    /** @param {Event} event Changed radio or seat select. @returns {void} */
    function changeSelection(event) {
        const name = event.target.name;
        if (!['rsvp_date', 'rsvp_time', 'rsvp_seat'].includes(name)) return;
        if (name === 'rsvp_date') clear('rsvp_time');
        if (name !== 'rsvp_seat') clear('rsvp_seat');
        updateOptions();
    }

    /** @param {MouseEvent} event Month navigation or retry activation. @returns {void} */
    function clickPicker(event) {
        if (event.target.closest('.rsvp-picker-retry')) {
            retryAction?.();
            return;
        }
        const button = event.target.closest('.rsvp-calendar-nav');
        if (!button || button.disabled) return;
        const calendar = calendarHost.querySelector('.rsvp-calendar');
        const direction = button.dataset.direction;
        load({ action: 'UpdateCalendar', room: form.elements.rsvp_room.value, month: calendar.dataset.period, end: calendar.dataset.end, direction },
            /** @param {string} html Adjacent month markup. @returns {void} */
            function applyCalendar(html) {
                const hadFocus = calendar.contains(document.activeElement);
                calendar.outerHTML = html;
                clear('rsvp_time');
                clear('rsvp_seat');
                timeHost.querySelector('.rsvp-time-select').textContent = labels.chooseDay;
                if (seatHost) seatHost.querySelector('.rsvp-seat-options').textContent = labels.chooseTime;
                if (hadFocus) {
                    const nextButton = calendarHost.querySelector(`[data-direction="${direction}"]`);
                    (nextButton.disabled ? calendarHost.querySelector('h3') : nextButton).focus({ preventScroll: true });
                }
            });
    }

    /** @param {SubmitEvent} event Final form submission. @returns {void} */
    function guardSubmission(event) {
        if (busy || failed || !complete()) {
            event.preventDefault();
            status.textContent = failed ? labels.error : labels.incomplete;
        }
    }

    picker.addEventListener('change', changeSelection);
    picker.addEventListener('click', clickPicker);
    form.addEventListener('submit', guardSubmission);
    updateSummary();
}

/** @returns {void} Enhance only actual calendars; check-in-only forms keep their existing flow. */
function initializePickers() {
    document.querySelectorAll('.rsvp-booking-picker').forEach(initializePicker);
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializePickers);
else initializePickers();
