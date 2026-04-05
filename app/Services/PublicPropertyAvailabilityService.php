<?php

namespace App\Services;

use App\Models\Property;
use App\Models\PropertyUnit;
use App\Models\PublicPropertyBooking;
use App\Models\PublicPropertyOption;
use App\Models\PublicPropertyWaitlist;
use App\Models\Tenant;
use App\Models\User;
use App\Models\TenantUnitAssignment;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PublicPropertyAvailabilityService
{
    public function __construct(
        private readonly UnitHistoryService $unitHistoryService = new UnitHistoryService(),
        private readonly TenantAccessService $tenantAccessService = new TenantAccessService()
    ) {
    }

    public function checkAvailability(int $propertyId, array $payload): array
    {
        $property = $this->getPublicProperty($propertyId);
        $option = $this->getPublicOption($property->id, (int) $payload['option_id']);
        $startDate = Carbon::parse($payload['start_date'])->toDateString();
        $endDate = Carbon::parse($payload['end_date'])->toDateString();

        return $this->calculateOptionAvailability(
            $property,
            $option,
            $startDate,
            $endDate,
            $payload['stay_mode']
        );
    }

    public function createWaitlist(int $propertyId, array $payload): PublicPropertyWaitlist
    {
        $property = $this->getPublicProperty($propertyId);
        $option = $this->getPublicOption($property->id, (int) $payload['option_id']);

        return PublicPropertyWaitlist::query()->create([
            'property_id' => $property->id,
            'option_id' => $option->id,
            'stay_mode' => $payload['stay_mode'],
            'start_date' => Carbon::parse($payload['start_date'])->toDateString(),
            'end_date' => Carbon::parse($payload['end_date'])->toDateString(),
            'guests' => (int) $payload['guests'],
            'full_name' => $payload['full_name'],
            'email' => $payload['email'],
            'phone' => $payload['phone'],
            'status' => 'pending',
        ]);
    }

    public function confirmBooking(int $propertyId, array $payload): array
    {
        DB::beginTransaction();

        try {
            $property = $this->getPublicProperty($propertyId);
            $option = $this->getPublicOption($property->id, (int) $payload['option_id']);
            $startDate = Carbon::parse($payload['start_date'])->toDateString();
            $endDate = Carbon::parse($payload['end_date'])->toDateString();
            $availability = $this->calculateOptionAvailability(
                $property,
                $option,
                $startDate,
                $endDate,
                $payload['stay_mode']
            );

            if (!$availability['available']) {
                throw new Exception(__('This stay is no longer available. Please refresh availability and try again.'));
            }

            [$user, $tenant, $accountCreated] = $this->createOrReuseTenantAccount($property, $payload);
            $setupEmailSent = $accountCreated || is_null($user->email_verified_at);
            if ($setupEmailSent) {
                $this->tenantAccessService->sendAccountSetupEmail($user, $property->owner_user_id);
            }

            $assignmentChanged = $this->applyBookingAssignment(
                $tenant,
                $property,
                $option,
                $payload['stay_mode'],
                $startDate,
                $endDate
            );

            if ($assignmentChanged && !is_null($tenant->property_id) && !is_null($tenant->unit_id)) {
                $assignedProperty = Property::query()->find($tenant->property_id);
                $assignedUnit = PropertyUnit::query()->find($tenant->unit_id);
                if ($assignedProperty && $assignedUnit) {
                    $this->tenantAccessService->sendAssignmentEmail(
                        $tenant->fresh(['user', 'property', 'unit']),
                        $assignedProperty,
                        $assignedUnit,
                        $property->owner_user_id
                    );
                }
            }

            $bookingRecord = PublicPropertyBooking::query()->create([
                'owner_user_id' => $property->owner_user_id,
                'property_id' => $property->id,
                'option_id' => $option->id,
                'property_unit_id' => $tenant->unit_id ?: $option->property_unit_id,
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'stay_mode' => $payload['stay_mode'],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'guests' => (int) $payload['guests'],
                'full_name' => $payload['full_name'],
                'email' => $user->email,
                'phone' => $this->normalizePhone($payload['phone'] ?? null),
                'payment_plan' => $payload['payment_plan'],
                'status' => PublicPropertyBooking::STATUS_CONFIRMED,
                'source' => 'website',
                'account_created' => $accountCreated,
                'setup_email_sent' => $setupEmailSent,
                'has_assignment' => !is_null($tenant->property_id) && !is_null($tenant->unit_id),
                'assignment_created' => $assignmentChanged,
                'confirmed_at' => now(),
            ]);

            DB::commit();

            return [
                'id' => $bookingRecord->id,
                'property_id' => $property->id,
                'option_id' => $option->id,
                'tenant_id' => $tenant->id,
                'email' => $user->email,
                'stay_mode' => $payload['stay_mode'],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'guests' => (int) $payload['guests'],
                'payment_plan' => $payload['payment_plan'],
                'account_created' => $accountCreated,
                'setup_email_sent' => $setupEmailSent,
                'has_assignment' => !is_null($tenant->property_id) && !is_null($tenant->unit_id),
                'assignment_created' => $assignmentChanged,
            ];
        } catch (\Throwable $throwable) {
            DB::rollBack();
            throw $throwable;
        }
    }

    private function getPublicProperty(int $propertyId): Property
    {
        $property = Property::query()
            ->where('id', $propertyId)
            ->whereNull('deleted_at')
            ->where('is_public', true)
            ->whereNotNull('public_slug')
            ->whereNotNull('public_category')
            ->first();

        if (!$property) {
            throw new ModelNotFoundException('Property not found');
        }

        return $property;
    }

    private function getPublicOption(int $propertyId, int $optionId): PublicPropertyOption
    {
        $option = PublicPropertyOption::query()
            ->where('id', $optionId)
            ->where('property_id', $propertyId)
            ->where('status', ACTIVE)
            ->with('propertyUnit')
            ->first();

        if (!$option) {
            throw new ModelNotFoundException('Option not found');
        }

        return $option;
    }

    private function createOrReuseTenantAccount(Property $property, array $payload): array
    {
        $email = Str::lower(trim((string) $payload['email']));
        $fullName = trim((string) $payload['full_name']);
        $phone = $this->normalizePhone($payload['phone'] ?? null);
        $nameParts = preg_split('/\s+/', $fullName, 2) ?: [];
        $firstName = $nameParts[0] ?? 'Guest';
        $lastName = $nameParts[1] ?? 'Tenant';
        $existingUserByEmail = User::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->first();
        $existingUserByPhone = $phone
            ? User::query()->where('contact_number', $phone)->first()
            : null;

        if (
            $existingUserByEmail &&
            $existingUserByPhone &&
            (int) $existingUserByEmail->id !== (int) $existingUserByPhone->id
        ) {
            throw new Exception(__('These guest details are already linked to different accounts. Please contact Savant support.'));
        }

        $existingUser = $existingUserByEmail ?? $existingUserByPhone;

        if ($existingUser && (int) $existingUser->role !== USER_ROLE_TENANT) {
            throw new Exception(__('This email is already linked to another account. Please contact Savant support.'));
        }

        if (
            !$existingUserByEmail &&
            $existingUserByPhone &&
            !blank($existingUserByPhone->email) &&
            Str::lower(trim((string) $existingUserByPhone->email)) !== $email
        ) {
            throw new Exception(__('This phone number is already linked to another account. Please sign in or contact Savant support.'));
        }

        if (
            $existingUser &&
            !is_null($existingUser->owner_user_id) &&
            !is_null($property->owner_user_id) &&
            (int) $existingUser->owner_user_id !== (int) $property->owner_user_id
        ) {
            throw new Exception(__('This email is already linked to another Savant account. Please contact support.'));
        }

        $accountCreated = false;
        $user = $existingUser;
        if (!$user) {
            $user = User::query()->create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'contact_number' => $phone,
                'password' => Hash::make(Str::random(40)),
                'status' => USER_STATUS_ACTIVE,
                'role' => USER_ROLE_TENANT,
                'owner_user_id' => $property->owner_user_id,
            ]);
            $accountCreated = true;
        } else {
            if (blank($user->contact_number) && $phone) {
                $user->contact_number = $phone;
            }
            if (blank($user->first_name)) {
                $user->first_name = $firstName;
            }
            if (blank($user->last_name)) {
                $user->last_name = $lastName;
            }
            if (blank($user->email)) {
                $user->email = $email;
            }
            if ((int) $user->status === USER_STATUS_UNVERIFIED) {
                $user->status = USER_STATUS_ACTIVE;
            }
            if (is_null($user->owner_user_id)) {
                $user->owner_user_id = $property->owner_user_id;
            }
            $user->save();
        }

        $tenant = $user->tenant;
        if (!$tenant) {
            $tenant = new Tenant();
            $tenant->user_id = $user->id;
            $tenant->owner_user_id = $property->owner_user_id;
            $tenant->job = __('Guest');
            $tenant->family_member = max((int) $payload['guests'], 1);
            $tenant->status = TENANT_STATUS_ACTIVE;
            $tenant->rent_type = $payload['stay_mode'] === 'months' ? RENT_TYPE_MONTHLY : RENT_TYPE_CUSTOM;
            $tenant->general_rent = 0;
            $tenant->security_deposit = 0;
            $tenant->late_fee = 0;
            $tenant->incident_receipt = 0;
            $tenant->save();
        } else {
            $tenant->owner_user_id = $tenant->owner_user_id ?: $property->owner_user_id;
            $tenant->family_member = max((int) $payload['guests'], (int) ($tenant->family_member ?? 1), 1);
            if ((int) $tenant->status === TENANT_STATUS_INACTIVE || (int) $tenant->status === TENANT_STATUS_CLOSE) {
                $tenant->status = TENANT_STATUS_ACTIVE;
            }
            $tenant->save();
        }

        return [$user->fresh(), $tenant->fresh(), $accountCreated];
    }

    private function normalizePhone($value): ?string
    {
        $phone = trim((string) $value);

        return $phone !== '' ? $phone : null;
    }

    private function applyBookingAssignment(
        Tenant $tenant,
        Property $property,
        PublicPropertyOption $option,
        string $stayMode,
        string $startDate,
        string $endDate
    ): bool {
        $original = [
            'property_id' => $tenant->property_id,
            'unit_id' => $tenant->unit_id,
            'lease_start_date' => $tenant->lease_start_date,
            'lease_end_date' => $tenant->lease_end_date,
        ];

        $tenant->owner_user_id = $tenant->owner_user_id ?: $property->owner_user_id;
        $tenant->status = TENANT_STATUS_ACTIVE;
        $tenant->rent_type = $stayMode === 'months' ? RENT_TYPE_MONTHLY : RENT_TYPE_CUSTOM;
        $tenant->general_rent = (float) ($stayMode === 'months'
            ? ($option->monthly_rate ?? 0)
            : ($option->nightly_rate ?? 0));
        $tenant->due_date = (int) Carbon::parse($startDate)->day;
        $tenant->lease_start_date = $startDate;
        $tenant->lease_end_date = $endDate;

        if ($option->property_unit_id) {
            $this->unitHistoryService->syncPrimaryAssignment(
                $tenant,
                (int) $property->id,
                (int) $option->property_unit_id,
                null
            );

            $tenant->property_id = $property->id;
            $tenant->unit_id = $option->property_unit_id;
        } else {
            if (!is_null($tenant->unit_id) && (int) $tenant->property_id !== (int) $property->id) {
                throw new Exception(__('This stay needs manual assignment. Please contact Savant support.'));
            }

            $tenant->property_id = $tenant->property_id ?: $property->id;
            $tenant->unit_id = null;
        }

        $tenant->save();

        return $original['property_id'] != $tenant->property_id
            || $original['unit_id'] != $tenant->unit_id
            || $original['lease_start_date'] != $tenant->lease_start_date
            || $original['lease_end_date'] != $tenant->lease_end_date;
    }

    private function calculateOptionAvailability(
        Property $property,
        PublicPropertyOption $option,
        string $startDate,
        string $endDate,
        string $stayMode
    ): array {
        if ($option->property_unit_id) {
            return $this->calculateUnitAvailability($property, $option, $startDate, $endDate, $stayMode);
        }

        return $this->calculateWholePropertyAvailability($property, $option, $startDate, $endDate, $stayMode);
    }

    private function calculateUnitAvailability(
        Property $property,
        PublicPropertyOption $option,
        string $startDate,
        string $endDate,
        string $stayMode
    ): array {
        $unit = PropertyUnit::query()
            ->where('property_id', $property->id)
            ->where('id', $option->property_unit_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$unit || is_null($unit->max_occupancy) || (int) $unit->max_occupancy <= 0) {
            return $this->unknownAvailabilityPayload($property->id, $option->id, $startDate, $endDate, $stayMode);
        }
        if (in_array(($unit->manual_availability_status ?? null), [
            PropertyUnit::MANUAL_AVAILABILITY_ON_HOLD,
            PropertyUnit::MANUAL_AVAILABILITY_OFF_MARKET,
        ], true)) {
            return $this->availabilityPayload(
                $property->id,
                $option->id,
                false,
                $unit->manual_availability_status,
                (int) $unit->max_occupancy,
                0,
                0,
                $startDate,
                $endDate,
                $stayMode
            );
        }

        $occupiedCounts = $this->getOccupiedUnitCounts($property->id, $startDate, $endDate);
        $occupied = (int) ($occupiedCounts[$unit->id] ?? 0);

        if (in_array($option->rental_kind, ['whole_property', 'whole_unit'], true)) {
            $available = $occupied === 0;

            return $this->availabilityPayload(
                $property->id,
                $option->id,
                $available,
                $available ? 'available' : 'full',
                1,
                $available ? 1 : 0,
                0,
                $startDate,
                $endDate,
                $stayMode
            );
        }

        $availableSlots = max((int) $unit->max_occupancy - $occupied, 0);

        return $this->availabilityPayload(
            $property->id,
            $option->id,
            $availableSlots > 0,
            $availableSlots > 0 ? 'available' : 'full',
            (int) $unit->max_occupancy,
            $availableSlots,
            0,
            $startDate,
            $endDate,
            $stayMode
        );
    }

    private function calculateWholePropertyAvailability(
        Property $property,
        PublicPropertyOption $option,
        string $startDate,
        string $endDate,
        string $stayMode
    ): array {
        $unitColumns = ['id', 'max_occupancy'];
        if (Schema::hasColumn('property_units', 'manual_availability_status')) {
            $unitColumns[] = 'manual_availability_status';
        }

        $units = PropertyUnit::query()
            ->where('property_id', $property->id)
            ->whereNull('deleted_at')
            ->get($unitColumns);

        if ($units->isEmpty()) {
            return $this->unknownAvailabilityPayload($property->id, $option->id, $startDate, $endDate, $stayMode);
        }

        $blockedStatus = $units
            ->pluck('manual_availability_status')
            ->first(fn ($status) => in_array($status, [
                PropertyUnit::MANUAL_AVAILABILITY_ON_HOLD,
                PropertyUnit::MANUAL_AVAILABILITY_OFF_MARKET,
            ], true));
        if ($blockedStatus) {
            return $this->availabilityPayload(
                $property->id,
                $option->id,
                false,
                $blockedStatus,
                1,
                0,
                0,
                $startDate,
                $endDate,
                $stayMode
            );
        }

        $unknownUnits = $units->filter(
            fn (PropertyUnit $unit) => is_null($unit->max_occupancy) || (int) $unit->max_occupancy <= 0
        );

        if ($unknownUnits->isNotEmpty()) {
            return $this->unknownAvailabilityPayload($property->id, $option->id, $startDate, $endDate, $stayMode);
        }

        $occupiedCounts = $this->getOccupiedUnitCounts($property->id, $startDate, $endDate);
        $available = $units->every(fn (PropertyUnit $unit) => (int) ($occupiedCounts[$unit->id] ?? 0) === 0);

        return $this->availabilityPayload(
            $property->id,
            $option->id,
            $available,
            $available ? 'available' : 'full',
            1,
            $available ? 1 : 0,
            0,
            $startDate,
            $endDate,
            $stayMode
        );
    }

    private function unknownAvailabilityPayload(
        int $propertyId,
        int $optionId,
        string $startDate,
        string $endDate,
        string $stayMode
    ): array {
        return $this->availabilityPayload(
            $propertyId,
            $optionId,
            false,
            'unknown',
            1,
            0,
            1,
            $startDate,
            $endDate,
            $stayMode
        );
    }

    private function availabilityPayload(
        int $propertyId,
        int $optionId,
        bool $available,
        string $status,
        int $unitsTotal,
        int $unitsAvailable,
        int $unitsUnknown,
        string $startDate,
        string $endDate,
        string $stayMode
    ): array {
        return [
            'property_id' => $propertyId,
            'option_id' => $optionId,
            'available' => $available,
            'availability_status' => $status,
            'units_total' => $unitsTotal,
            'units_available' => $unitsAvailable,
            'units_unknown' => $unitsUnknown,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'stay_mode' => $stayMode,
        ];
    }

    private function getOccupiedUnitCounts(int $propertyId, string $startDate, string $endDate): Collection
    {
        return TenantUnitAssignment::query()
            ->join('tenants', 'tenant_unit_assignments.tenant_id', '=', 'tenants.id')
            ->where('tenant_unit_assignments.property_id', $propertyId)
            ->whereNull('tenants.deleted_at')
            ->where('tenants.status', TENANT_STATUS_ACTIVE)
            ->when(Schema::hasColumn('tenant_unit_assignments', 'is_current'), function ($query) {
                $query->where('tenant_unit_assignments.is_current', true);
            })
            ->where(function ($query) use ($endDate) {
                $query->whereNull('tenants.lease_start_date')
                    ->orWhereDate('tenants.lease_start_date', '<=', $endDate);
            })
            ->where(function ($query) use ($startDate) {
                $query->whereNull('tenants.lease_end_date')
                    ->orWhereDate('tenants.lease_end_date', '>=', $startDate);
            })
            ->selectRaw('tenant_unit_assignments.unit_id, COUNT(DISTINCT tenant_unit_assignments.tenant_id) as total')
            ->groupBy('tenant_unit_assignments.unit_id')
            ->pluck('total', 'tenant_unit_assignments.unit_id');
    }
}
