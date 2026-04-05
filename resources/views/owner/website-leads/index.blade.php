@extends('owner.layouts.app')

@section('content')
    @php
        $statusBadgeClass = function ($status) {
            if (in_array($status, ['confirmed', 'converted', 'checked_in', 'completed'], true)) {
                return 'bg-green-transparent green-color';
            }

            if (in_array($status, ['contacted'], true)) {
                return 'bg-blue-transparent blue-color';
            }

            if (in_array($status, ['cancelled', 'closed'], true)) {
                return 'bg-red-transparent red-color';
            }

            return 'bg-orange-transparent orange-color';
        };
        $optionLabel = function ($option) {
            if (!$option) {
                return __('N/A');
            }

            if ($option->rental_kind === 'whole_property') {
                return __('Whole property');
            }

            if ($option->rental_kind === 'whole_unit') {
                return __('Whole unit');
            }

            if ($option->rental_kind === 'private_room') {
                return __('Private room');
            }

            return ucwords(str_replace('_', ' ', (string) $option->rental_kind));
        };
    @endphp

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
                                        <li class="breadcrumb-item">
                                            <a href="{{ route('owner.dashboard') }}" title="{{ __('Dashboard') }}">{{ __('Dashboard') }}</a>
                                        </li>
                                        <li class="breadcrumb-item active" aria-current="page">{{ $pageTitle }}</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row mb-25">
                        <div class="col-12">
                            <div class="property-search-inner-bg bg-off-white theme-border radius-4 p-20">
                                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                                    <div class="nav nav-pills gap-2">
                                        <a
                                            href="{{ route('owner.website-leads.index', ['tab' => 'bookings']) }}"
                                            class="btn {{ $activeTab === 'bookings' ? 'theme-btn' : 'theme-btn-outline' }}"
                                        >
                                            {{ __('Live Bookings') }} ({{ $bookingCount }})
                                        </a>
                                        <a
                                            href="{{ route('owner.website-leads.index', ['tab' => 'waitlist']) }}"
                                            class="btn {{ $activeTab === 'waitlist' ? 'theme-btn' : 'theme-btn-outline' }}"
                                        >
                                            {{ __('Waiting List') }} ({{ $waitlistCount }})
                                        </a>
                                    </div>

                                    <form method="GET" action="{{ route('owner.website-leads.index') }}" class="row g-2 align-items-center">
                                        <input type="hidden" name="tab" value="{{ $activeTab }}">
                                        <div class="col-md-4">
                                            <input
                                                type="text"
                                                name="search"
                                                value="{{ $filters['search'] }}"
                                                class="form-control"
                                                placeholder="{{ __('Search guest, email, or phone') }}"
                                            >
                                        </div>
                                        <div class="col-md-3">
                                            <select name="property_id" class="form-select">
                                                <option value="">{{ __('All Properties') }}</option>
                                                @foreach ($properties as $property)
                                                    <option value="{{ $property->id }}" @selected($filters['property_id'] == $property->id)>{{ $property->name }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <select name="status" class="form-select">
                                                <option value="">{{ __('All Statuses') }}</option>
                                                @foreach (($activeTab === 'bookings' ? $bookingStatuses : $waitlistStatuses) as $status)
                                                    <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ ucwords(str_replace('_', ' ', $status)) }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div class="col-auto">
                                            <button type="submit" class="theme-btn w-auto">{{ __('Apply') }}</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        @forelse ($records as $record)
                            <div class="col-md-6 col-xl-6 col-xxl-4">
                                <div class="property-item tenants-item bg-off-white theme-border radius-10 mb-25 h-100">
                                    <div class="property-item-content tenants-item-content p-20">
                                        <div class="d-flex align-items-start justify-content-between gap-3 mb-15 border-bottom pb-15">
                                            <div>
                                                <h4 class="mb-1">{{ $record->full_name }}</h4>
                                                <p class="font-13 text-break mb-1">{{ $record->email }}</p>
                                                <p class="font-13 mb-0">{{ $record->phone ?: __('No phone provided') }}</p>
                                            </div>
                                            <span class="radius-4 px-2 py-1 font-13 {{ $statusBadgeClass($record->status) }}">
                                                {{ ucwords(str_replace('_', ' ', $record->status)) }}
                                            </span>
                                        </div>

                                        <div class="tenant-details-toggle">
                                            <div class="py-2 px-0 d-flex justify-content-between align-items-center" style="border-bottom: 1px solid rgba(0,0,0,0.08);">
                                                <span class="font-13 color-heading">{{ __('Property') }}</span>
                                                <span class="font-13 text-end">{{ $record->property->name ?? __('N/A') }}</span>
                                            </div>
                                            <div class="py-2 px-0 d-flex justify-content-between align-items-center" style="border-bottom: 1px solid rgba(0,0,0,0.08);">
                                                <span class="font-13 color-heading">{{ __('Website Option') }}</span>
                                                <span class="font-13 text-end">{{ $optionLabel($record->option) }}</span>
                                            </div>
                                            <div class="py-2 px-0 d-flex justify-content-between align-items-center" style="border-bottom: 1px solid rgba(0,0,0,0.08);">
                                                <span class="font-13 color-heading">{{ __('Stay') }}</span>
                                                <span class="font-13 text-end">
                                                    {{ ucfirst($record->stay_mode) }}<br>
                                                    {{ \Carbon\Carbon::parse($record->start_date)->format('d M Y') }} -
                                                    {{ \Carbon\Carbon::parse($record->end_date)->format('d M Y') }}
                                                </span>
                                            </div>
                                            <div class="py-2 px-0 d-flex justify-content-between align-items-center" style="border-bottom: 1px solid rgba(0,0,0,0.08);">
                                                <span class="font-13 color-heading">{{ __('Guests') }}</span>
                                                <span class="font-13">{{ $record->guests }}</span>
                                            </div>

                                            @if ($activeTab === 'bookings')
                                                <div class="py-2 px-0 d-flex justify-content-between align-items-center" style="border-bottom: 1px solid rgba(0,0,0,0.08);">
                                                    <span class="font-13 color-heading">{{ __('Payment Plan') }}</span>
                                                    <span class="font-13">{{ ucfirst($record->payment_plan ?? 'later') }}</span>
                                                </div>
                                                <div class="py-2 px-0 d-flex justify-content-between align-items-center" style="border-bottom: 1px solid rgba(0,0,0,0.08);">
                                                    <span class="font-13 color-heading">{{ __('Assignment') }}</span>
                                                    <span class="font-13">
                                                        @if ($record->has_assignment)
                                                            {{ $record->unit->unit_name ?? __('Assigned') }}
                                                        @else
                                                            {{ __('Pending manual assignment') }}
                                                        @endif
                                                    </span>
                                                </div>
                                                <div class="py-2 px-0 d-flex justify-content-between align-items-center" style="border-bottom: 1px solid rgba(0,0,0,0.08);">
                                                    <span class="font-13 color-heading">{{ __('Tenant Account') }}</span>
                                                    <span class="font-13">
                                                        @if ($record->tenant_id)
                                                            <a href="{{ route('owner.tenant.details', [$record->tenant_id, 'tab' => 'profile']) }}">
                                                                {{ __('View Tenant') }}
                                                            </a>
                                                        @else
                                                            {{ __('Not linked') }}
                                                        @endif
                                                    </span>
                                                </div>
                                                <div class="py-2 px-0 d-flex justify-content-between align-items-center">
                                                    <span class="font-13 color-heading">{{ __('Confirmed') }}</span>
                                                    <span class="font-13">{{ optional($record->confirmed_at)->format('d M Y H:i') ?: $record->created_at->format('d M Y H:i') }}</span>
                                                </div>
                                            @else
                                                <div class="py-2 px-0 d-flex justify-content-between align-items-center">
                                                    <span class="font-13 color-heading">{{ __('Joined') }}</span>
                                                    <span class="font-13">{{ $record->created_at->format('d M Y H:i') }}</span>
                                                </div>
                                            @endif
                                        </div>

                                        <form
                                            action="{{ $activeTab === 'bookings' ? route('owner.website-leads.booking.status', $record->id) : route('owner.website-leads.waitlist.status', $record->id) }}"
                                            method="POST"
                                            class="mt-20"
                                        >
                                            @csrf
                                            <label class="font-13 color-heading mb-2 d-block">{{ __('Update Status') }}</label>
                                            <div class="d-flex gap-2">
                                                <select name="status" class="form-select">
                                                    @foreach (($activeTab === 'bookings' ? $bookingStatuses : $waitlistStatuses) as $status)
                                                        <option value="{{ $status }}" @selected($record->status === $status)>{{ ucwords(str_replace('_', ' ', $status)) }}</option>
                                                    @endforeach
                                                </select>
                                                <button type="submit" class="theme-btn w-auto">{{ __('Save') }}</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="row justify-content-center">
                                <div class="col-12 col-md-6 col-lg-6 col-xl-4">
                                    <div class="empty-properties-box text-center">
                                        <img src="{{ asset('assets/images/empty-img.png') }}" alt="" class="img-fluid">
                                        <h3 class="mt-25">{{ __('Empty') }}</h3>
                                        <p class="font-13 color-heading">
                                            {{ $activeTab === 'bookings' ? __('No website bookings found for the current filters.') : __('No waiting list entries found for the current filters.') }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                        @endforelse
                    </div>

                    @if ($records instanceof \Illuminate\Contracts\Pagination\Paginator && $records->hasPages())
                        <div class="d-flex justify-content-center mt-10">
                            {{ $records->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
