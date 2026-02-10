var propertyPerPage = 12;
var propertyCurrentPage = 1;

// Initial show all
$(function () {
    propertyApplySearch();
});

// Live search
$(document).on('input', '#propertyLiveSearch', function () {
    propertyCurrentPage = 1;
    propertyApplySearch();
});

function propertyApplySearch() {
    var searchTerm = ($('#propertyLiveSearch').val() || '').toLowerCase().trim();
    var $allProperties = $('.single-property');

    // Apply text search
    $allProperties.each(function () {
        var cardText = $(this).text().toLowerCase();
        if (searchTerm === '' || cardText.indexOf(searchTerm) > -1) {
            $(this).removeClass('search-hidden');
        } else {
            $(this).addClass('search-hidden');
        }
    });

    propertyApplyPagination();
}

function propertyApplyPagination() {
    var $visible = $('.single-property').not('.search-hidden');
    var totalItems = $visible.length;
    var totalPages = Math.ceil(totalItems / propertyPerPage);

    if (propertyCurrentPage > totalPages) propertyCurrentPage = totalPages || 1;

    // Hide all visible, then show current page
    $visible.addClass('d-none');
    var startIndex = (propertyCurrentPage - 1) * propertyPerPage;
    var endIndex = startIndex + propertyPerPage;
    $visible.slice(startIndex, endIndex).removeClass('d-none');

    // Ensure search-hidden stay hidden
    $('.single-property.search-hidden').addClass('d-none');

    // No results message
    if (totalItems === 0 && ($('#propertyLiveSearch').val() || '').trim() !== '') {
        $('#propertyNoResults').removeClass('d-none');
    } else {
        $('#propertyNoResults').addClass('d-none');
    }

    // Build pagination
    propertyRenderPagination(totalPages);
}

function propertyRenderPagination(totalPages) {
    var $pagination = $('#propertyPaginationList');
    $pagination.empty();

    if (totalPages <= 1) return;

    // Previous
    $pagination.append(
        '<li class="page-item ' + (propertyCurrentPage === 1 ? 'disabled' : '') + '">' +
        '<a class="page-link" href="#" data-page="' + (propertyCurrentPage - 1) + '">&laquo;</a></li>'
    );

    var startPage = Math.max(1, propertyCurrentPage - 2);
    var endPage = Math.min(totalPages, propertyCurrentPage + 2);

    if (startPage > 1) {
        $pagination.append('<li class="page-item"><a class="page-link" href="#" data-page="1">1</a></li>');
        if (startPage > 2) $pagination.append('<li class="page-item disabled"><span class="page-link">...</span></li>');
    }

    for (var i = startPage; i <= endPage; i++) {
        $pagination.append(
            '<li class="page-item ' + (i === propertyCurrentPage ? 'active' : '') + '">' +
            '<a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>'
        );
    }

    if (endPage < totalPages) {
        if (endPage < totalPages - 1) $pagination.append('<li class="page-item disabled"><span class="page-link">...</span></li>');
        $pagination.append('<li class="page-item"><a class="page-link" href="#" data-page="' + totalPages + '">' + totalPages + '</a></li>');
    }

    // Next
    $pagination.append(
        '<li class="page-item ' + (propertyCurrentPage === totalPages ? 'disabled' : '') + '">' +
        '<a class="page-link" href="#" data-page="' + (propertyCurrentPage + 1) + '">&raquo;</a></li>'
    );
}

$(document).on('click', '#propertyPaginationList .page-link', function (e) {
    e.preventDefault();
    var page = parseInt($(this).data('page'));
    if (page && page !== propertyCurrentPage) {
        propertyCurrentPage = page;
        propertyApplyPagination();
        $('html, body').animate({ scrollTop: $('.properties-item-wrap').offset().top - 100 }, 200);
    }
});
