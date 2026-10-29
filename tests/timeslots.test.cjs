const test = require('node:test');
const assert = require('node:assert/strict');
const { toInputDate, singleDateFields, isSingleDate, validateTimeslot } = require('../src/admin/timeslot-model');

/** @returns {void} Verify date-to-weekday conversion across DST and browser timezones. */
test('single dates retain their day and ISO weekday across timezones', function calendarDates() {
    const previousTimezone = process.env.TZ;
    try {
        for (const timezone of ['Europe/Berlin', 'America/Los_Angeles', 'Pacific/Auckland']) {
            process.env.TZ = timezone;
            assert.deepEqual(singleDateFields('2026-03-29'), {
                from: '29.03.2026', to: '29.03.2026', weekdays: ['7'],
            });
            assert.deepEqual(singleDateFields('2026-10-25'), {
                from: '25.10.2026', to: '25.10.2026', weekdays: ['7'],
            });
            assert.equal(toInputDate('3.11.2026'), '2026-11-03');
            assert.deepEqual(singleDateFields('2028-02-29').weekdays, ['2']);
        }
    } finally {
        if (previousTimezone === undefined) {
            delete process.env.TZ;
        } else {
            process.env.TZ = previousTimezone;
        }
    }
});

/** @returns {void} Reject rollover dates instead of silently booking another day. */
test('invalid dates cannot become a valid date by JavaScript rollover', function invalidDates() {
    for (const value of ['', '2026-02-29', '31.04.2026', '2026-13-01', 'garbage']) {
        assert.equal(toInputDate(value), '');
        assert.deepEqual(singleDateFields(value), { from: '', to: '', weekdays: [] });
    }
});

/** @returns {void} Only infer single mode when a legacy rule really offers that day. */
test('legacy rules retain their meaning when selecting an editor mode', function legacyRules() {
    assert.equal(isSingleDate({ from: '', to: '', weekdays: ['1', '3'] }), false);
    assert.equal(isSingleDate({ from: '01.11.2026', to: '30.11.2026', weekdays: ['1'] }), false);
    assert.equal(isSingleDate({ from: '03.11.2026', to: '03.11.2026', weekdays: ['1'] }), false);
    assert.equal(isSingleDate({ from: '03.11.2026', to: '03.11.2026', weekdays: ['1', '2'] }), true);
});

/** @returns {void} Check the incomplete and reversed input paths before form submission. */
test('edited schedules require matching dates and positive same-day durations', function validation() {
    const valid = { ...singleDateFields('2026-11-03'), start: '09:00', end: '11:00' };
    assert.equal(validateTimeslot(valid, 'single'), '');
    assert.equal(validateTimeslot({ ...valid, from: '', to: '', weekdays: [] }, 'single'), 'dateRequired');
    assert.equal(validateTimeslot({ ...valid, weekdays: [] }, 'weekly'), 'weekdayRequired');
    assert.equal(validateTimeslot({ ...valid, end: '09:00' }, 'single'), 'timeOrder');
    assert.equal(validateTimeslot({ ...valid, start: '23:00', end: '01:00' }, 'single'), 'timeOrder');
    assert.equal(validateTimeslot({ ...valid, end: '24:00' }, 'single'), 'timeRequired');
    assert.equal(validateTimeslot({ ...valid, to: '02.11.2026' }, 'weekly'), 'dateOrder');
    assert.equal(validateTimeslot({ ...valid, to: '31.11.2026' }, 'weekly'), 'invalidDate');
    assert.equal(validateTimeslot({ from: '', to: '', weekdays: [], start: '', end: '' }, 'single'), '');
});
