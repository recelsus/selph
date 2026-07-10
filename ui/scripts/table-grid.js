(function () {
    function restoreCell(input) {
        input.value = input.dataset.original || "";
        input.classList.remove("saving");
        input.classList.add("invalid");
        setTimeout(function () {
            input.classList.remove("invalid");
        }, 900);
    }

    function saveCell(input) {
        if (input.disabled || input.value === (input.dataset.original || "")) {
            return;
        }

        var type = input.dataset.type || "text";
        if (type === "number" && input.value !== "" && Number.isNaN(Number(input.value))) {
            restoreCell(input);
            return;
        }

        var body = new URLSearchParams();
        var isNew = !input.dataset.rowid;
        body.set("action", isNew ? "insert_row" : "update_cell");
        body.set("table", input.dataset.table);
        body.set("rowid", input.dataset.rowid || "");
        body.set("column", input.dataset.column);
        body.set("value", input.value);

        input.classList.add("saving");
        fetch(window.location.pathname, {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded",
                "Accept": "application/json"
            },
            body: body.toString()
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error("save failed");
                }

                return response.json();
            })
            .then(function (payload) {
                var data = payload && payload.data ? payload.data : {};
                if (isNew && data.rowid) {
                    var row = input.closest("tr");
                    if (row) {
                        row.querySelectorAll("[data-cell-input]").forEach(function (cell) {
                            cell.dataset.rowid = String(data.rowid);
                        });
                        row.classList.remove("new-row");
                        appendNewRow(row);
                    }
                }

                var value = Object.prototype.hasOwnProperty.call(data, "value") ? data.value : input.value;
                input.dataset.original = value === null ? "" : String(value);
                input.value = input.dataset.original;
                input.classList.remove("saving");
                input.classList.add("saved");
                setTimeout(function () {
                    input.classList.remove("saved");
                }, 700);
            })
            .catch(function () {
                restoreCell(input);
            });
    }

    function bindCell(input) {
        input.addEventListener("keydown", function (event) {
            if (event.key === "Enter") {
                event.preventDefault();
                saveCell(input);
                input.blur();
            } else if (event.key === "Escape") {
                event.preventDefault();
                restoreCell(input);
                input.blur();
            }
        });

        if (input.tagName === "SELECT") {
            input.addEventListener("change", function () {
                saveCell(input);
            });
        }
    }

    function appendNewRow(sourceRow) {
        var tbody = sourceRow.closest("tbody");
        if (!tbody || tbody.querySelector("tr.new-row")) {
            return;
        }

        var clone = sourceRow.cloneNode(true);
        clone.classList.add("new-row");
        clone.querySelectorAll("[data-cell-input]").forEach(function (cell) {
            delete cell.dataset.rowid;
            cell.dataset.original = "";
            cell.value = "";
            cell.classList.remove("saving", "saved", "invalid");
            bindCell(cell);
        });
        tbody.appendChild(clone);
    }

    function bindCells() {
        document.querySelectorAll("[data-cell-input]").forEach(bindCell);
    }

    document.addEventListener("DOMContentLoaded", bindCells);
})();
