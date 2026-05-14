(function () {
    var root = document.getElementById("fulfillment-lines-root");
    if (!root) return;

    var dataEl = document.getElementById("fulfillment-lines-data");
    if (!dataEl || !dataEl.textContent) return;

    var data;
    try {
        data = JSON.parse(dataEl.textContent);
    } catch (e) {
        return;
    }

    var inventory = data.materials || data.inventory || [];
    var initialLines = data.lines || [];
    var container = document.getElementById("line-rows-container");
    var template = document.getElementById("line-row-template");
    var addBtn = document.getElementById("add-fulfillment-line");
    if (!container || !template || !template.content || !addBtn) return;

    function bindRemove(row) {
        var btn = row.querySelector(".remove-fulfillment-line");
        if (btn) {
            btn.addEventListener("click", function () {
                row.remove();
                if (!container.querySelector(".fulfillment-line-row")) {
                    addRow(null, "");
                }
            });
        }
    }

    function addRow(itemId, qty) {
        var frag = template.content.cloneNode(true);
        var row = frag.querySelector(".fulfillment-line-row");
        if (!row) return;
        var sel = row.querySelector('select[name="line_item[]"]');
        var qtyInput = row.querySelector('input[name="line_qty[]"]');
        if (sel) {
            sel.value = itemId ? String(itemId) : "";
        }
        if (qtyInput) {
            qtyInput.value = qty !== undefined && qty !== null && qty !== "" ? String(qty) : "";
        }
        container.appendChild(frag);
        bindRemove(row);
    }

    if (inventory.length === 0) {
        root.setAttribute("data-no-inventory", "1");
        return;
    }

    if (initialLines.length) {
        initialLines.forEach(function (ln) {
            addRow(ln.inventory_item_id, ln.quantity);
        });
    } else {
        addRow(null, "");
    }

    addBtn.addEventListener("click", function () {
        addRow(null, "");
    });
})();
