import { parseDate } from './timeslot-model';
import { readClosedDays, toggleClosedDate, monthGrid } from './closed-days-model';

/**
 * Add an accessible calendar while keeping the original textarea as stored data.
 *
 * @param {JQueryStatic} $ WordPress jQuery instance.
 * @returns {void}
 */
jQuery(function initializeClosedDays($) {
    const textarea = $('#rrze-rsvp-room-days-closed');
    const labels = rrze_rsvp_admin.closedDays;
    if (!textarea.length || !labels) {
        return;
    }
    const locale = document.documentElement.lang || 'de';
    let selection = readClosedDays(textarea.val());
    const initialDate = selection.dates.find(
        /** @param {string} date Selected date. @returns {boolean} Whether it is still upcoming. */
        function upcomingDate(date) { return date >= labels.today; }
    ) || selection.dates[0] || labels.today;
    const month = parseDate(initialDate);
    month.setUTCDate(1);
    let focusedDate = initialDate;

    textarea.closest('.cmb-row').addClass('rsvp-closed-days-field');
    const ui = $('<div>', { class: 'rsvp-closed-days' });
    const help = $('<p>', { id: 'rsvp-closed-days-help', class: 'description', text: labels.help });
    const layout = $('<div>', { class: 'rsvp-closed-days-layout' });
    const calendar = $('<div>', { class: 'rsvp-closed-calendar', role: 'group',
        'aria-label': labels.calendar, 'aria-describedby': help.attr('id') });
    const header = $('<div>', { class: 'rsvp-closed-calendar-header' });
    const previous = $('<button>', { type: 'button', class: 'button', text: '‹', 'aria-label': labels.previous });
    const next = $('<button>', { type: 'button', class: 'button', text: '›', 'aria-label': labels.next });
    const heading = $('<h4>', { id: 'rsvp-closed-calendar-month', 'aria-live': 'polite' });
    header.append(previous, heading, next);
    const table = $('<table>', { class: 'rsvp-closed-calendar-grid', 'aria-labelledby': heading.attr('id') });
    const weekdays = $('<tr>');
    for (let offset = 0; offset < 7; offset++) {
        const day = new Date(Date.UTC(2026, 10, 1 + (labels.firstDay + offset) % 7));
        weekdays.append($('<th>', { scope: 'col',
            text: day.toLocaleDateString(locale, { weekday: 'short', timeZone: 'UTC' }),
            title: day.toLocaleDateString(locale, { weekday: 'long', timeZone: 'UTC' }) }));
    }
    const body = $('<tbody>');
    table.append($('<thead>').append(weekdays), body);
    const today = $('<button>', { type: 'button', class: 'button-link', text: labels.currentMonth });
    calendar.append(header, table, today);
    const overview = $('<div>', { class: 'rsvp-closed-days-overview' });
    const count = $('<h4>', { 'aria-live': 'polite' });
    const list = $('<ul>', { class: 'rsvp-closed-days-list', 'aria-label': labels.selected });
    const empty = $('<p>', { text: labels.empty });
    overview.append(count, empty, list);
    const warning = $('<p>', { id: 'rsvp-closed-days-warning', class: 'rsvp-closed-days-warning',
        role: 'status', hidden: true, text: labels.invalid });
    layout.append(calendar, overview);
    ui.append(help, layout, warning);
    textarea.before(ui, $('<label>', { class: 'rsvp-closed-days-text-label',
        for: textarea.attr('id'), text: labels.textList }));
    textarea.attr('aria-describedby', [textarea.attr('aria-describedby'), warning.attr('id')].filter(Boolean).join(' '));

    /**
     * Format an ISO date in the admin language without changing its calendar day.
     * @param {string} date ISO calendar date.
     * @param {boolean} long Whether to include the weekday and full month name.
     * @returns {string} Readable date label.
     */
    function formatDate(date, long = false) {
        return parseDate(date).toLocaleDateString(locale, {
            day: '2-digit', month: long ? 'long' : '2-digit', year: 'numeric',
            ...(long ? { weekday: 'long' } : {}), timeZone: 'UTC',
        });
    }

    /**
     * Render both views of the text value without modifying the textarea itself.
     * @returns {void}
     */
    function render() {
        selection = readClosedDays(textarea.val());
        const dates = new Set(selection.dates);
        heading.text(month.toLocaleDateString(locale, { month: 'long', year: 'numeric', timeZone: 'UTC' }));
        previous.prop('disabled', month.getUTCFullYear() === 1000 && month.getUTCMonth() === 0);
        next.prop('disabled', month.getUTCFullYear() === 9999 && month.getUTCMonth() === 11);
        const cells = monthGrid(month.toISOString().slice(0, 10), labels.firstDay);
        if (!cells.includes(focusedDate)) {
            focusedDate = cells.includes(labels.today) ? labels.today : cells.find(Boolean);
        }
        body.empty();
        let week;
        cells.forEach(
            /** @param {string|null} date Cell date. @param {number} index Cell position. @returns {void} */
            function renderDay(date, index) {
                if (index % 7 === 0) {
                    week = $('<tr>').appendTo(body);
                }
                const cell = $('<td>').appendTo(week);
                if (date) {
                    const button = $('<button>', { type: 'button', 'data-date': date,
                        text: Number(date.slice(-2)), 'aria-label': formatDate(date, true),
                        'aria-pressed': String(dates.has(date)), tabindex: date === focusedDate ? 0 : -1 });
                    if (date === labels.today) {
                        button.attr('aria-current', 'date');
                    }
                    cell.append(button);
                }
            }
        );
        count.text(labels.selected + ' (' + dates.size + ')');
        empty.prop('hidden', dates.size !== 0);
        warning.prop('hidden', selection.invalidLines.length === 0);
        list.empty();
        for (const date of selection.dates) {
            const remove = $('<button>', { type: 'button', class: 'rsvp-closed-day-remove',
                'data-date': date, 'aria-label': labels.remove.replace('%s', formatDate(date, true)) })
                .append($('<span>', { text: formatDate(date) }), $('<span>', { text: '×', 'aria-hidden': 'true' }));
            list.append($('<li>').append(remove));
        }
    }

    /**
     * Apply a calendar change to the existing input and notify its usual listeners.
     * @param {string} date ISO date to add or remove.
     * @returns {void}
     */
    function toggleDate(date) {
        textarea.val(toggleClosedDate(textarea.val(), date)).trigger('change');
    }

    /** @returns {void} Show the preceding month while retaining header focus. */
    previous.on('click', function previousMonth() {
        month.setUTCMonth(month.getUTCMonth() - 1);
        render();
    });
    /** @returns {void} Show the following month while retaining header focus. */
    next.on('click', function nextMonth() {
        month.setUTCMonth(month.getUTCMonth() + 1);
        render();
    });
    /** @returns {void} Return to the current month in the WordPress site timezone. */
    today.on('click', function currentMonth() {
        const date = parseDate(labels.today);
        month.setUTCFullYear(date.getUTCFullYear(), date.getUTCMonth(), 1);
        focusedDate = labels.today;
        render();
    });
    /** @returns {void} Toggle a date and restore focus after updating its button. */
    body.on('click', 'button[data-date]', function selectDay() {
        focusedDate = this.dataset.date;
        toggleDate(focusedDate);
        body.find('[data-date="' + focusedDate + '"]').trigger('focus');
    });
    /** @param {JQuery.Event} event Keyboard event. @returns {void} */
    body.on('keydown', 'button[data-date]', function navigateDays(event) {
        const steps = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 };
        if (!Object.prototype.hasOwnProperty.call(steps, event.key)) {
            return;
        }
        event.preventDefault();
        const date = parseDate(this.dataset.date);
        date.setUTCDate(date.getUTCDate() + steps[event.key]);
        if (date.getUTCFullYear() < 1000 || date.getUTCFullYear() > 9999) {
            return;
        }
        focusedDate = date.toISOString().slice(0, 10);
        month.setUTCFullYear(date.getUTCFullYear(), date.getUTCMonth(), 1);
        render();
        body.find('[data-date="' + focusedDate + '"]').trigger('focus');
    });
    /** @returns {void} Remove a listed date without losing keyboard focus. */
    list.on('click', 'button[data-date]', function removeDay() {
        const index = list.find('button').index(this);
        toggleDate(this.dataset.date);
        const remaining = list.find('button');
        if (remaining.length) {
            remaining.eq(Math.min(index, remaining.length - 1)).trigger('focus');
        } else {
            today.trigger('focus');
        }
    });
    textarea.on('input change', render);
    render();
});
