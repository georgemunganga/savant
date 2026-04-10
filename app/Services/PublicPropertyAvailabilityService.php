<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Property;
use App\Models\PropertyUnit;
use App\Models\PublicPropertyBooking;
use App\Models\PublicPropertyOption;
use App\Models\PublicPropertyWaitlist;
use App\Models\Tenant;
use App\Models\TenantDetails;
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
            $payload['stay_mode'],
            max((int) $payload['guests'], 1)
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
            'date_of_birth' => Carbon::parse($payload['date_of_birth'])->toDateString(),
            'nationality_country_id' => (string) $payload['nationality_country_id'],
            'id_type' => (string) $payload['id_type'],
            'id_number' => (string) $payload['id_number'],
            'occupation' => (string) $payload['occupation'],
            'is_student' => (bool) $payload['is_student'],
            'year_of_study' => $this->normalizeYearOfStudy($payload),
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
                $payload['stay_mode'],
                max((int) $payload['guests'], 1)
            );

            if (!$availability['available']) {
                throw new Exception(__('This stay is no longer available. Please refresh availability and try again.'));
            }

            [$user, $tenant, $accountCreated] = $this->createOrReuseTenantAccount($property, $payload);
            $pendingFee = $this->getPendingFeeSummary($tenant);

            if ($pendingFee) {
                DB::rollBack();

                return [
                    'requires_fee_clearance' => true,
                    'pending_fee' => $pendingFee,
                    'requires_confirmation' => false,
                    'conflict_summary' => null,
                ];
            }

            $bookingRequirement = $this->resolveConcurrentBookingRequirement($tenant, $user);

            if ($bookingRequirement['requires_confirmation'] && empty($payload['confirm_existing_booking'])) {
                DB::rollBack();

                return $bookingRequirement;
            }

            $setupEmailSent = $accountCreated || is_null($user->email_verified_at);
            if ($setupEmailSent) {
                $this->tenantAccessService->sendAccountSetupEmail($user, $property->owner_user_id);
            }

            $this->syncTenantLeadProfile($user, $tenant, $payload, $accountCreated);

            $shouldAutoAssign = !$bookingRequirement['requires_confirmation'];
            $assignmentChanged = false;
            if ($shouldAutoAssign) {
                $assignmentChanged = $this->applyBookingAssignment(
                    $tenant,
                    $property,
                    $option,
                    $payload['stay_mode'],
                    $startDate,
                    $endDate
                );
            }

            if ($shouldAutoAssign && $assignmentChanged && !is_null($tenant->property_id) && !is_null($tenant->unit_id)) {
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
                'property_unit_id' => $shouldAutoAssign ? ($tenant->unit_id ?: $option->property_unit_id) : null,
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'stay_mode' => $payload['stay_mode'],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'guests' => (int) $payload['guests'],
                'full_name' => $payload['full_name'],
                'email' => $user->email,
                'phone' => $this->normalizePhone($payload['phone'] ?? null),
                'date_of_birth' => Carbon::parse($payload['date_of_birth'])->toDateString(),
                'nationality_country_id' => (string) $payload['nationality_country_id'],
                'id_type' => (string) $payload['id_type'],
                'id_number' => (string) $payload['id_number'],
                'occupation' => (string) $payload['occupation'],
                'is_student' => (bool) $payload['is_student'],
                'year_of_study' => $this->normalizeYearOfStudy($payload),
                'payment_plan' => $payload['payment_plan'],
                'status' => PublicPropertyBooking::STATUS_CONFIRMED,
                'source' => 'website',
                'account_created' => $accountCreated,
                'setup_email_sent' => $setupEmailSent,
                'has_assignment' => $shouldAutoAssign && !is_null($tenant->property_id) && !is_null($tenant->unit_id),
                'assignment_created' => $shouldAutoAssign ? $assignmentChanged : false,
                'confirmed_at' => now(),
            ]);

            DB::commit();

            return [
                'requires_fee_clearance' => false,
                'pending_fee' => null,
                'requires_confirmation' => false,
                'conflict_summary' => null,
                'booking' => [
                    'id' => $bookingRecord->id,
                    'property_id' => $property->id,
                    'option_id' => $option->id,
                    'tenant_id' => $tenant->id,
                    'email' => $user->email,
                    'stay_mode' => $payload['stay_mode'],
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'guests' => (int) $payload['guests'],
                    'date_of_birth' => Carbon::parse($payload['date_of_birth'])->toDateString(),
                    'nationality_country_id' => (string) $payload['nationality_country_id'],
                    'id_type' => (string) $payload['id_type'],
                    'id_number' => (string) $payload['id_number'],
                    'occupation' => (string) $payload['occupation'],
                    'is_student' => (bool) $payload['is_student'],
                    'year_of_study' => $this->normalizeYearOfStudy($payload),
                    'payment_plan' => $payload['payment_plan'],
                    'account_created' => $accountCreated,
                    'setup_email_sent' => $setupEmailSent,
                    'has_assignment' => $shouldAutoAssign && !is_null($tenant->property_id) && !is_null($tenant->unit_id),
                    'assignment_created' => $shouldAutoAssign ? $assignmentChanged : false,
                ],
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

    private function syncTenantLeadProfile(User $user, Tenant $tenant, array $payload, bool $accountCreated): void
    {
        $dateOfBirth = Carbon::parse($payload['date_of_birth'])->toDateString();
        $occupation = trim((string) ($payload['occupation'] ?? ''));
        $nationalityCountryId = trim((string) ($payload['nationality_country_id'] ?? ''));
        $idType = trim((string) ($payload['id_type'] ?? ''));
        $idNumber = trim((string) ($payload['id_number'] ?? ''));
        $isStudent = (bool) ($payload['is_student'] ?? false);
        $yearOfStudy = $this->normalizeYearOfStudy($payload);
        $usersHasDateOfBirth = Schema::hasColumn('users', 'date_of_birth');
        $tenantsHasDateOfBirth = Schema::hasColumn('tenants', 'date_of_birth');
        $tenantsHasTenantType = Schema::hasColumn('tenants', 'tenant_type');
        $tenantDetailsHasNationality = Schema::hasColumn('tenant_details', 'nationality_country_id');
        $tenantDetailsHasIdentityType = Schema::hasColumn('tenant_details', 'identity_document_type');
        $tenantDetailsHasIdentityNumber = Schema::hasColumn('tenant_details', 'identity_document_number');
        $tenantDetailsHasYearOfStudy = Schema::hasColumn('tenant_details', 'year_of_study');

        if ($usersHasDateOfBirth && ($accountCreated || blank($user->date_of_birth))) {
            $user->date_of_birth = $dateOfBirth;
            $user->save();
        }

        if ($accountCreated || blank($tenant->job) || $tenant->job === __('Guest')) {
            $tenant->job = $occupation;
        }
        if ($tenantsHasDateOfBirth && ($accountCreated || blank($tenant->date_of_birth))) {
            $tenant->date_of_birth = $dateOfBirth;
        }
        if ($tenantsHasTenantType && $accountCreated) {
            $tenant->tenant_type = $isStudent ? 'student' : ($tenant->tenant_type ?: 'person');
        } elseif ($tenantsHasTenantType && blank($tenant->tenant_type)) {
            $tenant->tenant_type = $isStudent ? 'student' : 'person';
        }
        $tenant->save();

        $details = TenantDetails::query()->firstOrCreate(['tenant_id' => $tenant->id]);
        if ($tenantDetailsHasNationality && ($accountCreated || blank($details->nationality_country_id))) {
            $details->nationality_country_id = $nationalityCountryId;
        }
        if ($tenantDetailsHasIdentityType && ($accountCreated || blank($details->identity_document_type))) {
            $details->identity_document_type = $idType;
        }
        if ($tenantDetailsHasIdentityNumber && ($accountCreated || blank($details->identity_document_number))) {
            $details->identity_document_number = $idNumber;
        }
        if ($tenantDetailsHasYearOfStudy && ($accountCreated || blank($details->year_of_study))) {
            $details->year_of_study = $isStudent ? $yearOfStudy : null;
        }
        $details->save();
    }

    private function normalizePhone($value): ?string
    {
        $phone = trim((string) $value);

        return $phone !== '' ? $phone : null;
    }

    private function normalizeYearOfStudy(array $payload): ?string
    {
        $yearOfStudy = trim((string) ($payload['year_of_study'] ?? ''));

        return !empty($payload['is_student']) && $yearOfStudy !== '' ? $yearOfStudy : null;
    }

    private function resolveConcurrentBookingRequirement(Tenant $tenant, User $user): array
    {
        $currentStay = $this->getCurrentOrUpcomingStaySummary($tenant);
        $openBooking = $this->getOpenBookingSummary($tenant, $user);

        if (!$currentStay && !$openBooking) {
            return [
                'requires_fee_clearance' => false,
                'pending_fee' => null,
                'requires_confirmation' => false,
                'conflict_summary' => null,
            ];
        }

        $title = $currentStay
            ? __('This guest already has another active or upcoming stay.')
            : __('This guest already has another pending website booking.');

        return [
            'requires_fee_clearance' => false,
            'pending_fee' => null,
            'requires_confirmation' => true,
            'conflict_summary' => [
                'title' => $title,
                'message' => __('Confirm to create an additional website booking without changing the tenant\'s current assignment.'),
                'current_stay' => $currentStay,
                'open_booking' => $openBooking,
            ],
        ];
    }

    private function getCurrentOrUpcomingStaySummary(Tenant $tenant): ?array
    {
        if (
            (int) $tenant->status !== TENANT_STATUS_ACTIVE ||
            is_null($tenant->property_id) ||
            is_null($tenant->unit_id)
        ) {
            return null;
        }

        if (!is_null($tenant->lease_end_date) && Carbon::parse($tenant->lease_end_date)->lt(now()->startOfDay())) {
            return null;
        }

        $property = $tenant->relationLoaded('property')
            ? $tenant->property
            : Property::query()->find($tenant->property_id);
        $unit = $tenant->relationLoaded('unit')
            ? $tenant->unit
            : PropertyUnit::query()->find($tenant->unit_id);

        return [
            'property_name' => $property?->name,
            'unit_name' => $unit?->unit_name,
            'start_date' => $this->formatDateValue($tenant->lease_start_date),
            'end_date' => $this->formatDateValue($tenant->lease_end_date),
            'status' => 'active',
        ];
    }

    private function getOpenBookingSummary(Tenant $tenant, User $user): ?array
    {
        $booking = PublicPropertyBooking::query()
            ->with(['property:id,name', 'unit:id,unit_name'])
            ->where(function ($query) use ($tenant, $user) {
                $query->where('tenant_id', $tenant->id)
                    ->orWhere('user_id', $user->id);
            })
            ->whereIn('status', [
                PublicPropertyBooking::STATUS_CONFIRMED,
                PublicPropertyBooking::STATUS_CHECKED_IN,
            ])
            ->latest('confirmed_at')
            ->latest('id')
            ->first();

        if (!$booking) {
            return null;
        }

        return [
            'property_name' => $booking->property?->name,
            'unit_name' => $booking->unit?->unit_name,
            'start_date' => $this->formatDateValue($booking->start_date),
            'end_date' => $this->formatDateValue($booking->end_date),
            'status' => $booking->status,
        ];
    }

    private function formatDateValue($value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return Carbon::parse($value)->toDateString();
    }

    private function getPendingFeeSummary(Tenant $tenant): ?array
    {
        $invoice = Invoice::query()
            ->with(['property:id,name', 'propertyUnit:id,unit_name'])
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', [
                INVOICE_STATUS_PENDING,
                INVOICE_STATUS_OVER_DUE,
            ])
            ->orderByRaw(
                'CASE WHEN status = ? THEN 0 ELSE 1 END',
                [INVOICE_STATUS_OVER_DUE]
            )
            ->orderBy('due_date')
            ->orderBy('id')
            ->first();

        if (!$invoice) {
            return null;
        }

        $amountDue = (float) ($invoice->amount ?? 0);
        if ((int) $invoice->status === INVOICE_STATUS_OVER_DUE) {
            $amountDue += (float) ($invoice->late_fee ?? 0);
        }

        return [
            'invoice_id' => $invoice->id,
            'invoice_no' => $invoice->invoice_no ?: ('#' . $invoice->id),
            'amount_due' => $amountDue,
            'due_date' => $this->formatDateValue($invoice->due_date),
            'property_name' => $invoice->property?->name,
            'unit_name' => $invoice->propertyUnit?->unit_name,
            'status' => (int) $invoice->status === INVOICE_STATUS_OVER_DUE ? 'overdue' : 'pending',
        ];
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
        $tenant->security_deposit_type = (int) ($option->security_deposit_type ?? TYPE_FIXED);
        $tenant->security_deposit = (float) ($option->security_deposit_value ?? 0);
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
        string $stayMode,
        int $guests
    ): array {
        if ($option->property_unit_id) {
            return $this->calculateUnitAvailability($property, $option, $startDate, $endDate, $stayMode, $guests);
        }

        return match ($option->rental_kind) {
            'shared_space' => $this->calculateAggregateSharedSpaceAvailability(
                $property,
                $option,
                $startDate,
                $endDate,
                $stayMode,
                $guests
            ),
            'whole_unit', 'private_room' => $this->calculateAggregateUnitInventoryAvailability(
                $property,
                $option,
                $startDate,
                $endDate,
                $stayMode,
                $guests
            ),
            default => $this->calculateWholePropertyAvailability($property, $option, $startDate, $endDate, $stayMode),
        };
    }

    private function calculateUnitAvailability(
        Property $property,
        PublicPropertyOption $option,
        string $startDate,
        string $endDate,
        string $stayMode,
        int $guests
    ): array {
        $unit = PropertyUnit::query()
            ->where('property_id', $property->id)
            ->where('id', $option->property_unit_id)
            ->whereNull('deleted_at')
            ->first();

        if (!$unit || is_null($unit->max_occupancy) || (int) $unit->max_occupancy <= 0) {
            return $this->unknownAvailabilityPayload(
                $property->id,
                $option->id,
                $startDate,
                $endDate,
                $stayMode,
                $this->resolveInventoryMode($option)
            );
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
                $stayMode,
                $this->resolveInventoryMode($option),
                (int) $unit->max_occupancy,
                0,
                1
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
                $stayMode,
                'unit',
                1,
                $available ? 1 : 0,
                1
            );
        }

        $availableSlots = max((int) $unit->max_occupancy - $occupied, 0);
        $inventoryMode = $this->resolveInventoryMode($option);

        return $this->availabilityPayload(
            $property->id,
            $option->id,
            $availableSlots >= $guests,
            $availableSlots >= $guests ? 'available' : 'full',
            (int) $unit->max_occupancy,
            $availableSlots,
            0,
            $startDate,
            $endDate,
            $stayMode,
            $inventoryMode,
            (int) $unit->max_occupancy,
            $availableSlots,
            1
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
            return $this->unknownAvailabilityPayload(
                $property->id,
                $option->id,
                $startDate,
                $endDate,
                $stayMode,
                'property'
            );
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
                $stayMode,
                'property',
                1,
                0,
                $units->count()
            );
        }

        $unknownUnits = $units->filter(
            fn (PropertyUnit $unit) => is_null($unit->max_occupancy) || (int) $unit->max_occupancy <= 0
        );

        if ($unknownUnits->isNotEmpty()) {
            return $this->unknownAvailabilityPayload(
                $property->id,
                $option->id,
                $startDate,
                $endDate,
                $stayMode,
                'property',
                $unknownUnits->count(),
                $units->count()
            );
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
            $stayMode,
            'property',
            1,
            $available ? 1 : 0,
            $units->count()
        );
    }

    private function calculateAggregateSharedSpaceAvailability(
        Property $property,
        PublicPropertyOption $option,
        string $startDate,
        string $endDate,
        string $stayMode,
        int $guests
    ): array {
        $units = $this->getPropertyAvailabilityUnits($property);

        if ($units->isEmpty()) {
            return $this->unknownAvailabilityPayload(
                $property->id,
                $option->id,
                $startDate,
                $endDate,
                $stayMode,
                'bedspace'
            );
        }

        $eligibleUnits = $units->filter(function (PropertyUnit $unit) {
            return !$this->isManualAvailabilityBlocked($unit)
                && !is_null($unit->max_occupancy)
                && (int) $unit->max_occupancy > 0;
        })->values();
        $unknownUnits = $units->filter(
            fn (PropertyUnit $unit) => is_null($unit->max_occupancy) || (int) $unit->max_occupancy <= 0
        );

        if ($eligibleUnits->isEmpty()) {
            $knownCapacityUnits = $units->filter(
                fn (PropertyUnit $unit) => !is_null($unit->max_occupancy) && (int) $unit->max_occupancy > 0
            );

            if ($knownCapacityUnits->isEmpty()) {
                return $this->unknownAvailabilityPayload(
                    $property->id,
                    $option->id,
                    $startDate,
                    $endDate,
                    $stayMode,
                    'bedspace',
                    $unknownUnits->count(),
                    0
                );
            }

            return $this->availabilityPayload(
                $property->id,
                $option->id,
                false,
                'full',
                0,
                0,
                $unknownUnits->count(),
                $startDate,
                $endDate,
                $stayMode,
                'bedspace',
                0,
                0,
                0
            );
        }

        $occupiedCounts = $this->getOccupiedUnitCounts($property->id, $startDate, $endDate);
        $capacityTotal = (int) $eligibleUnits->sum(fn (PropertyUnit $unit) => (int) $unit->max_occupancy);
        $capacityAvailable = (int) $eligibleUnits->sum(function (PropertyUnit $unit) use ($occupiedCounts) {
            return max((int) $unit->max_occupancy - (int) ($occupiedCounts[$unit->id] ?? 0), 0);
        });

        return $this->availabilityPayload(
            $property->id,
            $option->id,
            $capacityAvailable >= $guests,
            $capacityAvailable >= $guests ? 'available' : 'full',
            $capacityTotal,
            $capacityAvailable,
            $unknownUnits->count(),
            $startDate,
            $endDate,
            $stayMode,
            'bedspace',
            $capacityTotal,
            $capacityAvailable,
            $eligibleUnits->count()
        );
    }

    private function calculateAggregateUnitInventoryAvailability(
        Property $property,
        PublicPropertyOption $option,
        string $startDate,
        string $endDate,
        string $stayMode,
        int $guests
    ): array {
        $units = $this->getPropertyAvailabilityUnits($property);

        if ($units->isEmpty()) {
            return $this->unknownAvailabilityPayload(
                $property->id,
                $option->id,
                $startDate,
                $endDate,
                $stayMode,
                'unit'
            );
        }

        $eligibleUnits = $units->filter(function (PropertyUnit $unit) {
            return !$this->isManualAvailabilityBlocked($unit)
                && !is_null($unit->max_occupancy)
                && (int) $unit->max_occupancy > 0;
        })->values();
        $unknownUnits = $units->filter(
            fn (PropertyUnit $unit) => is_null($unit->max_occupancy) || (int) $unit->max_occupancy <= 0
        );

        if ($eligibleUnits->isEmpty()) {
            return $this->unknownAvailabilityPayload(
                $property->id,
                $option->id,
                $startDate,
                $endDate,
                $stayMode,
                'unit',
                $unknownUnits->count(),
                0
            );
        }

        $occupiedCounts = $this->getOccupiedUnitCounts($property->id, $startDate, $endDate);
        $unitsAvailable = (int) $eligibleUnits->filter(function (PropertyUnit $unit) use ($occupiedCounts, $option, $guests) {
            $occupied = (int) ($occupiedCounts[$unit->id] ?? 0);
            if ($option->rental_kind === 'whole_unit') {
                return $occupied === 0;
            }

            return max((int) $unit->max_occupancy - $occupied, 0) >= $guests;
        })->count();

        return $this->availabilityPayload(
            $property->id,
            $option->id,
            $unitsAvailable > 0,
            $unitsAvailable > 0 ? 'available' : 'full',
            $eligibleUnits->count(),
            $unitsAvailable,
            $unknownUnits->count(),
            $startDate,
            $endDate,
            $stayMode,
            'unit',
            $eligibleUnits->count(),
            $unitsAvailable,
            $eligibleUnits->count()
        );
    }

    private function unknownAvailabilityPayload(
        int $propertyId,
        int $optionId,
        string $startDate,
        string $endDate,
        string $stayMode,
        string $inventoryMode,
        int $unitsUnknown = 1,
        int $eligibleUnitsTotal = 0
    ): array {
        return $this->availabilityPayload(
            $propertyId,
            $optionId,
            false,
            'unknown',
            1,
            0,
            $unitsUnknown,
            $startDate,
            $endDate,
            $stayMode,
            $inventoryMode,
            0,
            0,
            $eligibleUnitsTotal
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
        string $stayMode,
        string $inventoryMode,
        int $capacityTotal,
        int $capacityAvailable,
        int $eligibleUnitsTotal
    ): array {
        return [
            'property_id' => $propertyId,
            'option_id' => $optionId,
            'available' => $available,
            'availability_status' => $status,
            'inventory_mode' => $inventoryMode,
            'units_total' => $unitsTotal,
            'units_available' => $unitsAvailable,
            'units_unknown' => $unitsUnknown,
            'capacity_total' => $capacityTotal,
            'capacity_available' => $capacityAvailable,
            'eligible_units_total' => $eligibleUnitsTotal,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'stay_mode' => $stayMode,
        ];
    }

    private function getPropertyAvailabilityUnits(Property $property): Collection
    {
        $unitColumns = ['id', 'property_id', 'max_occupancy'];
        if (Schema::hasColumn('property_units', 'manual_availability_status')) {
            $unitColumns[] = 'manual_availability_status';
        }

        return PropertyUnit::query()
            ->where('property_id', $property->id)
            ->whereNull('deleted_at')
            ->get($unitColumns);
    }

    private function isManualAvailabilityBlocked(PropertyUnit $unit): bool
    {
        return in_array(($unit->manual_availability_status ?? null), [
            PropertyUnit::MANUAL_AVAILABILITY_ON_HOLD,
            PropertyUnit::MANUAL_AVAILABILITY_OFF_MARKET,
        ], true);
    }

    private function resolveInventoryMode(PublicPropertyOption $option): string
    {
        return match ($option->rental_kind) {
            'whole_property' => 'property',
            'shared_space' => 'bedspace',
            default => 'unit',
        };
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
