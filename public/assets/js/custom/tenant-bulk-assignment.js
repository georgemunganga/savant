(function () {
    "use strict";

    const data = window.bulkAssignmentData || {};
    const tenants = data.tenants || [];
    const properties = data.properties || [];
    const units = data.units || [];
    const tenantAssignments = data.tenantAssignments || [];

    const rowsWrap = document.getElementById("assignmentRows");
    const addButton = document.getElementById("addAssignmentRow");
    const clearButton = document.getElementById("clearAssignments");
    const saveButton = document.getElementById("saveAssignments");
    const summaryText = document.getElementById("assignmentSummary");
    const storeRoute = document.getElementById("bulkAssignmentStoreRoute");
    const csrfTokenInput = document.getElementById("bulkAssignmentCsrfToken");

    if (!rowsWrap || !addButton || !clearButton || !saveButton || !summaryText || !storeRoute || !csrfTokenInput) {
        return;
    }

    function tenantOptionsHtml() {
        return [
            '<option value="">--Select Tenant--</option>',
            ...tenants.map(function (tenant) {
                return `<option value="${tenant.id}">${tenant.name} (${tenant.email})</option>`;
            })
        ].join("");
    }

    function propertyOptionsHtml() {
        return [
            '<option value="">--Select Property--</option>',
            ...properties.map(function (property) {
                return `<option value="${property.id}">${property.name}</option>`;
            })
        ].join("");
    }

    function getTenantById(tenantId) {
        return tenants.find(function (tenant) {
            return String(tenant.id) === String(tenantId);
        });
    }

    function getUnitById(unitId) {
        return units.find(function (unit) {
            return String(unit.id) === String(unitId);
        });
    }

    function getTenantAssignedUnitIds(tenantId, propertyId) {
        return tenantAssignments
            .filter(function (item) {
                return String(item.tenant_id) === String(tenantId) && String(item.property_id) === String(propertyId);
            })
            .map(function (item) {
                return String(item.unit_id);
            });
    }

    function getFirstAssignmentPropertyId(tenantId) {
        const found = tenantAssignments.find(function (item) {
            return String(item.tenant_id) === String(tenantId);
        });
        return found ? String(found.property_id) : "";
    }

    function getRowUnitIds(row) {
        const json = row.dataset.selectedUnits || "[]";
        try {
            return JSON.parse(json);
        } catch (e) {
            return [];
        }
    }

    function setRowUnitIds(row, unitIds) {
        row.dataset.selectedUnits = JSON.stringify(Array.from(new Set(unitIds.map(String))));
    }

    function createRow() {
        const row = document.createElement("tr");
        row.className = "assignment-row";
        setRowUnitIds(row, []);
        row.innerHTML = `
            <td>
                <select class="form-select assignment-tenant">
                    ${tenantOptionsHtml()}
                </select>
            </td>
            <td>
                <select class="form-select assignment-property">
                    ${propertyOptionsHtml()}
                </select>
            </td>
            <td>
                <div class="d-flex gap-2 align-items-start flex-wrap">
                    <select class="form-select assignment-unit-dropdown"></select>
                    <button type="button" class="theme-btn w-auto border-0 add-unit-badge">Add</button>
                </div>
                <div class="assignment-unit-badges mt-2 d-flex flex-wrap gap-1"></div>
            </td>
            <td class="text-center">
                <button type="button" class="p-1 tbl-action-btn remove-assignment-row" title="Remove">
                    <span class="iconify" data-icon="ep:delete-filled"></span>
                </button>
            </td>
        `;

        rowsWrap.appendChild(row);
        bindRowEvents(row);
        renderUnitDropdown(row);
        renderUnitBadges(row);
        updateSummary();
    }

    function renderUnitDropdown(row) {
        const propertyId = row.querySelector(".assignment-property").value;
        const unitDropdown = row.querySelector(".assignment-unit-dropdown");
        const selectedUnitIds = new Set(getRowUnitIds(row));

        if (!propertyId) {
            unitDropdown.innerHTML = '<option value="">--Select Property First--</option>';
            return;
        }

        const options = ['<option value="">--Select Unit--</option>'];
        units.forEach(function (unit) {
            if (String(unit.property_id) !== String(propertyId)) {
                return;
            }
            if (selectedUnitIds.has(String(unit.id))) {
                return;
            }
            options.push(`<option value="${unit.id}">${unit.name}</option>`);
        });
        unitDropdown.innerHTML = options.join("");
    }

    function renderUnitBadges(row) {
        const badgesWrap = row.querySelector(".assignment-unit-badges");
        const unitIds = getRowUnitIds(row);

        if (unitIds.length < 1) {
            badgesWrap.innerHTML = "";
            return;
        }

        badgesWrap.innerHTML = unitIds.map(function (unitId) {
            const unit = getUnitById(unitId);
            const name = unit ? unit.name : `Unit ${unitId}`;
            return `<span class="badge bg-primary">
                ${name}
                <button type="button" class="btn-close btn-close-white ms-2 remove-unit-badge" data-unit-id="${unitId}" style="font-size:10px;"></button>
            </span>`;
        }).join("");
    }

    function addUnitToRow(row, unitId) {
        if (!unitId) return;
        const unitIds = getRowUnitIds(row);
        if (unitIds.includes(String(unitId))) {
            return;
        }
        unitIds.push(String(unitId));
        setRowUnitIds(row, unitIds);
        renderUnitDropdown(row);
        renderUnitBadges(row);
        updateSummary();
    }

    function removeUnitFromRow(row, unitId) {
        const unitIds = getRowUnitIds(row).filter(function (id) {
            return String(id) !== String(unitId);
        });
        setRowUnitIds(row, unitIds);
        renderUnitDropdown(row);
        renderUnitBadges(row);
        updateSummary();
    }

    function bindRowEvents(row) {
        const tenantSelect = row.querySelector(".assignment-tenant");
        const propertySelect = row.querySelector(".assignment-property");
        const unitDropdown = row.querySelector(".assignment-unit-dropdown");
        const addUnitButton = row.querySelector(".add-unit-badge");
        const removeRowButton = row.querySelector(".remove-assignment-row");

        tenantSelect.addEventListener("change", function () {
            const tenant = getTenantById(tenantSelect.value);
            if (tenant && !propertySelect.value) {
                const assignedPropertyId = getFirstAssignmentPropertyId(tenant.id);
                propertySelect.value = assignedPropertyId || tenant.property_id || "";
            }
            const prefilledUnitIds = tenant && propertySelect.value
                ? getTenantAssignedUnitIds(tenant.id, propertySelect.value)
                : [];
            setRowUnitIds(row, prefilledUnitIds);
            renderUnitDropdown(row);
            renderUnitBadges(row);
            updateSummary();
        });

        propertySelect.addEventListener("change", function () {
            const tenant = getTenantById(tenantSelect.value);
            const prefilledUnitIds = tenant && propertySelect.value
                ? getTenantAssignedUnitIds(tenant.id, propertySelect.value)
                : [];
            setRowUnitIds(row, prefilledUnitIds);
            renderUnitDropdown(row);
            renderUnitBadges(row);
            updateSummary();
        });

        addUnitButton.addEventListener("click", function () {
            addUnitToRow(row, unitDropdown.value);
        });

        row.addEventListener("click", function (e) {
            const btn = e.target.closest(".remove-unit-badge");
            if (!btn) return;
            removeUnitFromRow(row, btn.dataset.unitId);
        });

        removeRowButton.addEventListener("click", function () {
            row.remove();
            updateSummary();
        });
    }

    function validRowsCount() {
        let count = 0;
        rowsWrap.querySelectorAll(".assignment-row").forEach(function (row) {
            const tenantId = row.querySelector(".assignment-tenant").value;
            const propertyId = row.querySelector(".assignment-property").value;
            const unitIds = getRowUnitIds(row);
            if (tenantId && propertyId && unitIds.length > 0) {
                count += 1;
            }
        });
        return count;
    }

    function collectAssignments() {
        const assignments = [];
        let hasInvalidRow = false;
        const pairMap = {};

        rowsWrap.querySelectorAll(".assignment-row").forEach(function (row) {
            const tenantId = row.querySelector(".assignment-tenant").value;
            const propertyId = row.querySelector(".assignment-property").value;
            const unitIds = getRowUnitIds(row);

            if (!tenantId && !propertyId && unitIds.length === 0) {
                return;
            }

            if (!tenantId || !propertyId || unitIds.length === 0) {
                hasInvalidRow = true;
                return;
            }

            unitIds.forEach(function (unitId) {
                const key = `${tenantId}-${unitId}`;
                if (pairMap[key]) {
                    hasInvalidRow = true;
                    return;
                }
                pairMap[key] = true;
                assignments.push({
                    tenant_id: tenantId,
                    property_id: propertyId,
                    unit_id: unitId
                });
            });
        });

        if (hasInvalidRow) {
            return { error: "Please complete rows and avoid duplicate tenant-unit pairs." };
        }
        if (assignments.length < 1) {
            return { error: "Please complete at least one assignment row." };
        }
        return { assignments: assignments };
    }

    function submitAssignments() {
        const collected = collectAssignments();
        if (collected.error) {
            toastr.error(collected.error);
            return;
        }

        const formData = new FormData();
        formData.append("_token", csrfTokenInput.value);
        collected.assignments.forEach(function (item, index) {
            formData.append(`assignments[${index}][tenant_id]`, item.tenant_id);
            formData.append(`assignments[${index}][property_id]`, item.property_id);
            formData.append(`assignments[${index}][unit_id]`, item.unit_id);
        });

        saveButton.disabled = true;
        commonAjax("POST", storeRoute.value, submitAssignmentsRes, submitAssignmentsRes, formData);
    }

    function submitAssignmentsRes(response) {
        saveButton.disabled = false;

        if (response && response.status === true) {
            toastr.success(response.message || "Assignments updated successfully.");
            setTimeout(function () {
                window.location.reload();
            }, 300);
            return;
        }

        if (typeof commonHandler === "function") {
            commonHandler(response);
            return;
        }
        toastr.error("Something went wrong.");
    }

    function updateSummary() {
        const totalRows = rowsWrap.querySelectorAll(".assignment-row").length;
        const validRows = validRowsCount();
        if (totalRows === 0) {
            summaryText.textContent = "No assignment row added yet.";
            return;
        }
        summaryText.textContent = `${validRows} of ${totalRows} row(s) are ready. Added units appear as badges.`;
    }

    addButton.addEventListener("click", function () {
        createRow();
    });

    clearButton.addEventListener("click", function () {
        rowsWrap.innerHTML = "";
        updateSummary();
    });

    saveButton.addEventListener("click", function () {
        submitAssignments();
    });

    createRow();
})();
