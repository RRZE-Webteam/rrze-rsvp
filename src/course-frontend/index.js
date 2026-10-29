import './style.scss';

const { isPendingOccurrence, requestAttempt } = require('./request-model');
const config = window.rrzeRsvpCourses;

/**
 * Attach one live registration form with stable retries and accessible feedback.
 *
 * @param {HTMLFormElement} form Server-rendered course form.
 * @param {number} index Position distinguishing repeated blocks on the same page.
 * @returns {void}
 */
function attachForm(form, index) {
    const result = form.querySelector('.rrze-rsvp-course-result');
    const button = form.querySelector('.rrze-rsvp-course-submit');
    const cards = Array.from(form.querySelectorAll('.rrze-rsvp-course-date'));
    const allowed = form.dataset.formAllowed === '1';
    const storageKey = 'rrze-rsvp-course-attempt:' + window.location.pathname + ':' + form.dataset.courseId + ':' + index;
    let submitting = false;
    let completed = false;
    let pending = null;
    let lastRefresh = 0;

    try { pending = JSON.parse(window.sessionStorage.getItem(storageKey)); } catch (error) { /* Storage may be disabled. */ }

    /**
     * Display plain text without interpreting server or browser errors as HTML.
     *
     * @param {string} text Translated message.
     * @param {boolean} error Whether the message needs urgent announcement.
     * @param {boolean} focus Whether to move keyboard focus after an action.
     * @returns {void}
     */
    function announce(text, error = false, focus = true) {
        result.setAttribute('role', error ? 'alert' : 'status');
        result.setAttribute('aria-live', error ? 'assertive' : 'polite');
        result.classList.toggle('is-error', error);
        result.textContent = text;
        if (focus) { result.focus(); }
    }

    /** @returns {void} Persist only request identity, never raw participant fields. */
    function persistAttempt() {
        try { window.sessionStorage.setItem(storageKey, JSON.stringify(pending)); } catch (error) { /* In-memory retry remains available. */ }
    }

    /** @returns {void} Discard an acknowledged request after a successful response. */
    function clearAttempt() {
        pending = null;
        try { window.sessionStorage.removeItem(storageKey); } catch (error) { /* No persistence available. */ }
    }

    /**
     * Keep an uncertain date selectable while distinguishing retries from bookings.
     *
     * @param {HTMLElement} card Rendered occurrence card.
     * @param {boolean} bookable Current availability for a new registration.
     * @param {boolean} restore Whether to restore the pending selection after reload.
     * @returns {void}
     */
    function updateSelection(card, bookable, restore = false) {
        const radio = card.querySelector('input');
        const retry = isPendingOccurrence(pending, Number(card.dataset.occurrenceId));
        radio.dataset.bookable = bookable ? '1' : '0';
        radio.disabled = !allowed || (!bookable && !retry);
        if (allowed && retry && restore) {
            radio.checked = true;
            announce(config.labels.retryDetails, false, false);
        }
        if (radio.checked && radio.disabled) {
            radio.checked = false;
            announce(config.labels.unavailable, true, false);
        }
    }

    /**
     * Read live availability without exposing or fetching participant records.
     *
     * @returns {Promise<void>}
     */
    async function refresh() {
        if (submitting || completed) { return; }
        lastRefresh = Date.now();
        try {
            const response = await window.fetch(config.restUrl + 'courses/' + form.dataset.courseId + '/occurrences', {
                credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': config.restNonce },
            });
            if (!response.ok) { throw new Error(config.labels.refreshError); }
            const body = await response.json();
            if (!Array.isArray(body.occurrences)) { throw new Error(config.labels.refreshError); }
            if (submitting || completed) { return; }
            cards.forEach(
                /** @param {HTMLElement} card Existing date card, preserving focus and input. @returns {void} */
                function updateCard(card) {
                    /** @param {Object} date Safe occurrence DTO. @returns {boolean} */
                    function sameDate(date) { return String(date.occurrence_id) === card.dataset.occurrenceId; }
                    const date = body.occurrences.find(sameDate);
                    updateSelection(card, Boolean(date && date.bookable));
                    card.querySelector('.rrze-rsvp-course-availability').textContent = date ? date.message : config.labels.unavailable;
                    if (date) {
                        card.querySelector('strong').textContent = date.label;
                        card.querySelector('.rrze-rsvp-course-location').textContent = date.location;
                    }
                }
            );
        } catch (error) {
            if (!result.textContent && !completed && !submitting) { announce(config.labels.refreshError, false, false); }
        }
    }

    /**
     * Submit one immutable payload, retaining its ID on uncertain network results.
     *
     * @param {SubmitEvent} event Native submit event.
     * @returns {Promise<void>}
     */
    async function submit(event) {
        event.preventDefault();
        if (submitting || completed || !allowed || !form.reportValidity()) { return; }
        const selected = form.querySelector('input[name="occurrenceId"]:checked:not(:disabled)');
        if (!selected) { announce(config.labels.chooseDate, true); return; }
        const fields = new FormData(form);
        const trusted = form.dataset.trusted === '1';
        const participant = { consent: fields.get('consent') === 'on' };
        if (!trusted) {
            ['firstname', 'lastname', 'email'].forEach(
                /** @param {string} name Guest identity field. @returns {void} */
                function readField(name) { participant[name] = String(fields.get(name) || '').trim(); }
            );
        }
        const payload = { occurrenceId: Number(selected.value), participant };
        submitting = true;
        form.setAttribute('aria-busy', 'true');
        button.disabled = true;
        button.textContent = config.labels.submitting;
        try {
            const attempt = await requestAttempt({ ...payload, trusted }, pending, window.crypto, selected.dataset.bookable !== '1');
            if (!attempt) { announce(config.labels.retryDetails, true); return; }
            pending = attempt;
            persistAttempt();
            const response = await window.fetch(config.restUrl + 'course-bookings', {
                method: 'POST', credentials: 'same-origin', cache: 'no-store',
                headers: { 'Content-Type': 'application/json', 'X-RSVP-Nonce': config.nonce, 'X-WP-Nonce': config.restNonce },
                body: JSON.stringify({ ...payload, requestId: pending.requestId }),
            });
            let body;
            try { body = await response.json(); } catch (error) { throw new Error(config.labels.networkError); }
            if (!response.ok) {
                // A structured REST rejection is certain; transport errors are not.
                if (body.code) { clearAttempt(); }
                throw new Error(body.message || config.labels.networkError);
            }
            completed = true;
            clearAttempt();
            form.querySelectorAll('input, button').forEach(
                /** @param {HTMLInputElement} control Submitted form control. @returns {void} */
                function disableControl(control) { control.disabled = true; }
            );
            announce(body.message || config.labels.success);
        } catch (error) {
            announce(error instanceof TypeError ? config.labels.networkError : (error.message || config.labels.networkError), true);
        } finally {
            submitting = false;
            form.removeAttribute('aria-busy');
            button.disabled = completed;
            button.textContent = config.labels.submit;
            if (!completed) { refresh(); }
        }
    }

    /** @returns {void} Refresh an old page when the participant returns to it. */
    function onFocus() {
        if (Date.now() - lastRefresh > 15000) { refresh(); }
    }

    form.addEventListener('submit', submit);
    cards.forEach(
        /** @param {HTMLElement} card Initial server-rendered state. @returns {void} */
        function restoreSelection(card) { updateSelection(card, !card.querySelector('input').disabled, true); }
    );
    if (button && allowed) { button.disabled = false; }
    window.addEventListener('focus', onFocus);
    refresh();
}

if (config && window.fetch && window.crypto && window.crypto.subtle) {
    document.querySelectorAll('.rrze-rsvp-course-form').forEach(attachForm);
}
