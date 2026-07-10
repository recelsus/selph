(function () {
    function addColumn(target) {
        var row = document.createElement("div");
        row.className = "schema-row";
        row.innerHTML = '<input type="text" name="column_name[]" placeholder="column name" required><select name="column_type[]"><option value="text">Text</option><option value="number">Number</option><option value="bool">Bool</option></select><button type="button" class="danger" data-remove-column>Remove</button>';
        target.appendChild(row);

        var input = row.querySelector("input");
        if (input) {
            input.focus();
        }
    }

    function bindColumnButtons() {
        document.querySelectorAll("[data-add-column]").forEach(function (button) {
            button.addEventListener("click", function () {
                var target = document.getElementById(button.getAttribute("data-add-column"));
                if (target) {
                    addColumn(target);
                }
            });
        });

        document.addEventListener("click", function (event) {
            if (!event.target || !event.target.matches("[data-remove-column]")) {
                return;
            }

            var row = event.target.closest(".schema-row");
            if (row) {
                row.remove();
            }
        });
    }

    document.addEventListener("DOMContentLoaded", bindColumnButtons);
})();
