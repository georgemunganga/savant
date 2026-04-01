<form class="ajax" action="{{ route('owner.property.property-information.store') }}" method="post"
    data-handler="stepChange">
    @csrf
    @php
        $wholePublicOption = @$property->wholePublicOption;
        $publicSections = array_filter(explode(',', (string) @$property->public_home_sections));
    @endphp
    <input type="text" name="property_id" class="d-none" value="{{ @$property->id }}">
    <input type="text" name="property_type" class="d-none" id="property_type"
        value="{{ @$property->property_type ?? 1 }}">
    <div class="form-card add-property-box bg-off-white theme-border radius-4 p-20">
        <div class="add-property-title border-bottom pb-25 mb-25">
            <h4>{{ __('Property Information') }}</h4>
        </div>
        <div class="select-property-box bg-white theme-border radius-4 p-20 mb-25">
            <h6 class="mb-15">{{ __('Select Property') }}</h6>
            <ul class="nav nav-tabs select-property-nav-tabs border-0" id="myTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button
                        class="p-0 me-4 mb-1 nav-link {{ @$property->property_type ? ($property->property_type == 1 ? 'active' : '') : 'active' }} select_property_type"
                        data-property_type="1" id="own-property-tab" data-bs-toggle="tab"
                        data-bs-target="#own-property-tab-pane" type="button" role="tab"
                        aria-controls="own-property-tab-pane" aria-selected="true">
                        <span class="select-property-nav-text d-flex align-items-center position-relative">
                            <span class="select-property-nav-text-box me-2"></span>{{ __('Own Property') }}
                        </span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button
                        class="p-0 me-4 mb-1 nav-link {{ @$property->property_type ? ($property->property_type == 2 ? 'active' : '') : '' }} select_property_type"
                        data-property_type="2" id="lease-property-tab" data-bs-toggle="tab"
                        data-bs-target="#lease-property-tab-pane" type="button" role="tab"
                        aria-controls="lease-property-tab-pane" aria-selected="false">
                        <span class="select-property-nav-text d-flex align-items-center position-relative">
                            <span class="select-property-nav-text-box me-2"></span>{{ __('Lease Property') }}
                        </span>
                    </button>
                </li>
            </ul>
        </div>

        <div class="add-property-inner-box bg-white theme-border radius-4 p-20">
            <div class="tab-content" id="myTabContent">
                <div class="tab-pane fade {{ @$property->property_type ? ($property->property_type == 1 ? 'show active' : '') : 'show active' }}"
                    id="own-property-tab-pane" role="tabpanel" aria-labelledby="own-property-tab" tabindex="0">
                    <div class="row">
                        <div class="col-md-6 mb-25">
                            <label
                                class="label-text-title color-heading font-medium mb-2">{{ __('Property Name') }}</label>
                            <input type="text" class="form-control" name="own_property_name"
                                placeholder="{{ __('Property Name') }}"
                                value="{{ @$property->property_type ? ($property->property_type == 1 ? $property->name : '') : '' }}">
                        </div>
                        <div class="col-md-6 mb-25">
                            <label
                                class="label-text-title color-heading font-medium mb-2">{{ __('Number of Units') }}</label>
                            <input type="number" min="1" class="form-control" name="own_number_of_unit"
                                value="{{ @$property->property_type ? ($property->property_type == 1 ? $property->number_of_unit : '') : '' }}"
                                placeholder="{{ __('Number of Units') }}">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12 mb-25">
                            <label
                                class="label-text-title color-heading font-medium mb-2">{{ __('Description') }}</label>
                            <textarea class="form-control" name="own_description" placeholder="{{ __('Description') }}">{{ @$property->property_type ? ($property->property_type == 1 ? $property->description : '') : '' }}</textarea>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade {{ @$property->property_type ? ($property->property_type == 2 ? 'show active' : '') : '' }}"
                    id="lease-property-tab-pane" role="tabpanel" aria-labelledby="lease-property-tab" tabindex="0">
                    <div class="row">
                        <div class="col-md-4 mb-25">
                            <label
                                class="label-text-title color-heading font-medium mb-2">{{ __('Property Name') }}</label>
                            <input type="text" class="form-control" name="lease_property_name"
                                placeholder="{{ __('Property Name') }}"
                                value="{{ @$property->property_type ? ($property->property_type == 2 ? @$property->name : '') : '' }}">
                        </div>
                        <div class="col-md-4 mb-25">
                            <label
                                class="label-text-title color-heading font-medium mb-2">{{ __('Number of Units') }}</label>
                            <input type="number" min="1" class="form-control" name="lease_number_of_unit"
                                placeholder="{{ __('Number of Units') }}"
                                value="{{ @$property->property_type ? ($property->property_type == 2 ? $property->number_of_unit : '') : '' }}">
                        </div>
                        <div class="col-md-4 mb-25">
                            <label
                                class="label-text-title color-heading font-medium mb-2">{{ __('Lease Amount') }}</label>
                            <input type="number" min="0" step="any" class="form-control"
                                name="lease_amount" value="{{ @$property->propertyDetail->lease_amount }}"
                                placeholder="{{ __('Lease Amount') }}">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-25">
                            <label
                                class="label-text-title color-heading font-medium mb-2">{{ __('Lease Start date') }}</label>
                            <div class="custom-datepicker">
                                <div class="custom-datepicker-inner position-relative">
                                    <input type="text" class="datepicker form-control" name="lease_start_date"
                                        value="{{ @$property->propertyDetail->lease_start_date }}" autocomplete="off"
                                        placeholder="dd-mm-yy">
                                    <i class="ri-calendar-2-line"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-25">
                            <label
                                class="label-text-title color-heading font-medium mb-2">{{ __('Lease End date') }}</label>
                            <div class="custom-datepicker">
                                <div class="custom-datepicker-inner position-relative">
                                    <input type="text" class="datepicker form-control" name="lease_end_date"
                                        value="{{ @$property->propertyDetail->lease_end_date }}" autocomplete="off"
                                        placeholder="dd-mm-yy">
                                    <i class="ri-calendar-2-line"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12 mb-25">
                            <label
                                class="label-text-title color-heading font-medium mb-2">{{ __('Description') }}</label>
                            <textarea class="form-control" name="lease_description" placeholder="{{ __('Description') }}">{{ @$property->property_type ? ($property->property_type == 2 ? $property->description : '') : '' }}</textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="border-top pt-25 mt-10">
                <h5 class="mb-20">{{ __('Public Website') }}</h5>
                <div class="row">
                    <div class="col-md-4 mb-25">
                        <input type="hidden" name="is_public" value="0">
                        <div class="form-group custom-checkbox">
                            <input type="checkbox" id="is_public" name="is_public" value="1"
                                {{ (int) @$property->is_public === 1 ? 'checked' : '' }}>
                            <label class="fw-normal" for="is_public">{{ __('Publish on public website') }}</label>
                        </div>
                        <small class="text-muted d-block mt-2">
                            The website link, display order, and default public option are generated automatically.
                        </small>
                    </div>
                    <div class="col-md-4 mb-25">
                        <label class="label-text-title color-heading font-medium mb-2">Public Category</label>
                        <select class="form-control" name="public_category">
                            <option value="">--Select Type--</option>
                            <option value="apartment" {{ @$property->public_category === 'apartment' ? 'selected' : '' }}>
                                Apartments
                            </option>
                            <option value="boarding" {{ @$property->public_category === 'boarding' ? 'selected' : '' }}>
                                Boarding
                            </option>
                        </select>
                        <small class="text-muted d-block mt-2">
                            Choose how this property should appear on the website.
                        </small>
                    </div>
                    <div class="col-md-4 mb-25">
                        <label class="label-text-title color-heading font-medium mb-2">Public Link</label>
                        <input type="text" class="form-control"
                            value="{{ @$property->public_slug ?: 'Generated automatically after save' }}"
                            readonly>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-12 mb-25">
                        <label class="label-text-title color-heading font-medium mb-2">Public Summary</label>
                        <textarea class="form-control" name="public_summary"
                            placeholder="Short public summary">{{ @$property->public_summary }}</textarea>
                        <small class="text-muted d-block mt-2">
                            Optional. Leave blank and the property description will be used on the website.
                        </small>
                    </div>
                </div>

                <div class="border-top pt-25 mt-10">
                    <div class="row">
                        <div class="col-md-12 mb-20">
                            <input type="hidden" name="enable_whole_property_option" value="0">
                            <div class="form-group custom-checkbox">
                                <input type="checkbox" id="enable_whole_property_option"
                                    name="enable_whole_property_option" value="1"
                                    {{ $wholePublicOption ? 'checked' : '' }}>
                                <label class="fw-normal" for="enable_whole_property_option">
                                    Enable property-level public option
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="row js-whole-property-option-fields">
                        <div class="col-md-3 mb-25">
                            <label class="label-text-title color-heading font-medium mb-2">Rental Kind <span class="text-danger">*</span></label>
                            <select class="form-control js-whole-property-option-field" name="whole_property_option[rental_kind]">
                                @foreach (['whole_property' => 'Whole Property', 'whole_unit' => 'Whole Unit', 'private_room' => 'Private Room', 'shared_space' => 'Shared Space'] as $value => $label)
                                    <option value="{{ $value }}"
                                        {{ @$wholePublicOption->rental_kind === $value ? 'selected' : ($value === 'whole_property' ? 'selected' : '') }}>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2 mb-25">
                            <label class="label-text-title color-heading font-medium mb-2">Monthly Rate <span class="text-danger">*</span></label>
                            <input type="number" min="0" step="any" class="form-control js-whole-property-option-field"
                                name="whole_property_option[monthly_rate]"
                                value="{{ @$wholePublicOption->monthly_rate }}">
                        </div>
                        <div class="col-md-2 mb-25">
                            <label class="label-text-title color-heading font-medium mb-2">Nightly Rate <span class="text-danger">*</span></label>
                            <input type="number" min="0" step="any" class="form-control js-whole-property-option-field"
                                name="whole_property_option[nightly_rate]"
                                value="{{ @$wholePublicOption->nightly_rate }}">
                        </div>
                        <div class="col-md-2 mb-25">
                            <label class="label-text-title color-heading font-medium mb-2">Max Guests</label>
                            <input type="number" min="1" class="form-control js-whole-property-option-field"
                                name="whole_property_option[max_guests]"
                                value="{{ @$wholePublicOption->max_guests }}">
                        </div>
                        <div class="col-md-5 mb-25 d-flex align-items-end">
                            <small class="text-muted">
                                Turn this on first if you want these values to be saved and shown on the website.
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Next/Previous Button Start -->
    <button type="submit" class="action-button theme-btn mt-25">{{ __('Save & Go to Next') }}</button>
</form>
