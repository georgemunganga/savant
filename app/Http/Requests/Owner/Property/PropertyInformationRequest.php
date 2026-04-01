<?php

namespace App\Http\Requests\Owner\Property;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PropertyInformationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules()
    {
        $propertyId = $this->input('property_id');

        $rules =  [
            'property_type' => 'required|in:1,2',
            'own_property_name' => 'exclude_unless:property_type,1|required',
            'own_number_of_unit' => 'exclude_unless:property_type,1|required|numeric',
            'own_description' => 'exclude_unless:property_type,1|required',
            'lease_property_name' => 'exclude_unless:property_type,2|required',
            'lease_number_of_unit' => 'exclude_unless:property_type,2|required|numeric',
            'lease_amount' => 'exclude_unless:property_type,2|required|numeric',
            'lease_start_date' => 'exclude_unless:property_type,2|required',
            'lease_end_date' => 'exclude_unless:property_type,2|required',
            'lease_description' => 'exclude_unless:property_type,2|required',
            'is_public' => 'nullable|in:0,1',
            'public_slug' => [
                'nullable',
                'string',
                'max:191',
                Rule::unique('properties', 'public_slug')->ignore($propertyId),
            ],
            'public_category' => 'exclude_unless:is_public,1|required|in:apartment,boarding',
            'public_summary' => 'nullable|string',
            'public_home_sections' => 'nullable|array',
            'public_home_sections.*' => 'nullable|in:featured,popular,budget,luxury',
            'public_sort_order' => 'nullable|integer|min:0',
            'enable_whole_property_option' => 'nullable|in:0,1',
            'whole_property_option.rental_kind' => 'exclude_unless:is_public,1|required_if:enable_whole_property_option,1|in:whole_property,whole_unit,private_room,shared_space',
            'whole_property_option.monthly_rate' => 'exclude_unless:is_public,1|required_if:enable_whole_property_option,1|numeric|min:0',
            'whole_property_option.nightly_rate' => 'exclude_unless:is_public,1|required_if:enable_whole_property_option,1|numeric|min:0',
            'whole_property_option.max_guests' => 'nullable|integer|min:1',
            'whole_property_option.sort_order' => 'nullable|integer|min:0',
            'whole_property_option.is_default' => 'nullable|in:0,1',
        ];

        return $rules;
    }

    public function messages()
    {
        return [
            'public_category.required' => __('Public category is required when publishing a property on the website.'),
            'whole_property_option.rental_kind.required_if' => __('Rental kind is required when the property-level website option is enabled.'),
            'whole_property_option.monthly_rate.required_if' => __('Monthly rate is required when the property-level website option is enabled.'),
            'whole_property_option.nightly_rate.required_if' => __('Nightly rate is required when the property-level website option is enabled.'),
        ];
    }
}
