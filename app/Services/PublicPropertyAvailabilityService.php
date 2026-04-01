<?php

namespace App\Services;

use App\Models\Property;
use App\Models\PropertyUnit;
use App\Models\PublicPropertyOption;
use App\Models\PublicPropertyWaitlist;
use App\Models\TenantUnitAssignment;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class PublicPropertyAvailabilityService
{
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
