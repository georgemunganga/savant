@extends('admin.layouts.app')

@section('content')
    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">
                @include('shared.unit-history-detail', [
                    'homeUrl' => route('admin.dashboard'),
                    'backUrl' => route('admin.unit.index'),
                    'backLabel' => __('Units'),
                    'updateUrl' => route('admin.unit.update', $unit->id),
                ])
            </div>
        </div>
    </div>
@endsection
