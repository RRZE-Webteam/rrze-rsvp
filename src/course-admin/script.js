/**
 * Keep occurrence saves independent of the surrounding WordPress editor form.
 * The external forms remain usable through admin-post.php without JavaScript.
 */
(function () {
    'use strict';

    const settings = window.rsvpCourseAdmin;
    if (!settings) {
        return;
    }

    /**
     * Locate the region associated with a stable date or booking ID.
     *
     * @param {HTMLFormElement} form External operation form.
     * @returns {Element|null} Visible operation region.
     */
    function regionFor(form) {
        const attribute = form.dataset.operation.endsWith('_booking')
            ? 'data-course-booking-status' : 'data-course-date';
        return document.querySelector('[' + attribute + '="' + form.dataset.target + '"]');
    }

    /**
     * Announce feedback without interpreting server messages as HTML.
     *
     * @param {Element|null} region Related row or status container.
     * @param {string} message Translated plain-text message.
     * @param {boolean} error Whether this is a validation or transport error.
     * @returns {void}
     */
    function announce(region, message, error = false) {
        const feedback = region && region.querySelector('.rsvp-course-feedback');
        if (feedback) {
            feedback.textContent = message;
            feedback.classList.toggle('rsvp-course-error', error);
        }
    }

    /**
     * Refresh operation nonces and forms without nesting them in editor markup.
     *
     * @param {string} html Trusted escaped markup rendered by the admin endpoint.
     * @returns {void}
     */
    function replaceForms(html) {
        const template = document.createElement('template');
        template.innerHTML = html || '';
        Array.from(template.content.querySelectorAll('form.rsvp-course-action-form')).forEach(
            /** @param {HTMLFormElement} form Fresh footer form. */
            function (form) {
                const existing = document.getElementById(form.id);
                if (existing) {
                    existing.replaceWith(form);
                } else {
                    document.body.appendChild(form);
                }
            }
        );
    }

    /**
     * Apply a successful operation to only the affected row.
     *
     * @param {HTMLFormElement} form Submitted external form.
     * @param {Object} result Successful server payload.
     * @returns {void}
     */
    function applyResult(form, result) {
        const region = regionFor(form);
        replaceForms(result.forms_html);
        if (form.dataset.operation.endsWith('_booking')) {
            document.querySelectorAll('[data-course-booking-status="' + form.dataset.target + '"]').forEach(
                /** @param {Element} status One matching status region. */
                function (status) {
                    status.innerHTML = result.status_html;
                    announce(status, result.message);
                }
            );
        } else if (region && result.operation === 'delete_date') {
            region.remove();
            const next = document.querySelector('[data-course-date="0"]');
            announce(next, result.message);
        } else if (region) {
            const template = document.createElement('template');
            template.innerHTML = (result.row_html || '') + (result.new_html || '');
            const updated = template.content.querySelector('.rsvp-course-date');
            region.replaceWith(template.content);
            announce(updated, result.message);
        }
    }

    /**
     * Submit an authorized course operation while preserving all unsaved editor data.
     *
     * @param {SubmitEvent} event Native submit event, including external-form controls.
     * @returns {Promise<void>} Completion of the operation.
     */
    async function submitOperation(event) {
        const form = event.target;
        if (!form.matches('.rsvp-course-action-form')) {
            return;
        }
        event.preventDefault();
        const region = regionFor(form);
        if (!region || region.getAttribute('aria-busy') === 'true') {
            return;
        }
        const confirmation = event.submitter && event.submitter.dataset.courseConfirm;
        if (confirmation && !window.confirm(confirmation)) {
            return;
        }
        const payload = new FormData(form);
        const buttons = Array.from(region.querySelectorAll('button[type="submit"]'));
        const previousDisabled = buttons.map(
            /** @param {HTMLButtonElement} button Operation button. @returns {boolean} Previous disabled state. */
            function (button) { return button.disabled; }
        );
        buttons.forEach(
            /** @param {HTMLButtonElement} button Operation button. */
            function (button) { button.disabled = true; }
        );
        region.setAttribute('aria-busy', 'true');
        region.querySelectorAll('[aria-invalid="true"]').forEach(
            /** @param {HTMLElement} input Previously invalid input. */
            function (input) { input.removeAttribute('aria-invalid'); }
        );
        announce(region, settings.saving);
        try {
            const response = await fetch(settings.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: payload,
            });
            const result = await response.json();
            if (!result.success || !response.ok) {
                const error = result.data || {};
                announce(region, error.message || settings.failed, true);
                const fields = ['start', 'end', 'registration_start', 'registration_end', 'capacity', 'room_id', 'location'];
                if (fields.includes(error.field)) {
                    const input = region.querySelector('[name="occurrence[' + error.field + ']"]');
                    if (input) {
                        input.setAttribute('aria-invalid', 'true');
                        input.focus();
                    }
                }
                return;
            }
            applyResult(form, result.data);
        } catch (error) {
            announce(region, settings.failed, true);
        } finally {
            region.removeAttribute('aria-busy');
            buttons.forEach(
                /** @param {HTMLButtonElement} button Operation button. @param {number} index Previous state index. */
                function (button, index) { button.disabled = previousDisabled[index]; }
            );
        }
    }

    /**
     * Avoid keeping a date filter from a different course after changing courses.
     *
     * @param {Event} event Filter change.
     * @returns {void}
     */
    function changeCourseFilter(event) {
        if (event.target.id === 'rsvp-course-filter') {
            const dates = document.getElementById('rsvp-course-date-filter');
            if (dates) {
                dates.value = '0';
            }
        }
    }

    document.addEventListener('submit', submitOperation);
    document.addEventListener('change', changeCourseFilter);
}());
