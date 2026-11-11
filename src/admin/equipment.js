/**
 * Move the optional WordPress controls into a native disclosure on the equipment
 * add form. Keep the original inputs, labels and values for validation and AJAX.
 *
 * @returns {void}
 */
function groupEquipmentOptions() {
    const options = document.querySelector('#addtag .rsvp-equipment-options');
    if (!options) return;

    const fields = options.parentElement.querySelectorAll(
        ':scope > .term-parent-wrap, :scope > .term-slug-wrap, :scope > .term-description-wrap'
    );
    if (!fields.length) return;

    options.append(...fields);
    options.hidden = false;
}

document.addEventListener('DOMContentLoaded', groupEquipmentOptions);
