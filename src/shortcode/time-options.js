/**
 * Keep a time tile fully visible inside a long list without moving the page.
 * Native radio focus targets the hidden input, so its label needs extra room.
 *
 * @param {HTMLInputElement|null} input Selected or keyboard-focused time control.
 * @returns {void}
 */
function revealTimeChoice(input) {
    const viewport = input?.closest('.rsvp-time-options-scroll');
    const choice = input?.closest('.rsvp-picker-choice');
    if (!viewport || !choice) return;
    const bounds = choice.getBoundingClientRect();
    const visible = viewport.getBoundingClientRect();
    const padding = 4;
    if (bounds.top < visible.top + padding) {
        viewport.scrollTop -= visible.top + padding - bounds.top;
    } else if (bounds.bottom > visible.bottom - padding) {
        viewport.scrollTop += bounds.bottom - visible.bottom + padding;
    }
}

module.exports = { revealTimeChoice };
