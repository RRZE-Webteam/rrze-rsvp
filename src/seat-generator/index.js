import "./style.scss";

/**
 * Show row settings only for multiple rows and starting numbers only for
 * numeric labels. Without JavaScript all controls remain usable.
 *
 * @param {HTMLFormElement} form Seat configuration form.
 * @returns {void}
 */
function setupNumbering(form) {
    const rows = form.elements.number_rows;
    if (!rows || rows.type !== "number") return;

    /**
     * Mirror the conditions used by the server when it builds seat names.
     *
     * @returns {void}
     */
    function update() {
        form.querySelectorAll("[data-axis]").forEach((axis) => {
            const select = axis.querySelector("select");
            const start = axis.querySelector("[data-start]");
            axis.hidden = axis.dataset.axis === "rows" && rows.valueAsNumber <= 1;
            select.disabled = axis.hidden;
            start.hidden = select.value !== "num";
            start.querySelector("input").disabled = axis.hidden || start.hidden;
        });
    }

    form.addEventListener("input", update);
    update();
}

const generator = document.getElementById("rsvp-create-seats");
if (generator) setupNumbering(generator);
