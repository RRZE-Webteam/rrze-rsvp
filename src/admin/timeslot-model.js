/**
 * Parse a calendar date without applying the browser's timezone.
 *
 * @param {string} value ISO date or the existing CMB2 d.m.Y representation.
 * @returns {Date|null} Valid date at UTC midnight, or null.
 */
function parseDate(value) {
    const iso = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
    const legacy = /^(\d{1,2})\.(\d{1,2})\.(\d{4})$/.exec(value);
    if (!iso && !legacy) {
        return null;
    }
    const year = Number(iso ? iso[1] : legacy[3]);
    const month = Number(iso ? iso[2] : legacy[2]);
    const day = Number(iso ? iso[3] : legacy[1]);
    const date = new Date(Date.UTC(year, month - 1, day));
    return year >= 1000 && date.getUTCFullYear() === year
        && date.getUTCMonth() === month - 1 && date.getUTCDate() === day ? date : null;
}

/**
 * Convert a valid calendar date to the native date input format.
 *
 * @param {string} value Calendar date.
 * @returns {string} ISO date, or an empty string for invalid input.
 */
function toInputDate(value) {
    const date = parseDate(value);
    return date ? date.toISOString().slice(0, 10) : '';
}

/**
 * Express a single date using RSVP's existing weekday and validity fields.
 *
 * @param {string} value Calendar date.
 * @returns {{from: string, to: string, weekdays: string[]}} Legacy field values.
 */
function singleDateFields(value) {
    const date = parseDate(value);
    if (!date) {
        return { from: '', to: '', weekdays: [] };
    }
    const iso = toInputDate(value);
    const formatted = iso.slice(8, 10) + '.' + iso.slice(5, 7) + '.' + iso.slice(0, 4);
    return { from: formatted, to: formatted, weekdays: [String(date.getUTCDay() || 7)] };
}

/**
 * Recognize existing single-day rules without rewriting stored values.
 *
 * @param {{from: string, to: string, weekdays: string[]}} fields Legacy fields.
 * @returns {boolean} Whether the rule offers exactly one matching date.
 */
function isSingleDate(fields) {
    const day = singleDateFields(fields.from);
    return Boolean(day.from) && toInputDate(fields.from) === toInputDate(fields.to)
        && fields.weekdays.includes(day.weekdays[0]);
}

/**
 * Validate a changed schedule before submitting its legacy fields.
 *
 * Empty rows are allowed so a room can be drafted before its schedule is known.
 * Unchanged saved rules are exempted by the UI to preserve legacy behavior.
 *
 * @param {{from: string, to: string, weekdays: string[], start: string, end: string}} fields Legacy fields.
 * @param {string} mode Either single or weekly.
 * @returns {string} Translation key for the first error, or an empty string.
 */
function validateTimeslot(fields, mode) {
    if (!fields.start && !fields.end && !fields.from && !fields.to && !fields.weekdays.length) {
        return '';
    }
    if (mode === 'single' && !isSingleDate(fields)) {
        return 'dateRequired';
    }
    if (mode === 'weekly' && !fields.weekdays.length) {
        return 'weekdayRequired';
    }
    if ((fields.from && !parseDate(fields.from)) || (fields.to && !parseDate(fields.to))) {
        return 'invalidDate';
    }
    if (fields.from && fields.to && toInputDate(fields.from) > toInputDate(fields.to)) {
        return 'dateOrder';
    }
    const time = /^(?:[01]\d|2[0-3]):[0-5]\d$/;
    if (!time.test(fields.start) || !time.test(fields.end)) {
        return 'timeRequired';
    }
    return fields.end <= fields.start ? 'timeOrder' : '';
}

module.exports = { parseDate, toInputDate, singleDateFields, isSingleDate, validateTimeslot };
