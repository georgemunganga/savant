<?php

namespace App\Http\Requests;

class PublicPropertyLeadDetailsRequest extends PublicPropertyAvailabilityRequest
{
    public function rules()
    {
        return array_merge(parent::rules(), [
            'date_of_birth' => 'required|date|before_or_equal:today',
            'nationality_country_id' => 'required|string|max:50',
            'id_type' => 'required|string|in:national_id,passport',
            'id_number' => 'required|string|max:100',
            'occupation' => 'required|string|max:150',
            'is_student' => 'required|boolean',
            'year_of_study' => 'nullable|string|max:100',
        ]);
    }

    public function withValidator($validator)
    {
        parent::withValidator($validator);

        $validator->after(function ($validator) {
            $countryId = (string) $this->input('nationality_country_id', '');

            if ($countryId === '' || !getCountryById($countryId)) {
                $validator->errors()->add(
                    'nationality_country_id',
                    __('Choose a valid nationality.')
                );
            }

            if ($this->boolean('is_student') && blank($this->input('year_of_study'))) {
                $validator->errors()->add(
                    'year_of_study',
                    __('Year of study is required when the guest is a student.')
                );
            }
        });
    }
}
