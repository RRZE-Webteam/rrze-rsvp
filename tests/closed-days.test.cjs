const test = require('node:test');
const assert = require('node:assert/strict');
const { readClosedDays, toggleClosedDate, monthGrid } = require('../src/admin/closed-days-model');

/** @returns {void} Match the server's exact line format without implying invalid days are closed. */
test('closed days distinguish real ISO dates from unrecognized legacy lines', function parseLegacyText() {
    assert.deepEqual(readClosedDays('2026-11-03\r\n2026-11-01\n2026-11-03\n\n2026-02-29\n 2026-11-05\n03.11.2026'), {
        dates: ['2026-11-01', '2026-11-03'],
        invalidLines: ['2026-02-29', ' 2026-11-05', '03.11.2026'],
    });
    assert.deepEqual(readClosedDays(''), { dates: [], invalidLines: [] });
});

/** @returns {void} Keep unrelated text intact and remove duplicates together when reopening a day. */
test('calendar edits preserve unrelated text and the existing newline convention', function preserveText() {
    const legacy = '2026-11-03\r\nlegacy entry\r\n2026-11-03\r\n2026-12-24';
    assert.equal(toggleClosedDate(legacy, '2026-11-03'), 'legacy entry\r\n2026-12-24');
    assert.equal(toggleClosedDate(legacy, '2026-12-25'), legacy + '\r\n2026-12-25');
    assert.equal(toggleClosedDate('2026-11-03\n', '2026-11-05'), '2026-11-03\n2026-11-05');
    assert.equal(toggleClosedDate('2026-11-03', '2026-11-03'), '');
    assert.equal(toggleClosedDate('', '2028-02-29'), '2028-02-29');
    assert.equal(toggleClosedDate(legacy, '2026-02-29'), legacy);
    assert.equal(toggleClosedDate(legacy, '03.11.2026'), legacy);
});

/** @returns {void} Respect the configured week start and account for leap years. */
test('calendar grids cover complete weeks with the configured first weekday', function calendarLayout() {
    const monday = monthGrid('2026-11-03', 1);
    assert.equal(monday.length, 42);
    assert.deepEqual(monday.slice(0, 7), [null, null, null, null, null, null, '2026-11-01']);
    assert.equal(monday[35], '2026-11-30');
    const sunday = monthGrid('2026-11-01', 0);
    assert.equal(sunday[0], '2026-11-01');
    assert.equal(sunday.length, 35);
    assert.equal(monthGrid('2028-02-01', 1).filter(Boolean).length, 29);
    assert.equal(monthGrid('2026-03-01', 1).filter(Boolean).length, 31);
    assert.deepEqual(monthGrid('invalid', 1), []);
});
