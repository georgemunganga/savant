@extends('owner.layouts.app')

@section('content')
    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">
                <div class="page-content-wrapper bg-white p-30 radius-20">
                    <div class="row">
                        <div class="col-12">
                            <div
                                class="page-title-box d-sm-flex align-items-center justify-content-between border-bottom mb-20">
                                <div class="page-title-left">
                                    <h3 class="mb-sm-0">{{ $pageTitle }}</h3>
                                </div>
                                <div class="page-title-right">
                                    <ol class="breadcrumb mb-0">
                                        <li class="breadcrumb-item"><a href="{{ route('owner.dashboard') }}"
                                                title="{{ __('Dashboard') }}">{{ __('Dashboard') }}</a></li>
                                        <li class="breadcrumb-item">
                                            <a href="{{ route('owner.property.allUnit') }}"
                                                title="{{ __('Properties') }}">{{ __('Properties') }}</a>
                                        </li>
                                        <li class="breadcrumb-item active" aria-current="page">{{ $pageTitle }}</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tenants-details-layout-wrap position-relative">
                        <div class="row">
                            <div class="col-md-12 col-lg-12 col-xl-12 col-xxl-12">
                                <div class="account-settings-rightside bg-off-white theme-border radius-4 p-25">
                                    <div class="tenants-details-payment-history">
                                        <div class="account-settings-content-box">
                                            <div class="tenants-details-payment-history-table">
                                                <table id="allDataTable" class="table responsive theme-border p-20">
                                                    <thead>
                                                        <tr>
                                                            <th>{{ __('SL') }}</th>
                                                            <th data-priority="1">{{ __('Name') }}</th>
                                                            <th>{{ __('Image') }}</th>
                                                            <th>{{ __('Property') }}</th>
                                                            <th>{{ __('Occupancy') }}</th>
                                                            <th>{{ __('Availability') }}</th>
                                                            <th>{{ __('Last Vacated') }}</th>
                                                            <th class="text-center">{{ __('Action') }}</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach ($units as $unit)
                                                            <tr>
                                                                <td>{{ $loop->iteration }}</td>
                                                                <td>{{ $unit->unit_name }}</td>
                                                                <td>
                                                                    <img class="rounded-circle avatar-md tbl-user-image"
                                                                        src="{{ $unit->folder_name && $unit->file_name ? assetUrl($unit->folder_name . '/' . $unit->file_name) : asset('assets/images/no-image.jpg') }}">
                                                                </td>
                                                                <td>{{ $unit->property_name }}</td>
                                                                <td>
                                                                    <span
                                                                        class="badge {{ $unit->occupancy_state === 'full' ? 'bg-danger' : ($unit->occupancy_state === 'partially_occupied' ? 'bg-warning text-dark' : 'bg-success') }}">
                                                                        {{ $unit->occupancy_label }}
                                                                    </span>
                                                                    @if (($unit->active_tenant_count ?? 0) > 0)
                                                                        <div class="font-13 mt-1">
                                                                            {{ $unit->active_tenant_names }}
                                                                        </div>
                                                                    @endif
                                                                </td>
                                                                <td>
                                                                    <span
                                                                        class="badge {{ ($unit->manual_availability_status ?? 'active') === 'off_market' ? 'bg-secondary' : ((($unit->manual_availability_status ?? 'active') === 'on_hold') ? 'bg-dark' : (($unit->is_available_for_assignment ?? false) ? 'bg-success' : 'bg-danger')) }}">
                                                                        {{ $unit->availability_label }}
                                                                    </span>
                                                                    <div class="font-13 mt-1">
                                                                        {{ __('Slots') }}: {{ $unit->available_slots ?? 0 }}
                                                                    </div>
                                                                </td>
                                                                <td>
                                                                    {{ $unit->available_since ? \Carbon\Carbon::parse($unit->available_since)->format('d M Y H:i') : __('N/A') }}
                                                                </td>
                                                                <td class="text-center">
                                                                    <a class="p-1 tbl-action-btn"
                                                                        href="{{ route('owner.property.unit.details', $unit->id) }}"
                                                                        title="{{ __('View Unit History') }}">
                                                                        <span class="iconify"
                                                                            data-icon="mdi:history"></span>
                                                                    </a>
                                                                    @if (($unit->active_tenant_count ?? 0) == 1 && !is_null($unit->first_tenant_id))
                                                                        <a class="p-1 tbl-action-btn"
                                                                            href="{{ route('owner.tenant.details', [$unit->first_tenant_id, 'tab' => 'profile']) }}"
                                                                            title="{{ __('View Tenant') }}">
                                                                            <span class="iconify" data-icon="carbon:view-filled"></span>
                                                                        </a>
                                                                    @elseif(($unit->active_tenant_count ?? 0) > 1)
                                                                        <a class="p-1 tbl-action-btn"
                                                                            href="{{ route('owner.tenant.index', ['type' => 'all']) }}"
                                                                            title="{{ __('View Tenants') }}">
                                                                            <span class="iconify" data-icon="carbon:view-filled"></span>
                                                                        </a>
                                                                    @endif
                                                                    @if ($unit->can_delete_unit)
                                                                        <button class="p-1 tbl-action-btn deleteItem"
                                                                            data-formid="delete_row_form_{{ $unit->id }}">
                                                                            <span class="iconify"
                                                                                data-icon="ep:delete-filled"></span>
                                                                        </button>
                                                                        <form
                                                                            action="{{ route('owner.property.unit.delete', [$unit->id]) }}"
                                                                            method="post"
                                                                            id="delete_row_form_{{ $unit->id }}">
                                                                            {{ method_field('DELETE') }}
                                                                            <input type="hidden" name="_token"
                                                                                value="{{ csrf_token() }}">
                                                                        </form>
                                                                    @endif
                                                                </td>
                                                            </tr>
                                                        @endforeach
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
            </div>
        </div>
    </div>
@endsection

@push('style')
    @include('common.layouts.datatable-style')
@endpush

@push('script')
    @include('common.layouts.datatable-script')
    <script src="{{ asset('assets/js/pages/alldatatables.init.js') }}"></script>
@endpush
