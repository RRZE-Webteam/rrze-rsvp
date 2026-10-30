/**
 * Keep the submitted date independent of the month currently shown in the grid.
 * Rename enhanced radios so the form submits exactly one authoritative date.
 *
 * @param {HTMLElement} host Calendar fieldset, retained across month requests.
 * @returns {{input: HTMLInputElement, select: Function, sync: Function}} Persistent date and grid updates.
 */
function calendarSelection(host) {
    const initial = host.querySelector('input[name="rsvp_date"]:checked');
    const input = host.ownerDocument.createElement('input');
    input.type = 'hidden';
    input.name = 'rsvp_date';
    input.value = initial?.value || '';
    input.dataset.label = initial?.dataset.label || '';
    host.append(input);

    /** @returns {void} Restore the selected marker after replacing the month markup. */
    function sync() {
        host.querySelectorAll('input[type="radio"]').forEach(
            /** @param {HTMLInputElement} radio Visible calendar day. @returns {void} */
            function syncDay(radio) {
                radio.name = 'rsvp_calendar_date';
                // The required date may belong to another month; guardSubmission
                // validates the persistent value rather than this visible group.
                radio.required = false;
                radio.checked = radio.value === input.value;
            }
        );
    }

    /** @param {HTMLInputElement} radio Newly selected day. @returns {void} */
    function select(radio) {
        input.value = radio.value;
        input.dataset.label = radio.dataset.label;
    }

    sync();
    return { input, select, sync };
}

module.exports = { calendarSelection };
