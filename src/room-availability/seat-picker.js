/**
 * Create a plain element without interpreting labels or seat names as HTML.
 *
 * @param {string} tag HTML tag name.
 * @param {string} className Component class.
 * @param {string} text Optional visible text.
 * @returns {HTMLElement} Detached element.
 */
function element(tag, className, text = '') {
    const node = document.createElement(tag);
    node.className = className;
    node.textContent = text;
    return node;
}

/**
 * Replace one large disclosure with a modal using the original server-rendered links.
 *
 * @param {HTMLElement} root Room availability component.
 * @param {HTMLDetailsElement} picker No-JavaScript seat disclosure.
 * @param {Object<string,string>} labels Translated interface labels.
 * @param {number} index Unique dialog number on this page.
 * @returns {void}
 */
function enhancePicker(root, picker, labels, index) {
    const list = picker.querySelector('.rsvp-room-seat-links');
    const summary = picker.querySelector('summary');
    if (!list || !summary) return;
    const id = `rsvp-seat-dialog-${index}`;
    const trigger = element('button', 'rsvp-room-seat-trigger', summary.textContent);
    trigger.type = 'button';
    trigger.setAttribute('aria-haspopup', 'dialog');
    trigger.setAttribute('aria-controls', id);
    const dialog = element('dialog', 'rsvp-room-seat-dialog');
    dialog.id = id;
    dialog.setAttribute('aria-labelledby', `${id}-title`);
    dialog.setAttribute('aria-describedby', `${id}-context`);
    const header = element('div', 'rsvp-room-dialog-header');
    const title = element('h2', 'rsvp-room-dialog-title', summary.textContent);
    title.id = `${id}-title`;
    const close = element('button', 'rsvp-room-dialog-close', '×');
    close.type = 'button';
    close.setAttribute('aria-label', labels.close);
    header.append(title, close);
    const context = element('p', 'rsvp-room-dialog-context', picker.dataset.context);
    context.id = `${id}-context`;
    const searchLabel = element('label', 'rsvp-room-seat-search-label', labels.search);
    searchLabel.htmlFor = `${id}-search`;
    const search = element('input', 'rsvp-room-seat-search');
    search.type = 'search';
    search.id = searchLabel.htmlFor;
    search.autocomplete = 'off';
    const status = element('p', 'rsvp-room-seat-results');
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    const empty = element('p', 'rsvp-room-seat-empty', labels.empty);
    empty.hidden = true;
    const seats = Array.from(list.children);
    dialog.append(header, context, searchLabel, search, status, list, empty);
    root.append(dialog);
    picker.replaceWith(trigger);

    /** @returns {void} Filter only this date/time's seats while preserving their original URLs. */
    function filterSeats() {
        const query = search.value.trim().toLocaleLowerCase();
        let visible = 0;
        for (const seat of seats) {
            const matches = (seat.dataset.seatName || seat.textContent).toLocaleLowerCase().includes(query);
            seat.hidden = !matches;
            if (matches) visible++;
        }
        status.textContent = labels.results.replace('%1$s', visible.toLocaleString()).replace('%2$s', seats.length.toLocaleString());
        empty.hidden = visible !== 0;
    }

    trigger.addEventListener('click',
        /** @returns {void} Start a fresh selection and focus the searchable list's input. */
        function openPicker() {
            search.value = '';
            filterSeats();
            dialog.showModal();
            search.focus();
        }
    );
    search.addEventListener('input', filterSeats);
    close.addEventListener('click',
        /** @returns {void} Close this picker without choosing a seat. */
        function closePicker() { dialog.close(); }
    );
    dialog.addEventListener('keydown',
        /** @param {KeyboardEvent} event Key pressed in the dialog. @returns {void} Close even when a search input would consume Escape to clear itself. */
        function closeOnEscape(event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                dialog.close();
            }
        }
    );
    dialog.addEventListener('close',
        /** @returns {void} Restore keyboard position after closing by button or Escape. */
        function restoreFocus() { trigger.focus(); }
    );
}

/** @returns {void} Enhance only supported browsers; otherwise leave the native disclosures intact. */
function initializeSeatPickers() {
    if (typeof HTMLDialogElement === 'undefined' || typeof HTMLDialogElement.prototype.showModal !== 'function') return;
    let index = 0;
    for (const root of document.querySelectorAll('.rrze-rsvp-room-availability[data-seat-picker]')) {
        let labels;
        try { labels = JSON.parse(root.dataset.seatPicker); } catch { continue; }
        for (const picker of root.querySelectorAll('.rsvp-room-seat-picker')) {
            enhancePicker(root, picker, labels, ++index);
        }
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeSeatPickers);
} else {
    initializeSeatPickers();
}
