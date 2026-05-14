(function () {
    document.querySelectorAll("form[data-confirm]").forEach(function (form) {
        form.addEventListener("submit", function (e) {
            var msg = form.getAttribute("data-confirm") || "Continue?";
            if (!window.confirm(msg)) {
                e.preventDefault();
            }
        });
    });

    var roleSelect = document.getElementById("role-select");
    var orgBlock = document.getElementById("org-dept-fields");
    if (!roleSelect || !orgBlock) return;

    var orgSelect = document.getElementById("org-select");
    var deptSelect = document.getElementById("dept-select");

    function setOrgDeptRequired(required) {
        if (orgSelect) orgSelect.required = required;
        if (deptSelect) deptSelect.required = required;
    }

    function filterDepartments() {
        if (!orgSelect || !deptSelect) return;
        var orgId = orgSelect.value;
        var opts = deptSelect.querySelectorAll("option[data-org]");
        var firstVisible = null;
        opts.forEach(function (opt) {
            var match = !orgId || opt.getAttribute("data-org") === orgId;
            opt.hidden = !match;
            opt.disabled = !match;
            if (match && !firstVisible) firstVisible = opt;
        });
        deptSelect.value = "";
        if (firstVisible && orgId) {
            deptSelect.value = firstVisible.value;
        }
    }

    function applyRole() {
        var role = roleSelect.value;
        if (role === "sde") {
            orgBlock.style.display = "none";
            setOrgDeptRequired(false);
            if (orgSelect) orgSelect.value = "";
            if (deptSelect) deptSelect.value = "";
        } else {
            orgBlock.style.display = "";
            setOrgDeptRequired(true);
            filterDepartments();
        }
    }

    roleSelect.addEventListener("change", applyRole);
    if (orgSelect) orgSelect.addEventListener("change", filterDepartments);
    applyRole();
})();
