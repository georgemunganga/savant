@extends('admin.layouts.app')

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
                                        <li class="breadcrumb-item"><a href="{{ route('admin.dashboard') }}">{{ __('Dashboard') }}</a></li>
                                        <li class="breadcrumb-item active" aria-current="page">{{ $pageTitle }}</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-off-white theme-border radius-4 p-25 mb-25">
                        <form method="get" action="{{ route('admin.unit.index') }}">
                            <div class="row">
                                <div class="col-md-3 mb-20">
                                    <label class="label-text-title color-heading font-medium mb-2">{{ __('Owner') }}</label>
                                    <select name="owner_id" class="form-select">
                                        <option value="">{{ __('All Owners') }}</option>
                                        @foreach ($owners as $owner)
                                            <option value="{{ $owner->id }}" {{ (string) request('owner_id') === (string) $owner->id ? 'selected' : '' }}>
                                                {{ trim($owner->first_name . ' ' . $owner->last_name) }}{{ $owner->email ? ' (' . $owner->email . ')' : '' }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-3 mb-20">
                                    <label class="label-text-title color-heading font-medium mb-2">{{ __('Property') }}</label>
                                    <select name="property_id" class="form-select">
                                        <option value="">{{ __('All Properties') }}</option>
                                        @foreach ($properties as $property)
                                            <option value="{{ $property->id }}" {{ (string) request('property_id') === (string) $property->id ? 'selected' : '' }}>
                                                {{ $property->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-2 mb-20">
                                    <label class="label-text-title color-heading font-medium mb-2">{{ __('Status') }}</label>
                                    <select name="status" class="form-select">
                                        <option value="">{{ __('All Statuses') }}</option>
                                        <option value="available" {{ request('status') === 'available' ? 'selected' : '' }}>{{ __('Available') }}</option>
                                        <option value="vacant" {{ request('status') === 'vacant' ? 'selected' : '' }}>{{ __('Vacant') }}</option>
                                        <option value="partially_occupied" {{ request('status') === 'partially_occupied' ? 'selected' : '' }}>{{ __('Partially Occupied') }}</option>
                                        <option value="full" {{ request('status') === 'full' ? 'selected' : '' }}>{{ __('Full') }}</option>
                                        <option value="on_hold" {{ request('status') === 'on_hold' ? 'selected' : '' }}>{{ __('On Hold') }}</option>
                                        <option value="off_market" {{ request('status') === 'off_market' ? 'selected' : '' }}>{{ __('Off Market') }}</option>
                                    </select>
                                </div>
                                <div class="col-md-2 mb-20">
                                    <label class="label-text-title color-heading font-medium mb-2">{{ __('Search') }}</label>
                                    <input type="text" name="search" value="{{ request('search') }}" class="form-control"
                                        placeholder="{{ __('Unit, property, owner') }}">
                                </div>
                                <div class="col-md-2 mb-20 d-flex align-items-end gap-2">
                                    <button type="submit" class="theme-btn w-100">{{ __('Filter') }}</button>
                                    <a href="{{ route('admin.unit.index') }}" class="theme-btn d-outline-btn w-100">{{ __('Reset') }}</a>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="bg-off-white theme-border radius-4 p-25">
                        <div class="table-responsive">
                            <table class="table theme-border align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>{{ __('Unit') }}</th>
                                        <th>{{ __('Property') }}</th>
                                        <th>{{ __('Owner') }}</th>
                                        <th>{{ __('Occupancy') }}</th>
                                        <th>{{ __('Availability') }}</th>
                                        <th>{{ __('Last Vacated') }}</th>
                                        <th class="text-center">{{ __('Action') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($units as $unit)
                                        <tr>
                                            <td>
                                                <strong>{{ $unit->unit_name }}</strong>
                                                <div class="font-13 text-muted">{{ __('Capacity') }}: {{ $unit->max_occupancy }}</div>
                                            </td>
                                            <td>
                                                {{ $unit->property_name }}
                                                <div class="font-13 text-muted">{{ $unit->property_address ?: __('N/A') }}</div>
                                            </td>
                                            <td>
                                                {{ $unit->owner_name ?: __('N/A') }}
                                                <div class="font-13 text-muted">{{ $unit->owner_email ?: __('N/A') }}</div>
                                            </td>
                                            <td>
                                                <span class="badge {{ $unit->occupancy_state === 'full' ? 'bg-danger' : ($unit->occupancy_state === 'partially_occupied' ? 'bg-warning text-dark' : 'bg-success') }}">
                                                    {{ $unit->occupancy_label }}
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge {{ ($unit->manual_availability_status ?? 'active') === 'off_market' ? 'bg-secondary' : ((($unit->manual_availability_status ?? 'active') === 'on_hold') ? 'bg-dark' : (($unit->is_available_for_assignment ?? false) ? 'bg-success' : 'bg-danger')) }}">
                                                    {{ $unit->availability_label }}
                                                </span>
                                                <div class="font-13 mt-1">{{ __('Slots') }}: {{ $unit->available_slots ?? 0 }}</div>
                                            </td>
                                            <td>{{ $unit->last_vacated_at ? \Carbon\Carbon::parse($unit->last_vacated_at)->format('d M Y H:i') : __('N/A') }}</td>
                                            <td class="text-center">
                                                <a href="{{ route('admin.unit.show', $unit->id) }}" class="p-1 tbl-action-btn"
                                                    title="{{ __('View Unit History') }}">
                                                    <span class="iconify" data-icon="carbon:view-filled"></span>
                                                </a>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="7" class="text-center">{{ __('No units found for the selected filters.') }}</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>

                        @if (method_exists($units, 'links'))
                            <div class="mt-20">
                                {{ $units->links() }}
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
