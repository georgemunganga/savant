var thisStateSelector;
var tenantsPerPage = 12;
var currentPage = 1;
var bulkPortalAccessSelection = {};

// Get unit
$(document).on('change', '.property_id', function () {
    thisStateSelector = $(this);
    var route = $('#getPropertyUnitsRoute').val();
    commonAjax('GET', route, getUnitsRes, getUnitsRes, { 'property_id': $(thisStateSelector).val() });
});

function getUnitsRes(response) {
    if (response.data) {
        var unitOptionsHtml = response.data.map(function (opt) {
            return '<option value="' + opt.id + '">' + opt.unit_name + '</option>';
        }).join('');
        var unitsHtml = '<option value="0">--Select Unit--</option>' + unitOptionsHtml;
        $('.unit_id').html(unitsHtml);
    } else {
        $('.unit_id').html('<option value="0">--Select Unit--</option>');
    }
}

function selectedTenantIds() {
    return Object.keys(bulkPortalAccessSelection);
}

function rememberSelectedTenant(tenantId, metadata) {
    if (!tenantId) {
        return;
    }

    bulkPortalAccessSelection[String(tenantId)] = metadata || {};
}

function forgetSelectedTenant(tenantId) {
    delete bulkPortalAccessSelection[String(tenantId)];
}

function clearSelectedTenants() {
    bulkPortalAccessSelection = {};
}

function getVisibleEligibleCardCheckboxes() {
    return $('.single-tenant').filter(function () {
        return !$(this).hasClass('d-none') && String($(this).data('access-eligible')) === '1';
    }).find('.bulk-portal-access-check:not(:disabled)');
}

function getVisibleEligibleTableCheckboxes() {
    if (!window.allTenantDataTable) {
        return $();
    }

    return $(window.allTenantDataTable.rows({ page: 'current' }).nodes())
        .find('.bulk-portal-access-check:not(:disabled)');
}

function syncBulkSelectionUI() {
    var count = selectedTenantIds().length;
    $('#bulkPortalAccessSummary').text(count + ' tenant' + (count === 1 ? '' : 's') + ' selected');
    $('#sendBulkPortalAccessButton, #clearBulkPortalAccessSelection').prop('disabled', count < 1);

    $('.bulk-portal-access-check').each(function () {
        var tenantId = String($(this).data('tenant-id'));
        $(this).prop('checked', !!bulkPortalAccessSelection[tenantId]);
    });

    var visibleCardCheckboxes = getVisibleEligibleCardCheckboxes();
    var visibleTableCheckboxes = getVisibleEligibleTableCheckboxes();
    var visibleEligible = visibleCardCheckboxes.length ? visibleCardCheckboxes : visibleTableCheckboxes;
    var checkedVisibleCount = visibleEligible.filter(':checked').length;
    var shouldCheckAll = visibleEligible.length > 0 && checkedVisibleCount === visibleEligible.length;

    $('#bulkPortalAccessSelectAll, #bulkPortalAccessTableSelectAll').prop('checked', shouldCheckAll);
}

function applyVisibleSelection(shouldSelect) {
    var visibleCheckboxes = getVisibleEligibleCardCheckboxes();
    if (!visibleCheckboxes.length) {
        visibleCheckboxes = getVisibleEligibleTableCheckboxes();
    }

    visibleCheckboxes.each(function () {
        var checkbox = $(this);
        var tenantId = checkbox.data('tenant-id');

        checkbox.prop('checked', shouldSelect);

        if (shouldSelect) {
            rememberSelectedTenant(tenantId, {
                tenant_name: checkbox.data('tenant-name'),
                email: checkbox.data('tenant-email')
            });
        } else {
            forgetSelectedTenant(tenantId);
        }
    });

    syncBulkSelectionUI();
}

function summariseBulkPortalAccessResponse(response) {
    if (!response || !response.data) {
        return response && response.message ? response.message : 'Portal access request completed.';
    }

    var data = response.data;
    var message = response.message || ('Portal access email sent to ' + (data.sent_count || 0) + ' tenant(s).');
    var resultMessages = (data.results || [])
        .filter(function (item) {
            return item.status !== 'sent' && item.reason;
        })
        .slice(0, 3)
        .map(function (item) {
            var label = item.tenant_name || item.email || ('Tenant #' + item.tenant_id);
            return label + ': ' + item.reason;
        });

    if (resultMessages.length) {
        message += ' ' + resultMessages.join(' ');
    }

    return message;
}

function sendBulkPortalAccess() {
    var tenantIds = selectedTenantIds();
    if (!tenantIds.length) {
        toastr.error('Select at least one tenant.');
        return;
    }

    Swal.fire({
        title: 'Send portal access?',
        text: 'Selected draft tenants will receive a secure set-password email for the tenant portal.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Send Access',
        cancelButtonText: $('#cancelButtonText').val() || 'Cancel'
    }).then(function (result) {
        if (!result.value) {
            return;
        }

        var button = $('#sendBulkPortalAccessButton');
        button.prop('disabled', true);

        $.ajax({
            type: 'POST',
            url: $('#bulkPortalAccessRoute').val(),
            dataType: 'json',
            headers: {
                'X-CSRF-TOKEN': $('#bulkPortalAccessCsrfToken').val()
            },
            data: {
                tenant_ids: tenantIds
            },
            success: function (response) {
                clearSelectedTenants();
                $('.bulk-portal-access-check, #bulkPortalAccessSelectAll, #bulkPortalAccessTableSelectAll').prop('checked', false);
                syncBulkSelectionUI();
                toastr.success(summariseBulkPortalAccessResponse(response));

                if (window.allTenantDataTable) {
                    window.allTenantDataTable.ajax.reload(null, false);
                }
            },
            error: function (response) {
                commonHandler(response);
                syncBulkSelectionUI();
            },
            complete: function () {
                syncBulkSelectionUI();
            }
        });
    });
}

