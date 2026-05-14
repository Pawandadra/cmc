(function () {
    /**
     * Client-side sort for all data tables: click a header to sort; click again to reverse.
     * Skips .th-actions, .th-no-sort, headers that contain form controls, and tables with
     * data-sort-disable / .table-no-sort. Colspan "empty" rows stay at the bottom.
     */
    function cmcInitTableSorting() {
        document.querySelectorAll("table.table").forEach(function (table) {
            if (table.dataset.cmcSortInit === "1") {
                return;
            }
            if (table.hasAttribute("data-sort-disable") || table.classList.contains("table-no-sort")) {
                return;
            }
            var thead = table.tHead;
            var tbody = table.tBodies[0];
            if (!thead || !tbody || thead.rows.length < 1) {
                return;
            }
            var headerRow = thead.rows[0];
            var sortableHeaders = [];

            function cellText(td) {
                if (!td) {
                    return "";
                }
                return (td.innerText || "").replace(/\s+/g, " ").trim();
            }

            function isEmptySortVal(t) {
                return t === "" || t === "—" || t === "-";
            }

            function tryParseNumber(s) {
                if (isEmptySortVal(s)) {
                    return null;
                }
                var t = s.replace(/[₹\s\u00a0]/g, "").replace(/,/g, "");
                if (t === "" || t === "." || t === "-" || t === "-.") {
                    return null;
                }
                if (!/^-?\d+(\.\d+)?$/.test(t)) {
                    return null;
                }
                var n = parseFloat(t);
                return isNaN(n) ? null : n;
            }

            function tryParseDate(s) {
                if (isEmptySortVal(s)) {
                    return null;
                }
                var t = s.trim();
                if (!/^\d{4}-\d{2}-\d{2}/.test(t)) {
                    return null;
                }
                var iso = t.indexOf("T") < 0 && t.indexOf(" ") > 0 ? t.replace(" ", "T") : t;
                var ms = Date.parse(iso);
                return isNaN(ms) ? null : ms;
            }

            function compareValues(ta, tb) {
                if (isEmptySortVal(ta) && isEmptySortVal(tb)) {
                    return 0;
                }
                if (isEmptySortVal(ta)) {
                    return 1;
                }
                if (isEmptySortVal(tb)) {
                    return -1;
                }
                var na = tryParseNumber(ta);
                var nb = tryParseNumber(tb);
                if (na !== null && nb !== null) {
                    return na < nb ? -1 : na > nb ? 1 : 0;
                }
                var da = tryParseDate(ta);
                var db = tryParseDate(tb);
                if (da !== null && db !== null) {
                    return da < db ? -1 : da > db ? 1 : 0;
                }
                return String(ta).localeCompare(String(tb), undefined, { numeric: true, sensitivity: "base" });
            }

            function isPlaceholderRow(tr) {
                if (tr.cells.length !== 1) {
                    return false;
                }
                return (tr.cells[0].colSpan || 1) > 1;
            }

            function clearSortMarks() {
                sortableHeaders.forEach(function (h) {
                    h.classList.remove("th-sort-asc", "th-sort-desc");
                    h.setAttribute("aria-sort", "none");
                });
            }

            function sortByColumn(colIndex, th) {
                var dir = table.dataset.cmcSortCol === String(colIndex) && table.dataset.cmcSortDir === "asc" ? -1 : 1;
                if (table.dataset.cmcSortCol !== String(colIndex)) {
                    dir = 1;
                }
                table.dataset.cmcSortCol = String(colIndex);
                table.dataset.cmcSortDir = dir === 1 ? "asc" : "desc";

                clearSortMarks();
                th.classList.add(dir === 1 ? "th-sort-asc" : "th-sort-desc");
                th.setAttribute("aria-sort", dir === 1 ? "ascending" : "descending");

                var rows = Array.prototype.slice.call(tbody.rows);
                var placeholders = [];
                var dataRows = [];
                rows.forEach(function (tr) {
                    if (isPlaceholderRow(tr)) {
                        placeholders.push(tr);
                    } else {
                        dataRows.push(tr);
                    }
                });

                dataRows.forEach(function (tr, i) {
                    tr._cmcSortOrig = i;
                });

                dataRows.sort(function (a, b) {
                    var ac = a.cells[colIndex];
                    var bc = b.cells[colIndex];
                    var ta = cellText(ac);
                    var tb = cellText(bc);
                    var c = compareValues(ta, tb);
                    if (c !== 0) {
                        return dir * c;
                    }
                    return (a._cmcSortOrig || 0) - (b._cmcSortOrig || 0);
                });

                dataRows.forEach(function (tr) {
                    delete tr._cmcSortOrig;
                });

                dataRows.forEach(function (tr) {
                    tbody.appendChild(tr);
                });
                placeholders.forEach(function (tr) {
                    tbody.appendChild(tr);
                });
            }

            for (var i = 0; i < headerRow.cells.length; i++) {
                (function (colIndex, th) {
                    if (th.classList.contains("th-actions") || th.classList.contains("th-no-sort")) {
                        return;
                    }
                    if (th.querySelector("input,select,button,textarea")) {
                        return;
                    }
                    if (!th.textContent.replace(/\s+/g, " ").trim()) {
                        return;
                    }
                    th.classList.add("th-sortable");
                    th.setAttribute("tabindex", "0");
                    th.setAttribute("role", "columnheader");
                    th.setAttribute("aria-sort", "none");
                    sortableHeaders.push(th);

                    function activate(ev) {
                        if (ev.type === "keydown" && ev.key !== "Enter" && ev.key !== " ") {
                            return;
                        }
                        if (ev.type === "keydown") {
                            ev.preventDefault();
                        }
                        sortByColumn(colIndex, th);
                    }

                    th.addEventListener("click", function () {
                        sortByColumn(colIndex, th);
                    });
                    th.addEventListener("keydown", activate);
                })(i, headerRow.cells[i]);
            }

            if (sortableHeaders.length > 0) {
                table.dataset.cmcSortInit = "1";
            }
        });
    }

    cmcInitTableSorting();

    document.querySelectorAll("form[data-confirm]").forEach(function (form) {
        form.addEventListener("submit", function (e) {
            var msg = form.getAttribute("data-confirm") || "Continue?";
            if (!window.confirm(msg)) {
                e.preventDefault();
            }
        });
    });

    var createForm = document.getElementById("user-create-form");
    if (createForm) {
        var roleSelect = createForm.querySelector("#role-select");
        var orgBlock = createForm.querySelector("#org-dept-fields");
        if (roleSelect && orgBlock) {
            var orgSelect = createForm.querySelector("#org-select");
            var deptSelect = createForm.querySelector("#dept-select");

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
        }
    }
})();
