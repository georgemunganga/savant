<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicPropertyAvailabilityRequest;
use App\Http\Requests\PublicPropertyBookingConfirmRequest;
use App\Http\Requests\PublicPropertyLeadDetailsRequest;
use App\Services\PublicPropertyAvailabilityService;
use App\Traits\ResponseTrait;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PublicPropertyAvailabilityController extends Controller
{
    use ResponseTrait;

    public function __construct(
        private readonly PublicPropertyAvailabilityService $availabilityService
    ) {
    }

    public function check(int $propertyId, PublicPropertyAvailabilityRequest $request)
    {
        try {
            $data = $this->availabilityService->checkAvailability($propertyId, $request->validated());

            return $this->success($data, 'Availability checked successfully');
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'data' => null,
                'message' => 'Property not found',
            ], 404);
        } catch (Exception $e) {
            return $this->error([], $e->getMessage());
        }
    }

    public function waitlist(int $propertyId, PublicPropertyLeadDetailsRequest $request)
    {
        try {
            $waitlist = $this->availabilityService->createWaitlist($propertyId, $request->validated());

            return $this->success([
                'waitlist' => [
                    'id' => $waitlist->id,
                    'property_id' => $waitlist->property_id,
                    'option_id' => $waitlist->option_id,
                    'status' => $waitlist->status,
                    'stay_mode' => $waitlist->stay_mode,
                    'start_date' => $waitlist->start_date?->toDateString(),
                    'end_date' => $waitlist->end_date?->toDateString(),
                    'guests' => $waitlist->guests,
                    'full_name' => $waitlist->full_name,
                    'email' => $waitlist->email,
                    'phone' => $waitlist->phone,
                    'date_of_birth' => $waitlist->date_of_birth?->toDateString(),
                    'nationality_country_id' => $waitlist->nationality_country_id,
                    'id_type' => $waitlist->id_type,
                    'id_number' => $waitlist->id_number,
                    'occupation' => $waitlist->occupation,
                    'is_student' => (bool) $waitlist->is_student,
                    'year_of_study' => $waitlist->year_of_study,
                ],
            ], 'Joined waiting list successfully');
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'data' => null,
                'message' => 'Property not found',
            ], 404);
        } catch (Exception $e) {
            return $this->error([], $e->getMessage());
        }
    }

    public function confirm(int $propertyId, PublicPropertyBookingConfirmRequest $request)
    {
        try {
            $result = $this->availabilityService->confirmBooking($propertyId, $request->validated());

            return $this->success(
                $result,
                !empty($result['requires_fee_clearance'])
                    ? 'Pending fee clearance required'
                    : (!empty($result['requires_confirmation'])
                        ? 'Existing booking confirmation required'
                        : 'Booking confirmed successfully')
            );
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'data' => null,
                'message' => 'Property not found',
            ], 404);
        } catch (Exception $e) {
            return $this->error([], $e->getMessage());
        }
    }
}
