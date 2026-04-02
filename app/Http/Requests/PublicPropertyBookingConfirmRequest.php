<?php

namespace App\Http\Requests;

class PublicPropertyBookingConfirmRequest extends PublicPropertyAvailabilityRequest
{
    public function rules()
    {
        return array_merge(parent::rules(), [
            'payment_plan' => 'required|string|in:now,later',
        ]);
    }
}
