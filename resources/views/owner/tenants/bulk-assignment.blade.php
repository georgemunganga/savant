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
                                        <li class="breadcrumb-item"><a
                                                href="{{ route('owner.tenant.index', ['type' => 'all']) }}">{{ __('Tenants') }}</a>
                                        </li>
                                        <li class="breadcrumb-item active" aria-current="page">{{ $pageTitle }}</li>
                                    </ol>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="bg-off-white theme-border radius-4 p-25">
                                <div
                                    class="d-flex flex-wrap gap-2 justify-content-between align-items-center border-bottom pb-20 mb-20">
                                    <div>
                                        <h5 class="mb-1">{{ __('Assignment Planner') }}</h5>
                                        <p class="mb-0">{{ __('Prepare multiple tenant-to-unit assignments at once.') }}
                                        </p>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button type="button" class="theme-btn border-0 w-auto"
                                            id="addAssignmentRow">{{ __('Add Row') }}</button>
                                        <button type="button" class="theme-btn d-outline-btn border-0 w-auto"
                                            id="clearAssignments">{{ __('Reset') }}</button>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                    <table class="table theme-border align-middle mb-0" id="bulkAssignmentTable">
                                        <thead>
                                            <tr>
                                                <th>{{ __('Tenant') }}</th>
                                                <th>{{ __('Property') }}</th>
                                                <th>{{ __('Units') }}</th>
                                                <th class="text-center">{{ __('Action') }}</th>
                                            </tr>
                                        </thead>
                                        <tbody id="assignmentRows"></tbody>
                                    </table>
                                </div>

                                <div class="mt-20 d-flex flex-wrap justify-content-between align-items-center gap-3">
                                    <div>
                                        <div class="fw-semibold">{{ __('Summary') }}</div>
                                        <div class="font-13 text-muted" id="assignmentSummary">
                                            {{ __('No assignment row added yet.') }}
                                        </div>
                                    </div>
                                    <button type="button" class="theme-btn w-auto"
                                        id="saveAssignments">{{ __('Save Assignments') }}</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <input type="hidden" id="bulkAssignmentStoreRoute" value="{{ route('owner.tenant.bulk-assignment.store') }}">
    <input type="hidden" id="bulkAssignmentCsrfToken" value="{{ csrf_token() }}">
@endsection

@push('script')
    <script>
        window.bulkAssignmentData = @json($bulkAssignmentData);
    </script>
    <script src="{{ asset('assets/js/custom/tenant-bulk-assignment.js') }}"></script>
@endpush
