<?php

namespace App\Http\Requests;

use App\Traits\ResponseTrait;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class PublicPropertyAvailabilityRequest extends FormRequest
{
    use ResponseTrait;

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
        return [
            'option_id' => 'required|integer|min:1',
            'stay_mode' => 'required|in:days,weeks,months',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'guests' => 'required|integer|min:1',
            'full_name' => 'required|string|max:150',
            'email' => 'required|string|email|max:150',
            'phone' => 'required|string|max:30',
        ];
    }

    public function withValidator($validator)
    {
    }

    public function failedValidation(Validator $validator)
    {
        if ($this->header('accept') === 'application/json') {
            $error = $validator->fails() ? $validator->errors()->first() : VALIDATION_ERRORS;
            return $this->validationErrorApi($validator, $error);
        }

        throw (new ValidationException($validator))
            ->errorBag($this->errorBag)
            ->redirectTo($this->getRedirectUrl());
    }
}
