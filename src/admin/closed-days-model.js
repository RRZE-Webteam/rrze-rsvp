const { toInputDate, parseDate } = require('./timeslot-model');

/**
 * Recognize exactly the ISO dates understood by the existing availability checks.
 *
 * Whitespace and other formats must not appear selected: the PHP reader does not
 * trim or normalize them either. Carriage returns are removed by both readers.
 *
 * @param {string} text Original newline-separated metadata.
 * @returns {{dates: string[], invalidLines: string[]}} Unique sorted dates and unrecognized lines.
 */
function readClosedDays(text) {
    const dates = new Set();
    const invalidLines = [];
    for (const line of text.replace(/\r/g, '').split('\n')) {
        if (!line) {
            continue;
        }
        if (line === toInputDate(line)) {
            dates.add(line);
        } else {
            invalidLines.push(line);
        }
    }
    return { dates: Array.from(dates).sort(), invalidLines };
}

/**
 * Toggle one closed day without rewriting unrelated lines or discarding old data.
 *
 * All duplicates of a removed date are deleted so the day actually reopens.
 * Adding a date preserves the existing newline convention and text order.
 *
 * @param {string} text Original textarea content.
 * @param {string} date Strict ISO calendar date.
 * @returns {string} Updated legacy metadata.
 */
function toggleClosedDate(text, date) {
    if (!date || toInputDate(date) !== date) {
        return text;
    }
    if (readClosedDays(text).dates.includes(date)) {
        return text.split('\n').filter(
            /** @param {string} line Original line. @returns {boolean} Whether to retain it. */
            function retainOtherDate(line) { return line.replace(/\r/g, '') !== date; }
        ).join('\n');
    }
    const newline = text.includes('\r\n') ? '\r\n' : '\n';
    return text + (text && !text.endsWith('\n') ? newline : '') + date;
}

/**
 * Build complete calendar weeks using UTC to avoid daylight-saving date shifts.
 *
 * @param {string} month Any ISO date in the requested month.
 * @param {number} firstDay WordPress start_of_week value (Sunday = 0).
 * @returns {(string|null)[]} Dates padded with empty cells to complete each week.
 */
function monthGrid(month, firstDay) {
    const date = parseDate(month);
    if (!date) {
        return [];
    }
    date.setUTCDate(1);
    const monthIndex = date.getUTCMonth();
    const cells = Array((date.getUTCDay() - firstDay + 7) % 7).fill(null);
    while (date.getUTCMonth() === monthIndex) {
        cells.push(date.toISOString().slice(0, 10));
        date.setUTCDate(date.getUTCDate() + 1);
    }
    while (cells.length % 7) {
        cells.push(null);
    }
    return cells;
}

module.exports = { readClosedDays, toggleClosedDate, monthGrid };
