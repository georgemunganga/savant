@extends('owner.layouts.app')

@section('content')
    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">
                <div class="page-content-wrapper bg-white p-30 radius-20">
                    <div class="row">
                        <div class="col-12">
                            <div class="page-title-box d-sm-flex align-items-center justify-content-between border-bottom mb-20">
                                <div class="page-title-left">
                                    <h3 class="mb-sm-0">{{ $pageTitle }}</h3>
                                </div>
                                <div class="page-title-right">
                                    <ol class="breadcrumb mb-0">
                                        <li class="breadcrumb-item"><a href="{{ route('owner.dashboard') }}">{{ __('Dashboard') }}</a></li>
                                        <li class="breadcrumb-item"><a href="{{ route('owner.invoice.index') }}">{{ __('Billing Center') }}</a></li>
                                        <li class="breadcrumb-item active">{{ $pageTitle }}</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="property-search-inner-bg bg-off-white theme-border radius-4 p-25 pb-0 mb-25">
                            <form method="get" action="{{ route('owner.invoice.rent-arrears') }}">
                                <div class="row">
                                    <div class="col-md-3 mb-25">
                                        <select class="form-select flex-shrink-0" name="property_id" id="property_id">
                                            <option value="">{{ __('All Properties') }}</option>
                                            @foreach ($properties as $property)
                                                <option value="{{ $property->id }}" {{ (string) $filters['property_id'] === (string) $property->id ? 'selected' : '' }}>
                                                    {{ $property->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-3 mb-25">
                                        <select class="form-select flex-shrink-0" name="unit_id" id="unit_id" data-selected="{{ $filters['unit_id'] }}">
                                            <option value="">{{ __('All Units') }}</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2 mb-25">
                                        <select class="form-select flex-shrink-0" name="month">
                                            <option value="">{{ __('All Months') }}</option>
                                            @for ($m = 1; $m <= 12; $m++)
                                                <option value="{{ $m }}" {{ (string) $filters['month'] === (string) $m ? 'selected' : '' }}>
                                                    {{ month($m) }}
                                                </option>
                                            @endfor
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-25">
                                        <div class="input-group">
                                            <span class="input-group-text">{{ __('From') }}</span>
                                            <input type="date" class="form-control" name="start_date" value="{{ $filters['start_date'] }}">
                                            <span class="input-group-text">{{ __('to') }}</span>
                                            <input type="date" class="form-control" name="end_date" value="{{ $filters['end_date'] }}">
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-auto mb-25">
                                        <button type="submit" class="default-btn theme-btn-purple w-auto">{{ __('Search Arrears') }}</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="row mb-20">
                        <div class="col-md-3 mb-15">
                            <div class="bg-off-white theme-border radius-4 p-15">
                                <h6 class="mb-1">{{ __('Missing Invoices') }}</h6>
                                <h4 class="mb-0">{{ $summary['missing_count'] }}</h4>
                            </div>
                        </div>
                        <div class="col-md-3 mb-15">
                            <div class="bg-off-white theme-border radius-4 p-15">
                                <h6 class="mb-1">{{ __('Unpaid Existing') }}</h6>
                                <h4 class="mb-0">{{ $summary['unpaid_count'] }}</h4>
                            </div>
                        </div>
                        <div class="col-md-3 mb-15">
                            <div class="bg-off-white theme-border radius-4 p-15">
                                <h6 class="mb-1">{{ __('Est. Penalties') }}</h6>
                                <h4 class="mb-0">{{ getCurrencySymbol() }}{{ number_format($summary['estimated_penalty'], 2) }}</h4>
                            </div>
                        </div>
                        <div class="col-md-3 mb-15">
                            <div class="bg-off-white theme-border radius-4 p-15">
                                <h6 class="mb-1">{{ __('Est. Due') }}</h6>
                                <h4 class="mb-0">{{ getCurrencySymbol() }}{{ number_format($summary['estimated_due'], 2) }}</h4>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="notice-board-table-area">
                            <div class="bg-off-white theme-border radius-4 p-25">
                                @if(empty($hasDateFilter))
                                    <div class="alert alert-info mb-20">
                                        {{ __('Choose a date range and click Search Arrears to list candidates.') }}
                                    </div>
                                @endif
                                <div class="d-flex justify-content-between align-items-center mb-15">
                                    <h5 class="mb-0">{{ __('Arrears Results') }}</h5>
                                    <div>
                                        <button type="button" class="theme-btn-purple me-2" id="openSelectedReminder">{{ __('Send Reminder (Selected)') }}</button>
                                        <button type="button" class="theme-btn-purple me-2" id="openAllReminder">{{ __('Send Reminder (All Results)') }}</button>
                                        <button type="button" class="theme-btn" id="openGeneratePreview">{{ __('Preview & Invoice Selected') }}</button>
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table class="table theme-border">
                                        <thead>
                                        <tr>
                                            <th><input type="checkbox" id="selectAllRows"></th>
                                            <th>{{ __('Tenant') }}</th>
                                            <th>{{ __('Property / Unit') }}</th>
                                            <th>{{ __('Month') }}</th>
                                            <th>{{ __('Status') }}</th>
                                            <th>{{ __('Rent') }}</th>
                                            <th>{{ __('Tax') }}</th>
                                            <th>{{ __('Penalty') }}</th>
                                            <th>{{ __('Est. Due') }}</th>
                                            <th>{{ __('Action') }}</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @forelse($rows as $row)
                                            <tr data-tenant-id="{{ $row['tenant_id'] }}">
                                                <td>
                                                    <input type="checkbox"
                                                           class="reminder-check {{ $row['can_generate'] ? 'generate-check' : '' }}"
                                                           value="{{ $row['entry_payload'] }}"
                                                           data-tenant-id="{{ $row['tenant_id'] }}"
                                                           data-tenant="{{ $row['tenant_name'] }}"
                                                           data-month="{{ $row['month_label'] }}"
                                                           data-rent="{{ $row['rent_amount'] }}"
                                                           data-tax="{{ $row['tax_amount'] }}"
                                                           data-penalty="{{ $row['penalty_amount'] }}"
                                                           data-total="{{ $row['estimated_total'] }}">
                                                </td>
                                                <td>
                                                    <h6 class="mb-1">{{ $row['tenant_name'] }}</h6>
                                                    <p class="font-13 mb-0">{{ $row['tenant_email'] }}</p>
                                                </td>
                                                <td>
                                                    <h6 class="mb-1">{{ $row['property_name'] }}</h6>
                                                    <p class="font-13 mb-0">{{ $row['unit_name'] }}</p>
                                                </td>
                                                <td>{{ $row['month_label'] }}</td>
                                                <td>
                                                    @if($row['status'] === 'missing')
                                                        <span class="badge bg-danger">{{ __('Missing Invoice') }}</span>
                                                    @else
                                                        <span class="badge bg-warning text-dark">{{ __('Unpaid Invoice') }}</span>
                                                    @endif
                                                </td>
                                                <td>{{ getCurrencySymbol() }}{{ number_format($row['rent_amount'], 2) }}</td>
                                                <td>{{ getCurrencySymbol() }}{{ number_format($row['tax_amount'], 2) }}</td>
                                                <td>{{ getCurrencySymbol() }}{{ number_format($row['penalty_amount'], 2) }}</td>
                                                <td>{{ getCurrencySymbol() }}{{ number_format($row['estimated_total'], 2) }}</td>
                                                <td>
                                                    <button type="button"
                                                            class="p-1 tbl-action-btn send-row-reminder"
                                                            title="{{ __('Send Reminder') }}"
                                                            data-tenant-id="{{ $row['tenant_id'] }}"
                                                            data-tenant="{{ $row['tenant_name'] }}"
                                                            data-month="{{ $row['month_label'] }}">
                                                        <span class="iconify" data-icon="ri:send-plane-fill"></span>
                                                    </button>
                                                    @if($row['invoice_id'])
                                                        <a href="{{ route('owner.invoice.print', $row['invoice_id']) }}" class="p-1 tbl-action-btn" target="_blank">
                                                            <span class="iconify" data-icon="carbon:view-filled"></span>
                                                        </a>
                                                    @else
                                                        <span class="font-13">{{ __('Ready To Invoice') }}</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="10" class="text-center">{{ __('No arrears found for the selected filters.') }}</td>
                                            </tr>
                                        @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="previewGenerateModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Penalty Preview Before Invoice') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="{{ route('owner.invoice.rent-arrears.generate') }}" id="generateArrearsForm">
                    @csrf
                    <div class="modal-body">
                        <div class="table-responsive">
                            <table class="table theme-border">
                                <thead>
                                <tr>
                                    <th>{{ __('Tenant') }}</th>
                                    <th>{{ __('Month') }}</th>
                                    <th>{{ __('Penalty') }}</th>
                                    <th>{{ __('Total') }}</th>
                                </tr>
                                </thead>
                                <tbody id="previewRows"></tbody>
                            </table>
                        </div>
                        <div class="d-flex justify-content-between mt-2">
                            <h6 class="mb-0">{{ __('Selected') }}: <span id="previewCount">0</span></h6>
                            <h6 class="mb-0">{{ __('Grand Total') }}: <span id="previewTotal">{{ getCurrencySymbol() }}0.00</span></h6>
                        </div>
                        <div id="selectedEntryInputs"></div>
                    </div>
                    <div class="modal-footer justify-content-start">
                        <button type="button" class="theme-btn-back me-3" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="submit" class="theme-btn">{{ __('Generate Invoices') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="arrearsReminderModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">{{ __('Send Reminder') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="{{ route('owner.invoice.rent-arrears.reminder') }}" id="arrearsReminderForm">
                    @csrf
                    <div class="modal-body">
                        <div class="modal-inner-form-box bg-off-white theme-border radius-4 p-20">
                            <div class="row">
                                <div class="col-md-12 mb-20">
                                    <label class="label-text-title color-heading font-medium mb-2">{{ __('Title') }}</label>
                                    <input type="text" class="form-control" name="title" id="reminderTitle" value="{{ __('Payment Reminder') }}">
                                </div>
                                <div class="col-md-12 mb-10">
                                    <label class="label-text-title color-heading font-medium mb-2">{{ __('Body') }}</label>
                                    <textarea class="form-control" name="body" id="reminderBody" rows="4">{{ __('Please review your outstanding rent and make payment as soon as possible.') }}</textarea>
                                </div>
                                <div class="col-md-12">
                                    <small class="text-muted">{{ __('Recipients') }}: <span id="reminderRecipientCount">0</span></small>
                                </div>
                            </div>
                        </div>
                        <div id="reminderTenantInputs"></div>
                    </div>
                    <div class="modal-footer justify-content-start">
                        <button type="button" class="theme-btn-back me-3" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                        <button type="submit" class="theme-btn">{{ __('Send') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <input type="hidden" id="getPropertyUnitsRoute" value="{{ route('owner.property.getPropertyUnits') }}">
@endsection

@push('script')
    <script>
        "use strict";

        function formatMoney(amount) {
            return currencySymbol + Number(amount || 0).toFixed(2);
        }

        function loadUnits(propertyId, selectedUnitId = '') {
            var unitSelector = $('#unit_id');
            var defaultOption = '<option value="">{{ __("All Units") }}</option>';
            if (!propertyId) {
                unitSelector.html(defaultOption);
                return;
            }

            $.get($('#getPropertyUnitsRoute').val(), {property_id: propertyId}, function (response) {
                var html = defaultOption;
                if (response && response.data) {
                    response.data.forEach(function (unit) {
                        var selected = String(selectedUnitId) === String(unit.id) ? ' selected' : '';
                        html += '<option value="' + unit.id + '"' + selected + '>' + unit.unit_name + '</option>';
                    });
                }
                unitSelector.html(html);
            });
        }

        $(document).on('change', '#property_id', function () {
            loadUnits($(this).val(), '');
        });

        function openReminderModal(tenantIds) {
            var uniqIds = Array.from(new Set((tenantIds || []).map(function (id) {
                return String(id);
            }).filter(function (id) {
                return id !== '';
            })));

            if (!uniqIds.length) {
                toastr.error('{{ __("No tenants selected for reminder.") }}');
                return;
            }

            var inputs = '';
            uniqIds.forEach(function (id) {
                inputs += '<input type="hidden" name="tenant_ids[]" value="' + id + '">';
            });

            $('#reminderTenantInputs').html(inputs);
            $('#reminderRecipientCount').text(uniqIds.length);
            new bootstrap.Modal(document.getElementById('arrearsReminderModal')).show();
        }

        $(document).on('change', '#selectAllRows', function () {
            $('.reminder-check').prop('checked', $(this).is(':checked'));
        });

        $(document).on('click', '#openGeneratePreview', function () {
            var checkedRows = $('.generate-check:checked');
            if (!checkedRows.length) {
                toastr.error('{{ __("Select at least one missing month to invoice.") }}');
                return;
            }

            var previewHtml = '';
            var inputHtml = '';
            var grandTotal = 0;
            checkedRows.each(function () {
                var row = $(this);
                var total = Number(row.data('total'));
                grandTotal += total;

                previewHtml += '<tr>' +
                    '<td>' + row.data('tenant') + '</td>' +
                    '<td>' + row.data('month') + '</td>' +
                    '<td>' + formatMoney(row.data('penalty')) + '</td>' +
                    '<td>' + formatMoney(total) + '</td>' +
                    '</tr>';

                inputHtml += '<input type="hidden" name="entries[]" value="' + row.val() + '">';
            });

            $('#previewRows').html(previewHtml);
            $('#selectedEntryInputs').html(inputHtml);
            $('#previewCount').text(checkedRows.length);
            $('#previewTotal').text(formatMoney(grandTotal));

            new bootstrap.Modal(document.getElementById('previewGenerateModal')).show();
        });

        $(document).on('click', '.send-row-reminder', function () {
            var tenantName = $(this).data('tenant');
            var monthLabel = $(this).data('month');
            $('#reminderTitle').val('{{ __("Payment Reminder") }}');
            $('#reminderBody').val('{{ __("Please review your outstanding rent for") }} ' + monthLabel + '.');
            openReminderModal([$(this).data('tenant-id')]);
        });

        $(document).on('click', '#openSelectedReminder', function () {
            var selectedTenantIds = $('.reminder-check:checked').map(function () {
                return $(this).data('tenant-id');
            }).get();
            openReminderModal(selectedTenantIds);
        });

        $(document).on('click', '#openAllReminder', function () {
            var allTenantIds = $('.reminder-check').map(function () {
                return $(this).data('tenant-id');
            }).get();
            openReminderModal(allTenantIds);
        });

        loadUnits($('#property_id').val(), $('#unit_id').data('selected'));
    </script>
@endpush
