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
                            <a href="{{ $homeUrl }}" title="{{ __('Dashboard') }}">{{ __('Dashboard') }}</a>
                        </li>
                        <li class="breadcrumb-item">
                            <a href="{{ $backUrl }}" title="{{ $backLabel }}">{{ $backLabel }}</a>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ $pageTitle }}</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

    @php
        $manualStatus = old('manual_availability_status', $unit->manual_availability_status ?? \App\Models\PropertyUnit::MANUAL_AVAILABILITY_ACTIVE);
        $maxOccupancy = old('max_occupancy', $unit->max_occupancy ?? 1);
        $manualReason = old('manual_status_reason', $unit->manual_status_reason);
        $imageUrl = $unit->folder_name && $unit->file_name
            ? assetUrl($unit->folder_name . '/' . $unit->file_name)
            : asset('assets/images/no-image.jpg');
        $ownerName = trim(($unit->owner_first_name ?? '') . ' ' . ($unit->owner_last_name ?? ''));
    @endphp

    <div class="row mb-25">
        <div class="col-lg-8">
            <div class="bg-off-white theme-border radius-4 p-25 h-100">
                <div class="d-flex gap-3 align-items-start flex-wrap">
                    <img src="{{ $imageUrl }}" alt="{{ $unit->unit_name }}"
                        class="rounded" style="width: 120px; height: 120px; object-fit: cover;">
                    <div class="flex-grow-1">
                        <div class="d-flex gap-2 flex-wrap align-items-center mb-2">
                            <h4 class="mb-0">{{ $unit->unit_name }}</h4>
                            <span
                                class="badge {{ $unit->occupancy_state === 'full' ? 'bg-danger' : ($unit->occupancy_state === 'partially_occupied' ? 'bg-warning text-dark' : 'bg-success') }}">
                                {{ $unit->occupancy_label }}
                            </span>
                            <span
                                class="badge {{ ($unit->manual_availability_status ?? 'active') === 'off_market' ? 'bg-secondary' : ((($unit->manual_availability_status ?? 'active') === 'on_hold') ? 'bg-dark' : (($unit->is_available_for_assignment ?? false) ? 'bg-success' : 'bg-danger')) }}">
                                {{ $unit->availability_label }}
                            </span>
                            @if ($unit->has_public_option ?? false)
                                <span class="badge bg-info text-dark">{{ __('Website Enabled') }}</span>
                            @endif
                        </div>
                        <p class="mb-1"><strong>{{ __('Property:') }}</strong> {{ $property->name }}</p>
                        <p class="mb-1"><strong>{{ __('Address:') }}</strong> {{ $property->propertyDetail?->address ?: __('N/A') }}</p>
                        @if ($ownerName !== '')
                            <p class="mb-1"><strong>{{ __('Owner:') }}</strong> {{ $ownerName }}</p>
                        @endif
                        <p class="mb-0">
                            <strong>{{ __('Current capacity:') }}</strong>
                            {{ $activeTenantCount }}/{{ $unit->max_occupancy }} {{ __('occupied') }}
                            {{ __('with') }} {{ $availableSlots }} {{ __('slot(s)') }} {{ __('remaining') }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="bg-off-white theme-border radius-4 p-25 h-100">
                <h5 class="border-bottom pb-15 mb-20">{{ __('Unit Snapshot') }}</h5>
                <div class="d-flex justify-content-between mb-2">
                    <span>{{ __('Last Vacated') }}</span>
                    <span>{{ $unit->last_vacated_at ? \Carbon\Carbon::parse($unit->last_vacated_at)->format('d M Y H:i') : __('N/A') }}</span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>{{ __('Assignments Logged') }}</span>
                    <span>{{ $totalAssignments }}</span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>{{ __('Current Occupants') }}</span>
                    <span>{{ $activeTenantCount }}</span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>{{ __('Available Slots') }}</span>
                    <span>{{ $availableSlots }}</span>
                </div>
                <div class="d-flex justify-content-between">
                    <span>{{ __('Manual Status') }}</span>
                    <span>{{ \App\Models\PropertyUnit::manualAvailabilityOptions()[$unit->manual_availability_status ?? \App\Models\PropertyUnit::MANUAL_AVAILABILITY_ACTIVE] ?? __('Active') }}</span>
                </div>
                @if (!empty($publicOption))
                    <div class="border-top pt-15 mt-15">
                        <div class="font-13 mb-1">{{ __('Public Website Option') }}</div>
                        <div>{{ __('Rental Kind') }}: {{ \Illuminate\Support\Str::headline(str_replace('_', ' ', $publicOption->rental_kind)) }}</div>
                        <div>{{ __('Monthly') }}: {{ currencyPrice($publicOption->monthly_rate ?? 0) }}</div>
                        @if (!is_null($publicOption->nightly_rate))
                            <div>{{ __('Nightly') }}: {{ currencyPrice($publicOption->nightly_rate) }}</div>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="row mb-25">
        <div class="col-12">
            <div class="bg-off-white theme-border radius-4 p-25">
                <div class="d-flex justify-content-between align-items-center border-bottom pb-15 mb-20">
                    <h5 class="mb-0">{{ __('Operational Controls') }}</h5>
                    <span class="font-13 text-muted">{{ __('These controls affect owner/admin assignment availability and public website availability.') }}</span>
                </div>
                <form action="{{ $updateUrl }}" method="post">
                    @csrf
                    <div class="row">
                        <div class="col-md-4 mb-20">
                            <label class="label-text-title color-heading font-medium mb-2">{{ __('Manual Availability Status') }}</label>
                            <select name="manual_availability_status" class="form-select">
                                @foreach (\App\Models\PropertyUnit::manualAvailabilityOptions() as $value => $label)
                                    <option value="{{ $value }}" {{ $manualStatus === $value ? 'selected' : '' }}>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                            @error('manual_availability_status')
                                <div class="text-danger font-13 mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-4 mb-20">
                            <label class="label-text-title color-heading font-medium mb-2">{{ __('Max Occupancy') }}</label>
                            <input type="number" min="1" name="max_occupancy" class="form-control"
                                value="{{ $maxOccupancy }}">
                            @error('max_occupancy')
                                <div class="text-danger font-13 mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-4 mb-20">
                            <label class="label-text-title color-heading font-medium mb-2">{{ __('Last Status Change') }}</label>
                            <input type="text" class="form-control"
                                value="{{ $unit->manual_status_changed_at ? \Carbon\Carbon::parse($unit->manual_status_changed_at)->format('d M Y H:i') : __('N/A') }}"
                                disabled>
                        </div>
                        <div class="col-12 mb-20">
                            <label class="label-text-title color-heading font-medium mb-2">{{ __('Reason / Notes') }}</label>
                            <textarea name="manual_status_reason" rows="3" class="form-control">{{ $manualReason }}</textarea>
                            @error('manual_status_reason')
                                <div class="text-danger font-13 mt-1">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-12 text-end">
                            <button type="submit" class="theme-btn">{{ __('Save Unit Controls') }}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="row mb-25">
        <div class="col-12">
            <div class="bg-off-white theme-border radius-4 p-25">
                <h5 class="border-bottom pb-15 mb-20">{{ __('Current Occupants') }}</h5>
                <div class="table-responsive">
                    <table class="table theme-border align-middle mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('Tenant') }}</th>
                                <th>{{ __('Email') }}</th>
                                <th>{{ __('Assigned At') }}</th>
                                <th>{{ __('Lease Range') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($currentAssignments as $assignment)
                                <tr>
                                    <td>{{ trim(($assignment->tenant?->user?->first_name ?? '') . ' ' . ($assignment->tenant?->user?->last_name ?? '')) ?: __('N/A') }}</td>
                                    <td>{{ $assignment->tenant?->user?->email ?: __('N/A') }}</td>
                                    <td>{{ optional($assignment->assigned_at ?: $assignment->created_at)->format('d M Y H:i') ?: __('N/A') }}</td>
                                    <td>
                                        {{ $assignment->tenant?->lease_start_date ?: __('N/A') }}
                                        -
                                        {{ $assignment->tenant?->lease_end_date ?: __('Open') }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center">{{ __('No active occupants in this unit.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-25">
        <div class="col-lg-4">
            <div class="bg-off-white theme-border radius-4 p-25 h-100">
                <h5 class="border-bottom pb-15 mb-20">{{ __('Last Tenant To Leave') }}</h5>
                @if ($lastReleasedAssignment)
                    <div class="mb-2">
                        <strong>{{ trim(($lastReleasedAssignment->tenant?->user?->first_name ?? '') . ' ' . ($lastReleasedAssignment->tenant?->user?->last_name ?? '')) ?: __('N/A') }}</strong>
                    </div>
                    <div class="mb-2">{{ $lastReleasedAssignment->tenant?->user?->email ?: __('N/A') }}</div>
                    <div class="mb-2">
                        {{ __('Released') }}: {{ optional($lastReleasedAssignment->released_at)->format('d M Y H:i') ?: __('N/A') }}
                    </div>
                    <div class="mb-2">{{ __('Reason') }}: {{ $lastReleasedAssignment->release_reason ?: __('N/A') }}</div>
                    <div class="mb-0">
                        {{ __('By') }}: {{ trim(($lastReleasedAssignment->releasedBy?->first_name ?? '') . ' ' . ($lastReleasedAssignment->releasedBy?->last_name ?? '')) ?: __('System') }}
                    </div>
                @else
                    <p class="mb-0">{{ __('This unit has not recorded a move-out yet.') }}</p>
                @endif
            </div>
        </div>
        <div class="col-lg-8">
            <div class="bg-off-white theme-border radius-4 p-25 h-100">
                <h5 class="border-bottom pb-15 mb-20">{{ __('Assignment History') }}</h5>
                <div class="table-responsive">
                    <table class="table theme-border align-middle mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('Tenant') }}</th>
                                <th>{{ __('Assigned At') }}</th>
                                <th>{{ __('Released At') }}</th>
                                <th>{{ __('Release Reason') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($pastAssignments as $assignment)
                                <tr>
                                    <td>{{ trim(($assignment->tenant?->user?->first_name ?? '') . ' ' . ($assignment->tenant?->user?->last_name ?? '')) ?: __('N/A') }}</td>
                                    <td>{{ optional($assignment->assigned_at ?: $assignment->created_at)->format('d M Y H:i') ?: __('N/A') }}</td>
                                    <td>{{ optional($assignment->released_at ?: $assignment->updated_at)->format('d M Y H:i') ?: __('N/A') }}</td>
                                    <td>{{ $assignment->release_reason ?: __('N/A') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center">{{ __('No historical assignments recorded for this unit.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="bg-off-white theme-border radius-4 p-25">
                <h5 class="border-bottom pb-15 mb-20">{{ __('Unit Activity Log') }}</h5>
                <div class="table-responsive">
                    <table class="table theme-border align-middle mb-0">
                        <thead>
                            <tr>
                                <th>{{ __('When') }}</th>
                                <th>{{ __('Event') }}</th>
                                <th>{{ __('Actor') }}</th>
                                <th>{{ __('Tenant') }}</th>
                                <th>{{ __('Notes') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($activityLogs as $log)
                                <tr>
                                    <td>{{ optional($log->occurred_at ?: $log->created_at)->format('d M Y H:i') ?: __('N/A') }}</td>
                                    <td>{{ \Illuminate\Support\Str::headline(str_replace('_', ' ', $log->event_type)) }}</td>
                                    <td>{{ trim(($log->actor?->first_name ?? '') . ' ' . ($log->actor?->last_name ?? '')) ?: __('System') }}</td>
                                    <td>{{ trim(($log->tenant?->user?->first_name ?? '') . ' ' . ($log->tenant?->user?->last_name ?? '')) ?: __('N/A') }}</td>
                                    <td>{{ $log->notes ?: __('N/A') }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center">{{ __('No unit activity recorded yet.') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
