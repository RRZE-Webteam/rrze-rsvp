import { parseDate, toInputDate, singleDateFields, isSingleDate, validateTimeslot } from './timeslot-model';

/**
 * Enhance CMB2's existing schedule fields without changing their storage schema.
 *
 * @param {JQueryStatic} $ WordPress jQuery instance.
 * @returns {void}
 */
jQuery(function initializeTimeslots($) {
    const group = $('#rrze-rsvp-room-timeslots_repeat');
    const labels = rrze_rsvp_admin.timeslots;
    if (!group.length || !labels) {
        return;
    }
    const states = new WeakMap();
    let nextId = 0;

    /**
     * Read the original named inputs, including disabled protected fields.
     * @param {JQuery} row CMB2 group row.
     * @returns {Object} Legacy schedule values.
     */
    function readFields(row) {
        return {
            from: row.find('input[name$="[rrze-rsvp-room-timeslot-valid-from]"]').val() || '',
            to: row.find('input[name$="[rrze-rsvp-room-timeslot-valid-to]"]').val() || '',
            start: row.find('input[name$="[rrze-rsvp-room-starttime]"]').val() || '',
            end: row.find('input[name$="[rrze-rsvp-room-endtime]"]').val() || '',
            weekdays: row.find('input[type="checkbox"]:checked').map(
                /** @returns {string} Selected ISO weekday. */
                function selectedWeekday() { return this.value; }
            ).get(),
        };
    }

    /**
     * Update only weekday and validity fields, preserving both time inputs.
     * @param {JQuery} row CMB2 group row.
     * @param {Object} fields Legacy date fields.
     * @returns {void}
     */
    function writeDates(row, fields) {
        row.find('input[name$="[rrze-rsvp-room-timeslot-valid-from]"]').val(fields.from);
        row.find('input[name$="[rrze-rsvp-room-timeslot-valid-to]"]').val(fields.to);
        row.find('input[type="checkbox"]').each(
            /** @returns {void} Restore a weekday's selection. */
            function updateWeekday() { this.checked = fields.weekdays.includes(this.value); }
        );
    }

    /**
     * Format dates in the admin locale, keeping the calendar date timezone-free.
     * @param {string} value Calendar date.
     * @returns {string} Localized date, or an empty string.
     */
    function formatDate(value) {
        const date = parseDate(value);
        return date ? date.toLocaleDateString(document.documentElement.lang || 'de', {
            weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'UTC',
        }) : '';
    }

    /**
     * Update the readable summary and an accessible inline validation message.
     * @param {JQuery} row CMB2 group row.
     * @param {boolean} showError Whether to reveal validation errors.
     * @returns {boolean} Whether this row may be submitted.
     */
    function refresh(row, showError = false) {
        const state = states.get(row[0]);
        const fields = readFields(row);
        let summary;
        if (state.mode.val() === 'single') {
            summary = formatDate(fields.from) || labels.chooseDate;
        } else {
            const names = row.find('input[type="checkbox"]:checked').map(
                /** @returns {string} Visible weekday label. */
                function weekdayLabel() { return $(this).siblings('label').text(); }
            ).get();
            summary = names.length ? labels.weekly + ': ' + names.join(', ') : labels.chooseWeekdays;
            summary += fields.from ? ' · ' + labels.from + ' ' + formatDate(fields.from) : '';
            summary += fields.to ? ' · ' + labels.until + ' ' + formatDate(fields.to) : '';
            summary += !fields.from && !fields.to ? ' · ' + labels.unlimited : '';
        }
        if (fields.start && fields.end) {
            summary += ' · ' + fields.start + '–' + fields.end;
        }
        state.summary.text(summary);
        const unchanged = state.saved && state.original === JSON.stringify(fields);
        const error = unchanged ? '' : validateTimeslot(fields, state.mode.val());
        state.error.text(showError && error ? labels[error] : '').prop('hidden', !(showError && error));
        row.toggleClass('rsvp-timeslot-invalid', Boolean(showError && error));
        return !error;
    }

    /**
     * Switch visible controls while keeping each mode's unfinished dates intact.
     * @param {JQuery} row CMB2 group row.
     * @param {boolean} changed Whether this is an explicit user mode change.
     * @returns {void}
     */
    function applyMode(row, changed) {
        const state = states.get(row[0]);
        const single = state.mode.val() === 'single';
        if (changed) {
            if (single) {
                state.weekly = readFields(row);
                writeDates(row, singleDateFields(state.date.val()));
            } else {
                writeDates(row, state.weekly);
            }
        }
        row.find('.cmb-type-multicheck, .cmb-type-text-date-timestamp').prop('hidden', single);
        state.date.closest('.rsvp-timeslot-date').prop('hidden', !single);
        state.help.text(single ? labels.singleHelp : labels.weeklyHelp);
        refresh(row);
    }

    /**
     * Give cloned controls unique IDs after CMB2 reindexes removed rows by name only.
     *
     * Datepickers cache the input ID, so reattach them with their existing options.
     * Names remain untouched because they are the contract with CMB2's persistence.
     *
     * @param {JQuery} row New CMB2 group row.
     * @param {string} prefix Unique UI row ID.
     * @returns {void}
     */
    function identifyNewInputs(row, prefix) {
        row.find('input[id]').each(
            /** @param {number} index Input position. @returns {void} */
            function identifyInput(index) {
                const input = $(this);
                const oldId = this.id;
                const newId = prefix + '-field-' + index;
                const pickerOptions = input.hasClass('hasDatepicker')
                    ? input.datepicker('option', 'all') : null;
                if (pickerOptions) {
                    input.datepicker('destroy');
                }
                row.find('label[for="' + oldId + '"]').attr('for', newId);
                input.attr('id', newId);
                if (pickerOptions) {
                    input.datepicker(pickerOptions);
                }
            }
        );
    }

    /**
     * Initialize one existing or newly cloned CMB2 group with presentation controls.
     * @param {JQuery} row CMB2 group row.
     * @param {boolean} fresh Whether the row has no persisted schedule.
     * @returns {void}
     */
    function initializeRow(row, fresh) {
        if (states.has(row[0])) {
            return;
        }
        row.find('.rsvp-timeslot-ui').remove(); // CMB2 clones markup, not our state.
        row.addClass('rsvp-timeslot');
        const inside = row.children('.inside');
        if (fresh) {
            row.find('input, button.cmb-remove-group-row').prop('disabled', false);
            row.find('input:not([type="checkbox"])').val('');
            row.find('input[type="checkbox"]').prop('checked', false);
        }
        const fields = readFields(row);
        row.find('input[name$="[rrze-rsvp-room-starttime]"], input[name$="[rrze-rsvp-room-endtime]"]').each(
            /** @returns {void} Use native time controls without changing unusual legacy values. */
            function enhanceTimeInput() {
                const input = $(this);
                if (input.val() && !/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(input.val())) {
                    return;
                }
                if ($.fn.timepicker && input.hasClass('hasDatepicker')) {
                    input.timepicker('destroy');
                }
                input.removeClass('cmb2-timepicker').attr({ type: 'time', step: 60 });
            }
        );
        const locked = row.find('input[name$="[rrze-rsvp-room-starttime]"]').prop('disabled');
        const id = 'rsvp-timeslot-' + nextId++;
        if (fresh) {
            identifyNewInputs(row, id);
        }
        const mode = $('<select>', { id: id + '-mode', class: 'rsvp-timeslot-mode', disabled: locked })
            .append($('<option>', { value: 'single', text: labels.single }))
            .append($('<option>', { value: 'weekly', text: labels.weekly }))
            .val(fresh || isSingleDate(fields) ? 'single' : 'weekly');
        const help = $('<p>', { id: id + '-help', class: 'description' });
        mode.attr('aria-describedby', help.attr('id'));
        const modeRow = $('<div>', { class: 'rsvp-timeslot-ui rsvp-timeslot-mode-row' })
            .append($('<label>', { for: mode.attr('id'), text: labels.type }), mode, help);
        const date = $('<input>', { type: 'date', id: id + '-date', class: 'rsvp-timeslot-day', disabled: locked })
            .val(toInputDate(fields.from));
        const dateRow = $('<div>', { class: 'rsvp-timeslot-ui rsvp-timeslot-date' })
            .append($('<label>', { for: date.attr('id'), text: labels.date }), date);
        const summary = $('<p>', { class: 'rsvp-timeslot-ui rsvp-timeslot-summary', 'aria-live': 'polite' });
        const error = $('<p>', { class: 'rsvp-timeslot-ui rsvp-timeslot-error', role: 'alert', hidden: true });
        inside.prepend(modeRow, dateRow);
        inside.find('.cmb-remove-field-row').before(summary, error);
        if (locked) {
            inside.append($('<p>', { class: 'rsvp-timeslot-ui rsvp-timeslot-locked', text: labels.locked }));
        }
        states.set(row[0], { mode, date, help, summary, error, saved: !fresh,
            original: JSON.stringify(fields), weekly: fields, locked });
        row.find('input[name$="[rrze-rsvp-room-starttime]"]').attr('placeholder', '09:00');
        row.find('input[name$="[rrze-rsvp-room-endtime]"]').attr('placeholder', '10:00');
        applyMode(row, false);
    }

    /** @returns {void} Initialize server-rendered rows without mutating saved rules. */
    group.find('.cmb-repeatable-grouping').each(function initializeExistingRow() {
        initializeRow($(this), !labels.hasSavedTimeslots);
    });

    /**
     * Initialize fresh controls after CMB2 has reset cloned native field values.
     * @param {JQuery.Event} event CMB2 event.
     * @param {JQuery} row Added group row.
     * @returns {void}
     */
    $(document).on('cmb2_add_row', '#rrze-rsvp-room-timeslots_repeat', function addTimeslot(event, row) {
        if (event.group) {
            initializeRow($(row), true);
        }
    });

    /** @param {JQuery.Event} event Mode selection event. @returns {void} */
    group.on('change', '.rsvp-timeslot-mode', function changeMode(event) {
        applyMode($(event.target).closest('.cmb-repeatable-grouping'), true);
    });

    /** @param {JQuery.Event} event Input event. @returns {void} */
    group.on('input change', '.cmb-repeatable-grouping input', function updateTimeslot(event) {
        const row = $(event.target).closest('.cmb-repeatable-grouping');
        const state = states.get(row[0]);
        if (!state) {
            return;
        }
        if ($(event.target).hasClass('rsvp-timeslot-day') && !state.locked) {
            writeDates(row, singleDateFields(state.date.val()));
        }
        refresh(row, true);
    });

    /**
     * Reject incomplete edited rows before other handlers re-enable protected fields.
     * @param {SubmitEvent} event Post form submission.
     * @returns {void}
     */
    function validateForm(event) {
        let invalid = null;
        group.find('.cmb-repeatable-grouping').each(
            /** @returns {void} Find the first invalid row while revealing all errors. */
            function validateRow() {
                if (!refresh($(this), true) && !invalid) {
                    invalid = $(this);
                }
            }
        );
        if (invalid) {
            event.preventDefault();
            event.stopImmediatePropagation();
            invalid.removeClass('closed');
            invalid[0].scrollIntoView({ block: 'center' });
            invalid.find('input:visible:enabled').first().trigger('focus');
        }
    }
    group.closest('form')[0].addEventListener('submit', validateForm, true);
});
