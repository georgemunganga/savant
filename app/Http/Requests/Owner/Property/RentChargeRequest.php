<?php

namespace App\Http\Requests\Owner\Property;

use Illuminate\Foundation\Http\FormRequest;

class RentChargeRequest extends FormRequest
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
        $rules =  [
            'propertyUnit.public_enabled.*' => 'nullable|in:0,1',
            'propertyUnit.public_rental_kind.*' => 'nullable|in:whole_property,whole_unit,private_room,shared_space',
            'propertyUnit.public_monthly_rate.*' => 'nullable|numeric|min:0',
            'propertyUnit.public_nightly_rate.*' => 'nullable|numeric|min:0',
            'propertyUnit.public_security_deposit_type.*' => 'nullable|in:0,1',
            'propertyUnit.public_security_deposit_value.*' => 'nullable|numeric|min:0',
            'propertyUnit.public_max_guests.*' => 'nullable|integer|min:1',
            'propertyUnit.public_sort_order.*' => 'nullable|integer|min:0',
            'propertyUnit.public_is_default.*' => 'nullable|in:0,1',
        ];

        return $rules;
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $enabledFlags = $this->input('propertyUnit.public_enabled', []);
            $rentalKinds = $this->input('propertyUnit.public_rental_kind', []);
            $monthlyRates = $this->input('propertyUnit.public_monthly_rate', []);
            $nightlyRates = $this->input('propertyUnit.public_nightly_rate', []);

            foreach ($enabledFlags as $index => $enabled) {
                if ((string) $enabled !== '1') {
                    continue;
                }

                if (($rentalKinds[$index] ?? '') === '') {
                    $validator->errors()->add(
                        "propertyUnit.public_rental_kind.$index",
                        __('Rental kind is required for each enabled public unit option.')
                    );
                }

                if (($monthlyRates[$index] ?? '') === '') {
                    $validator->errors()->add(
                        "propertyUnit.public_monthly_rate.$index",
                        __('Monthly rate is required for each enabled public unit option.')
                    );
                }

                if (($nightlyRates[$index] ?? '') === '') {
                    $validator->errors()->add(
                        "propertyUnit.public_nightly_rate.$index",
                        __('Nightly rate is required for each enabled public unit option.')
                    );
                }
            }
        });
    }
}
