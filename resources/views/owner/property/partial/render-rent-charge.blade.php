<form class="ajax" action="{{ route('owner.property.rentCharge.store') }}" method="post" data-handler="stepChange">
    @csrf
    <input type="text" name="ids[]" class="d-none" id="property_unit_ids" value="{{ @json_encode($propertyUnitIds) }}">
    <input type="text" name="property_id" class="d-none property_id" value="{{ $property->id }}">
    <div class="form-card add-property-box bg-off-white theme-border radius-4 p-20 pb-0">
        <div class="add-property-title border-bottom pb-25 mb-25">
            <h4>{{ __('Rent & Charges') }}</h4>
        </div>
        <div class="bg-white theme-border radius-4 p-20 pb-0 mb-25">
            <div class="row">
                <div class="col-md-12">
                    <label class="label-text-title color-heading font-medium mb-2">{{ __('Unit Name') }}</label>
                </div>
            </div>
            <div class="row align-items-center">
                <div class="col-md-6 col-lg-6 col-xl-4 col-xxl-3 mb-25">
                    <select class="form-select flex-shrink-0" id="select_unit_id">
                        <option value="">--{{ __('Select Unit') }}--</option>
                        @foreach ($propertyUnits as $propertyUnit)
                            <option value="{{ $propertyUnit->id }}">{{ $propertyUnit->unit_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6 col-lg-4 mb-25">
                    <div class="form-group custom-checkbox">
                        <input type="checkbox" id="sameUnitRent">
                        <label class="fw-normal" for="sameUnitRent">{{ __('Same Rent for all Unit') }}</label>
                    </div>
                </div>
            </div>
        </div>

        <div class="add-property-inner-box">
            <!-- Unit Block Wrapper Start -->
            <div class="unit-block-wrapper">
                <div class="accordion" id="accordionExample">
                    @php $c = 1; @endphp
                    @foreach ($propertyUnits as $propertyUnit)
                        @php $publicOption = @$publicOptionsByUnit[$propertyUnit->id]; @endphp
                        <input type="hidden" name="propertyUnit[id][]" value="{{ $propertyUnit->id }}">
                        <div class="accordion-item unit-block-item-box bg-white radius-4 mb-25">
                            <h4 class="accordion-header" id="heading{{ $propertyUnit->id }}">
                                <button class="accordion-button {{ $c == 1 ? '' : 'collapsed' }} p-20" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#collapse{{ $propertyUnit->id }}"
                                    aria-expanded="{{ $c == 1 ? 'true' : 'false' }}"
                                    aria-controls="collapse{{ $propertyUnit->id }}">
                                    {{ $propertyUnit->unit_name }}
                                </button>
                            </h4>
                            <div id="collapse{{ $propertyUnit->id }}"
                                class="accordion-collapse collapse {{ $c == 1 ? 'show' : '' }}"
                                aria-labelledby="heading{{ $propertyUnit->id }}" data-bs-parent="#accordionExample">
                                <div class="accordion-body">
                                    <div class="row">
                                        <div class="col-md-6 col-lg-6 col-xl-3 mb-25">
                                            <label
                                                class="label-text-title color-heading font-medium mb-2">{{ __('General Rent') }}</label>
                                            <input type="number" name="propertyUnit[general_rent][]"
                                                id="general_rent{{ $propertyUnit->id }}"
                                                value="{{ $propertyUnit->general_rent }}" class="form-control"
                                                placeholder="">
                                        </div>
                                        <div class="col-md-6 col-lg-6 col-xl-3 mb-25">
                                            <label
                                                class="label-text-title color-heading font-medium mb-2">{{ __('Security deposit') }}</label>
                                            <div class="input-group custom-input-group">
                                                <select name="propertyUnit[security_deposit_type][]"
                                                    class="form-control">
                                                    <option value="0"
                                                        {{ $propertyUnit->security_deposit_type == TYPE_FIXED ? 'selected' : '' }}>
                                                        {{ __('Fixed') }}</option>
                                                    <option value="1"
                                                        {{ $propertyUnit->security_deposit_type == TYPE_PERCENTAGE ? 'selected' : '' }}>
                                                        {{ __('Percentage') }}</option>
                                                </select>
                                                <input type="number" name="propertyUnit[security_deposit][]"
                                                    id="security_deposit{{ $propertyUnit->id }}"
                                                    value="{{ $propertyUnit->security_deposit }}" class="form-control"
                                                    placeholder="">
                                            </div>
                                        </div>
                                        <div class="col-md-6 col-lg-6 col-xl-3 mb-25">
                                            <label
                                                class="label-text-title color-heading font-medium mb-2">{{ __('Late fee') }}</label>
                                            <div class="input-group custom-input-group">
                                                <select name="propertyUnit[late_fee_type][]" class="form-control">
                                                    <option value="0"
                                                        {{ $propertyUnit->late_fee_type == TYPE_FIXED ? 'selected' : '' }}>
                                                        {{ __('Fixed') }}</option>
                                                    <option value="1"
                                                        {{ $propertyUnit->late_fee_type == TYPE_PERCENTAGE ? 'selected' : '' }}>
                                                        {{ __('Percentage') }}</option>
                                                </select>
                                                <input type="number" name="propertyUnit[late_fee][]"
                                                    id="late_fee{{ $propertyUnit->id }}"
                                                    value="{{ $propertyUnit->late_fee }}" class="form-control"
                                                    placeholder="">
                                            </div>
                                        </div>
                                        <div class="col-md-6 col-lg-6 col-xl-3 mb-25">
                                            <label
                                                class="label-text-title color-heading font-medium mb-2">{{ __('Incident receipt') }}</label>
                                            <input type="text" name="propertyUnit[incident_receipt][]"
                                                id="incident_receipt{{ $propertyUnit->id }}"
                                                value="{{ $propertyUnit->incident_receipt }}" class="form-control"
                                                placeholder="">
                                        </div>
                                    </div>

                                    <label
                                        class="label-text-title color-heading font-medium mb-2">{{ __('Rent Type') }}</label>
                                    <ul class="nav nav-tabs select-property-nav-tabs border-0 mb-20"
                                        id="unitTypeDateChangeTab{{ $propertyUnit->id }}" role="tablist">
                                        <li class="nav-item" role="presentation">
                                            <button
                                                class="p-0 me-4 mb-1 nav-link {{ $propertyUnit->rent_type == PROPERTY_UNIT_RENT_TYPE_MONTHLY ? 'active' : '' }} select_rent_type"
                                                data-rent_type="1" data-id="{{ $propertyUnit->id }}"
                                                id="monthly-unit-block-tab{{ $propertyUnit->id }}"
                                                data-bs-toggle="tab"
                                                data-bs-target="#monthly-unit-block-tab-pane{{ $propertyUnit->id }}"
                                                type="button" role="tab"
                                                aria-controls="monthly-unit-block-tab-pane{{ $propertyUnit->id }}"
                                                aria-selected="true">
                                                <span
                                                    class="select-property-nav-text d-flex align-items-center position-relative">
                                                    <span
                                                        class="select-property-nav-text-box me-2"></span>{{ __('Monthly') }}
                                                </span>
                                            </button>
                                        </li>
                                        <li class="nav-item" role="presentation">
                                            <button
                                                class="p-0 me-4 mb-1 nav-link {{ $propertyUnit->rent_type == PROPERTY_UNIT_RENT_TYPE_YEARLY ? 'active' : '' }} select_rent_type"
                                                data-rent_type="2" data-id="{{ $propertyUnit->id }}"
                                                id="yearly-unit-block-tab{{ $propertyUnit->id }}"
                                                data-bs-toggle="tab"
                                                data-bs-target="#yearly-unit-block-tab-pane{{ $propertyUnit->id }}"
                                                type="button" role="tab"
                                                aria-controls="yearly-unit-block-tab-pane{{ $propertyUnit->id }}"
                                                aria-selected="false">
                                                <span
                                                    class="select-property-nav-text d-flex align-items-center position-relative">
                                                    <span
                                                        class="select-property-nav-text-box me-2"></span>{{ __('Yearly') }}
                                                </span>
                                            </button>
                                        </li>
                                        <li class="nav-item" role="presentation">
                                            <button
                                                class="p-0 me-4 mb-1 nav-link {{ $propertyUnit->rent_type == PROPERTY_UNIT_RENT_TYPE_CUSTOM ? 'active' : '' }} select_rent_type"
                                                data-rent_type="3" data-id="{{ $propertyUnit->id }}"
                                                id="custom-unit-block-tab{{ $propertyUnit->id }}"
                                                data-bs-toggle="tab"
                                                data-bs-target="#custom-unit-block-tab-pane{{ $propertyUnit->id }}"
                                                type="button" role="tab"
                                                aria-controls="custom-unit-block-tab-pane{{ $propertyUnit->id }}"
                                                aria-selected="false">
                                                <span
                                                    class="select-property-nav-text d-flex align-items-center position-relative">
                                                    <span
                                                        class="select-property-nav-text-box me-2"></span>{{ __('Custom') }}
                                                </span>
                                            </button>
                                        </li>
                                    </ul>
                                    <input type="hidden" name="propertyUnit[rent_type][]"
                                        value="{{ $propertyUnit->rent_type }}"
                                        id="rent_type{{ $propertyUnit->id }}">
                                    <div class="tab-content"
                                        id="unitTypeDateChangeTabContent{{ $propertyUnit->id }}">
                                        <div class="tab-pane fade {{ $propertyUnit->rent_type == PROPERTY_UNIT_RENT_TYPE_MONTHLY ? 'show active' : '' }}"
                                            id="monthly-unit-block-tab-pane{{ $propertyUnit->id }}" role="tabpanel"
                                            aria-labelledby="monthly-unit-block-tab{{ $propertyUnit->id }}"
                                            tabindex="0">
                                            <div class="row">
                                                <div class="col-md-6 col-lg-6 col-xl-4 mb-25">
                                                    <label
                                                        class="label-text-title color-heading font-medium mb-2">{{ __('Due Day') }}</label>
                                                    <input type="number" step="any" min="0"
                                                        name="propertyUnit[monthly_due_day][]"
                                                        value="{{ $propertyUnit->monthly_due_day }}"
                                                        class="form-control" placeholder="Type day of month: 1 to 30">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="tab-pane fade {{ $propertyUnit->rent_type == PROPERTY_UNIT_RENT_TYPE_YEARLY ? 'show active' : '' }}"
                                            id="yearly-unit-block-tab-pane{{ $propertyUnit->id }}" role="tabpanel"
                                            aria-labelledby="yearly-unit-block-tab{{ $propertyUnit->id }}"
                                            tabindex="0">
                                            <div class="row">
                                                <div class="col-md-6 col-lg-6 col-xl-4 mb-25">
                                                    <label
                                                        class="label-text-title color-heading font-medium mb-2">{{ __('Due Month') }}</label>
                                                    <input type="number" step="any" min="0"
                                                        name="propertyUnit[yearly_due_day][]"
                                                        value="{{ $propertyUnit->yearly_due_day }}"
                                                        class="form-control"
                                                        placeholder="{{ __('Type month of year: 1 to 12') }}">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="tab-pane fade {{ $propertyUnit->rent_type == PROPERTY_UNIT_RENT_TYPE_CUSTOM ? 'show active' : '' }}"
                                            id="custom-unit-block-tab-pane{{ $propertyUnit->id }}" role="tabpanel"
                                            aria-labelledby="custom-unit-block-tab{{ $propertyUnit->id }}"
                                            tabindex="0">
                                            <div class="row">
                                                <div class="col-md-6 col-lg-6 col-xl-4 mb-25">
                                                    <label
                                                        class="label-text-title color-heading font-medium mb-2">{{ __('Lease Start date') }}</label>
                                                    <div class="custom-datepicker">
                                                        <div class="custom-datepicker-inner position-relative">
                                                            <input type="text"
                                                                name="propertyUnit[lease_start_date][]"
                                                                value="{{ $propertyUnit->lease_start_date }}"
                                                                class="datepicker form-control" autocomplete="off"
                                                                placeholder="dd-mm-yy">
                                                            <i class="ri-calendar-2-line"></i>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="col-md-6 col-lg-6 col-xl-4 mb-25">
                                                    <label
                                                        class="label-text-title color-heading font-medium mb-2">{{ __('Lease End date') }}</label>
                                                    <div class="custom-datepicker">
                                                        <div class="custom-datepicker-inner position-relative">
                                                            <input type="text"
                                                                name="propertyUnit[lease_end_date][]"
                                                                value="{{ $propertyUnit->lease_end_date }}"
                                                                class="datepicker form-control" autocomplete="off"
                                                                placeholder="dd-mm-yy">
                                                            <i class="ri-calendar-2-line"></i>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="col-md-6 col-lg-6 col-xl-4 mb-25">
                                                    <label
                                                        class="label-text-title color-heading font-medium mb-2">{{ __('Payment due on date') }}</label>
                                                    <div class="custom-datepicker">
                                                        <div class="custom-datepicker-inner position-relative">
                                                            <input type="text"
                                                                name="propertyUnit[lease_payment_due_date][]"
                                                                value="{{ $propertyUnit->lease_payment_due_date }}"
                                                                class="datepicker form-control" autocomplete="off"
                                                                placeholder="dd-mm-yy">
                                                            <i class="ri-calendar-2-line"></i>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="border-top pt-25 mt-10">
                                        <h6 class="mb-20">Public Website Option</h6>
                                        <p class="text-muted mb-20">
                                            Enable only the units you want on the website. Sort order and the default website option are selected automatically.
                                        </p>
                                        <div class="row">
                                            <div class="col-md-2 mb-25">
                                                <label class="label-text-title color-heading font-medium mb-2">Public Listing</label>
                                                <select name="propertyUnit[public_enabled][]" class="form-control js-public-unit-enabled">
                                                    <option value="0" {{ !$publicOption ? 'selected' : '' }}>Disabled</option>
                                                    <option value="1" {{ $publicOption ? 'selected' : '' }}>Enabled</option>
                                                </select>
                                            </div>
                                            <div class="col-md-2 mb-25">
                                                <label class="label-text-title color-heading font-medium mb-2">Rental Kind <span class="text-danger">*</span></label>
                                                <select name="propertyUnit[public_rental_kind][]" class="form-control js-public-unit-field">
                                                    @foreach (['whole_unit' => 'Whole Unit', 'private_room' => 'Private Room', 'shared_space' => 'Shared Space'] as $value => $label)
                                                        <option value="{{ $value }}"
                                                            {{ @$publicOption->rental_kind === $value ? 'selected' : ($value === 'whole_unit' ? 'selected' : '') }}>
                                                            {{ $label }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-md-2 mb-25">
                                                <label class="label-text-title color-heading font-medium mb-2">Monthly Rate <span class="text-danger">*</span></label>
                                                <input type="number" step="any" min="0" class="form-control js-public-unit-field"
                                                    name="propertyUnit[public_monthly_rate][]"
                                                    value="{{ @$publicOption->monthly_rate ?? $propertyUnit->general_rent }}">
                                            </div>
                                            <div class="col-md-2 mb-25">
                                                <label class="label-text-title color-heading font-medium mb-2">Nightly Rate <span class="text-danger">*</span></label>
                                                <input type="number" step="any" min="0" class="form-control js-public-unit-field"
                                                    name="propertyUnit[public_nightly_rate][]"
                                                    value="{{ @$publicOption->nightly_rate }}">
                                            </div>
                                            <div class="col-md-2 mb-25">
                                                <label class="label-text-title color-heading font-medium mb-2">Max Guests</label>
                                                <input type="number" min="1" class="form-control js-public-unit-field"
                                                    name="propertyUnit[public_max_guests][]"
                                                    value="{{ @$publicOption->max_guests ?? $propertyUnit->max_occupancy }}">
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-md-3 mb-25">
                                                <label class="label-text-title color-heading font-medium mb-2">Security Deposit Type</label>
                                                <select name="propertyUnit[public_security_deposit_type][]" class="form-control js-public-unit-field">
                                                    <option value="0"
                                                        {{ (int) (@$publicOption->security_deposit_type ?? $propertyUnit->security_deposit_type ?? TYPE_FIXED) === TYPE_FIXED ? 'selected' : '' }}>
                                                        {{ __('Fixed') }}
                                                    </option>
                                                    <option value="1"
                                                        {{ (int) (@$publicOption->security_deposit_type ?? $propertyUnit->security_deposit_type ?? TYPE_FIXED) === TYPE_PERCENTAGE ? 'selected' : '' }}>
                                                        {{ __('Percentage') }}
                                                    </option>
                                                </select>
                                            </div>
                                            <div class="col-md-3 mb-25">
                                                <label class="label-text-title color-heading font-medium mb-2">Security Deposit</label>
                                                <input type="number" step="any" min="0" class="form-control js-public-unit-field"
                                                    name="propertyUnit[public_security_deposit_value][]"
                                                    value="{{ @$publicOption->security_deposit_value ?? $propertyUnit->security_deposit ?? 0 }}">
                                            </div>
                                            <div class="col-md-6 mb-25 d-flex align-items-end">
                                                <small class="text-muted">
                                                    Set this to Enabled first if you want these website values to be saved for this unit. The public website will use this security deposit in the quote breakdown.
                                                </small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @php $c = 2; @endphp
                    @endforeach
                </div>
            </div>
            <!-- Unit Block Wrapper End -->
        </div>
    </div>

    <!-- Next/Previous Button Start -->
    <input type="button" name="previous" class="rentChargeBack action-button-previous theme-btn mt-25"
        value="Back">
    <button type="submit" class="action-button theme-btn mt-25">{{ __('Save & Go to Next') }}</button>
</form>
