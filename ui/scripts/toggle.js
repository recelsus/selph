(function () {
    function bindToggles() {
        document.querySelectorAll("[data-toggle-target]").forEach(function (button) {
            button.addEventListener("click", function () {
                var target = document.getElementById(button.getAttribute("data-toggle-target"));
                if (!target) {
                    return;
                }

                target.classList.toggle("collapsed");
                var input = target.querySelector("input[type=text]");
                if (input && !target.classList.contains("collapsed")) {
                    input.focus();
                }
            });
        });
    }

    document.addEventListener("DOMContentLoaded", bindToggles);
})();
