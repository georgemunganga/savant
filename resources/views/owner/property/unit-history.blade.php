@extends('owner.layouts.app')

@section('content')
    <div class="main-content">
        <div class="page-content">
            <div class="container-fluid">
                @include('shared.unit-history-detail', [
                    'homeUrl' => route('owner.dashboard'),
                    'backUrl' => route('owner.property.allUnit'),
                    'backLabel' => __('Units'),
                    'updateUrl' => route('owner.property.unit.operational.update', $unit->id),
                ])
            </div>
        </div>
    </div>
@endsection