// Initial load
tenantSearch(0, 0);
syncBulkSelectionUI();

function tenantSearch(property_id, unit_id) {
    $('.single-tenant').each(function () {
        var item = $(this);
        var propertyIds = (item.data('property-ids') || '').toString().split(',').filter(Boolean);
        var unitIds = (item.data('unit-ids') || '').toString().split(',').filter(Boolean);
        var propertyMatch = (property_id == 0) || propertyIds.indexOf(property_id.toString()) > -1;
        var unitMatch = (unit_id == 0) || unitIds.indexOf(unit_id.toString()) > -1;

        if (propertyMatch && unitMatch) {
            item.removeClass('filter-hidden');
        } else {
            item.addClass('filter-hidden').addClass('d-none');
        }
    });
    currentPage = 1;
    applyLiveSearch();
}

$(document).on('click', '#applySearch', function () {
    var property_id = $('.property_id').val();
    var unit_id = $('.unit_id').val();
    tenantSearch(property_id, unit_id);
});

// Live search
$(document).on('input', '#tenantLiveSearch', function () {
    currentPage = 1;
    applyLiveSearch();
});

function applyLiveSearch() {
    var searchTerm = ($('#tenantLiveSearch').val() || '').toLowerCase().trim();
    var $allTenants = $('.single-tenant').not('.filter-hidden');

    $allTenants.each(function () {
        var cardText = $(this).text().toLowerCase();
        if (searchTerm === '' || cardText.indexOf(searchTerm) > -1) {
            $(this).removeClass('search-hidden');
        } else {
            $(this).addClass('search-hidden');
        }
    });

    applyPagination();
}

function applyPagination() {
    var $visible = $('.single-tenant').not('.filter-hidden').not('.search-hidden');
    var totalItems = $visible.length;
    var totalPages = Math.ceil(totalItems / tenantsPerPage);

    if (currentPage > totalPages) currentPage = totalPages || 1;

    $visible.addClass('d-none');
    var startIndex = (currentPage - 1) * tenantsPerPage;
    var endIndex = startIndex + tenantsPerPage;
    $visible.slice(startIndex, endIndex).removeClass('d-none');

    $('.single-tenant.filter-hidden, .single-tenant.search-hidden').addClass('d-none');

    if (totalItems === 0 && ($('#tenantLiveSearch').val() || '').trim() !== '') {
        $('#tenantNoResults').removeClass('d-none');
    } else {
        $('#tenantNoResults').addClass('d-none');
    }

    renderPagination(totalPages, '#tenantPaginationList');
    syncBulkSelectionUI();
}

function renderPagination(totalPages, selector) {
    var $pagination = $(selector);
    $pagination.empty();

    if (totalPages <= 1) return;

    $pagination.append(
        '<li class="page-item ' + (currentPage === 1 ? 'disabled' : '') + '">' +
        '<a class="page-link" href="#" data-page="' + (currentPage - 1) + '">&laquo;</a></li>'
    );

    var startPage = Math.max(1, currentPage - 2);
    var endPage = Math.min(totalPages, currentPage + 2);

    if (startPage > 1) {
        $pagination.append('<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>');
        if (startPage > 2) $pagination.append('<li class="page-item disabled"><span class="page-link">...</span></li>');
    }

    for (var i = startPage; i <= endPage; i++) {
        $pagination.append(
            '<li class="page-item ' + (i === currentPage ? 'active' : '') + '">' +
            '<a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>'
        );
    }

    if (endPage < totalPages) {
        if (endPage < totalPages - 1) $pagination.append('<li class="page-item disabled"><span class="page-link">...</span></li>');
        $pagination.append('<li class="page-item"><a class="page-link" href="#" data-page="' + totalPages + '">' + totalPages + '</a></li>');
    }

    $pagination.append(
        '<li class="page-item ' + (currentPage === totalPages ? 'disabled' : '') + '">' +
        '<a class="page-link" href="#" data-page="' + (currentPage + 1) + '">&raquo;</a></li>'
    );
}

$(document).on('click', '#tenantPaginationList .page-link', function (e) {
    e.preventDefault();
    var page = parseInt($(this).data('page'));
    if (page && page !== currentPage) {
        currentPage = page;
        applyPagination();
        $('html, body').animate({ scrollTop: $('.properties-item-wrap').offset().top - 100 }, 200);
    }
});

$(document).on('change', '.bulk-portal-access-check', function () {
    var checkbox = $(this);
    var tenantId = checkbox.data('tenant-id');

    if (checkbox.is(':checked')) {
        rememberSelectedTenant(tenantId, {
            tenant_name: checkbox.data('tenant-name'),
            email: checkbox.data('tenant-email')
        });
    } else {
        forgetSelectedTenant(tenantId);
    }

    syncBulkSelectionUI();
});

$(document).on('change', '#bulkPortalAccessSelectAll, #bulkPortalAccessTableSelectAll', function () {
    applyVisibleSelection($(this).is(':checked'));
});

$(document).on('click', '#clearBulkPortalAccessSelection', function () {
    clearSelectedTenants();
    syncBulkSelectionUI();
});

$(document).on('click', '#sendBulkPortalAccessButton', function () {
    sendBulkPortalAccess();
});

$(document).on('tenant-datatable-draw', function () {
    syncBulkSelectionUI();
});
