var thisStateSelector;
var tenantsPerPage = 12;
var currentPage = 1;

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
        var unitsHtml = '<option value="0">--Select Unit--</option>' + unitOptionsHtml
        $('.unit_id').html(unitsHtml);
    } else {
        $('.unit_id').html('<option value="0">--Select Unit--</option>');
    }
}

// Initial load
tenantSearch(0, 0);

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

    // Apply text search
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

    // Hide all, then show current page items
    $visible.addClass('d-none');
    var startIndex = (currentPage - 1) * tenantsPerPage;
    var endIndex = startIndex + tenantsPerPage;
    $visible.slice(startIndex, endIndex).removeClass('d-none');

    // Also ensure filter-hidden and search-hidden stay hidden
    $('.single-tenant.filter-hidden, .single-tenant.search-hidden').addClass('d-none');

    // No results message
    if (totalItems === 0 && ($('#tenantLiveSearch').val() || '').trim() !== '') {
        $('#tenantNoResults').removeClass('d-none');
    } else {
        $('#tenantNoResults').addClass('d-none');
    }

    // Build pagination
    renderPagination(totalPages, '#tenantPaginationList');
}

function renderPagination(totalPages, selector) {
    var $pagination = $(selector);
    $pagination.empty();

    if (totalPages <= 1) return;

    // Previous
    $pagination.append(
        '<li class="page-item ' + (currentPage === 1 ? 'disabled' : '') + '">' +
        '<a class="page-link" href="#" data-page="' + (currentPage - 1) + '">&laquo;</a></li>'
    );

    // Page numbers (show max 5 around current)
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

    // Next
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
