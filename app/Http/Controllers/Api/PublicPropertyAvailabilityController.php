<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicPropertyAvailabilityRequest;
use App\Http\Requests\PublicPropertyBookingConfirmRequest;
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

    public function waitlist(int $propertyId, PublicPropertyAvailabilityRequest $request)
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
            $booking = $this->availabilityService->confirmBooking($propertyId, $request->validated());

            return $this->success([
                'booking' => $booking,
            ], 'Booking confirmed successfully');
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
