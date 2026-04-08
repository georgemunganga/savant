<?php

namespace App\Http\Requests;

class PublicPropertyBookingConfirmRequest extends PublicPropertyLeadDetailsRequest
{
    public function rules()
    {
        return array_merge(parent::rules(), [
            'phone' => 'nullable|string|max:30',
            'payment_plan' => 'required|string|in:now,later',
            'confirm_existing_booking' => 'nullable|boolean',
        ]);
    }
}
