<?php

namespace App\Services;

use App\Models\PropertyUnit;
use App\Models\PublicPropertyOption;
use App\Models\TenantUnitAssignment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UnitAvailabilityService
{
    public function supportsTemporalAssignments(): bool
    {
        return Schema::hasColumn('tenant_unit_assignments', 'is_current')
            && Schema::hasColumn('tenant_unit_assignments', 'released_at');
    }

    public function getUnits(array $filters = []): Collection
    {
        $ownerUserId = $filters['owner_user_id'] ?? null;
        $propertyIds = collect($filters['property_ids'] ?? [])->filter()->map(fn ($id) => (int) $id)->values()->all();
        $unitIds = collect($filters['unit_ids'] ?? [])->filter()->map(fn ($id) => (int) $id)->values()->all();
        $search = trim((string) ($filters['search'] ?? ''));

        $query = PropertyUnit::query()
            ->join('properties', 'property_units.property_id', '=', 'properties.id')
            ->leftJoin('property_details', 'properties.id', '=', 'property_details.property_id')
            ->leftJoin('users as owners', function ($join) {
                $join->on('properties.owner_user_id', '=', 'owners.id')->whereNull('owners.deleted_at');
            })
            ->leftJoinSub($this->activeTenantSummaryQuery($ownerUserId), 'tenant_summary', function ($join) {
                $join->on('property_units.id', '=', 'tenant_summary.unit_id');
            })
            ->leftJoinSub($this->assignmentSummaryQuery($ownerUserId), 'assignment_summary', function ($join) {
                $join->on('property_units.id', '=', 'assignment_summary.unit_id');
            })
            ->leftJoin(
                'file_managers',
                [
                    'property_units.id' => 'file_managers.origin_id',
                    'file_managers.origin_type' => DB::raw("'App\\\Models\\\PropertyUnit'"),
                ]
            )
            ->select(
                'property_units.*',
                'properties.name as property_name',
                'properties.owner_user_id',
                'property_details.address as property_address',
                'owners.first_name as owner_first_name',
                'owners.last_name as owner_last_name',
                'owners.email as owner_email',
                'file_managers.file_name',
                'file_managers.folder_name',
                'tenant_summary.active_tenant_count',
                'tenant_summary.active_tenant_names',
                'tenant_summary.first_tenant_id',
                'assignment_summary.assignment_count',
                'assignment_summary.latest_released_at'
            )
            ->whereNull('properties.deleted_at')
            ->whereNull('property_units.deleted_at')
            ->orderBy('properties.id')
            ->orderBy('property_units.unit_name');

        if (! is_null($ownerUserId)) {
            $query->where('properties.owner_user_id', (int) $ownerUserId);
        }
        if (! empty($propertyIds)) {
            $query->whereIn('property_units.property_id', $propertyIds);
        }
        if (! empty($unitIds)) {
            $query->whereIn('property_units.id', $unitIds);
        }
        if ($search !== '') {
            $query->where(function ($subQuery) use ($search) {
                $subQuery
                    ->where('property_units.unit_name', 'LIKE', "%{$search}%")
                    ->orWhere('properties.name', 'LIKE', "%{$search}%")
                    ->orWhere('property_details.address', 'LIKE', "%{$search}%")
                    ->orWhere('owners.first_name', 'LIKE', "%{$search}%")
                    ->orWhere('owners.last_name', 'LIKE', "%{$search}%")
                    ->orWhere('owners.email', 'LIKE', "%{$search}%");
            });
        }

        $units = $query->get();
        $unitIds = $units->pluck('id')->filter()->all();
        $publicOptionUnitIds = PublicPropertyOption::query()
            ->whereIn('property_unit_id', $unitIds)
            ->whereNotNull('property_unit_id')
            ->pluck('property_unit_id')
            ->flip();

        return $units->map(function ($unit) use ($publicOptionUnitIds) {
            return $this->decorateUnit($unit, $publicOptionUnitIds->has($unit->id));
        });
    }

    public function getPropertySummaries(array $propertyIds, ?int $ownerUserId = null): Collection
    {
        $unitGroups = $this->getUnits([
            'owner_user_id' => $ownerUserId,
            'property_ids' => $propertyIds,
        ])->groupBy('property_id');

        return collect($propertyIds)->mapWithKeys(function ($propertyId) use ($unitGroups) {
            $units = $unitGroups->get((int) $propertyId, collect());

            return [
                (int) $propertyId => [
                    'available_unit' => $units->where('is_available_for_assignment', true)->count(),
                    'occupied_unit' => $units->filter(fn ($unit) => (int) $unit->active_tenant_count > 0)->count(),
                    'full_unit' => $units->where('occupancy_state', 'full')->count(),
                    'partial_unit' => $units->where('occupancy_state', 'partially_occupied')->count(),
                    'vacant_unit' => $units->where('occupancy_state', 'vacant')->count(),
                    'on_hold_unit' => $units->where('manual_availability_status', PropertyUnit::MANUAL_AVAILABILITY_ON_HOLD)->count(),
                    'off_market_unit' => $units->where('manual_availability_status', PropertyUnit::MANUAL_AVAILABILITY_OFF_MARKET)->count(),
                    'total_tenant' => $units->sum(fn ($unit) => (int) ($unit->active_tenant_count ?? 0)),
                ],
            ];
        });
    }

    public function decorateUnit($unit, bool $hasPublicOption = false)
    {
        $activeTenantCount = (int) ($unit->active_tenant_count ?? 0);
        $capacity = max((int) ($unit->max_occupancy ?? 0), max($activeTenantCount, 1));
        $availableSlots = max($capacity - $activeTenantCount, 0);
        $manualStatus = $unit->manual_availability_status ?: PropertyUnit::MANUAL_AVAILABILITY_ACTIVE;
        $isManualBlocked = in_array($manualStatus, [
            PropertyUnit::MANUAL_AVAILABILITY_ON_HOLD,
            PropertyUnit::MANUAL_AVAILABILITY_OFF_MARKET,
        ], true);

        if ($activeTenantCount === 0) {
            $occupancyState = 'vacant';
        } elseif ($availableSlots > 0) {
            $occupancyState = 'partially_occupied';
        } else {
            $occupancyState = 'full';
        }

        $isAvailableForAssignment = ! $isManualBlocked && $availableSlots > 0;

        $occupancyLabel = match ($occupancyState) {
            'vacant' => __('Vacant'),
            'partially_occupied' => $activeTenantCount . '/' . $capacity . ' ' . __('occupied'),
            default => __('Full') . ' ' . $activeTenantCount . '/' . $capacity,
        };

        $availabilityLabel = match ($manualStatus) {
            PropertyUnit::MANUAL_AVAILABILITY_ON_HOLD => __('On Hold'),
            PropertyUnit::MANUAL_AVAILABILITY_OFF_MARKET => __('Off Market'),
            default => $isAvailableForAssignment
                ? __('Available') . ' (' . $availableSlots . ' ' . __('slot(s)') . ')'
                : __('Unavailable'),
        };

        $unit->setAttribute('max_occupancy', $capacity);
        $unit->setAttribute('available_slots', $availableSlots);
        $unit->setAttribute('occupancy_state', $occupancyState);
        $unit->setAttribute('occupancy_label', $occupancyLabel);
        $unit->setAttribute('availability_label', $availabilityLabel);
        $unit->setAttribute('is_available_for_assignment', $isAvailableForAssignment);
        $unit->setAttribute('has_public_option', $hasPublicOption);
        $unit->setAttribute(
            'can_delete_unit',
            ! $hasPublicOption
            && (int) ($unit->assignment_count ?? 0) < 1
            && $activeTenantCount < 1
        );
        $unit->setAttribute(
            'owner_name',
            trim(($unit->owner_first_name ?? '') . ' ' . ($unit->owner_last_name ?? ''))
        );
        $unit->setAttribute(
            'available_since',
            $occupancyState === 'vacant' && $isAvailableForAssignment
                ? ($unit->last_vacated_at ?: $unit->latest_released_at)
                : null
        );

        return $unit;
    }

    private function activeTenantSummaryQuery(?int $ownerUserId = null)
    {
        $query = TenantUnitAssignment::query()
            ->join('tenants', 'tenant_unit_assignments.tenant_id', '=', 'tenants.id')
            ->join('users', 'tenants.user_id', '=', 'users.id')
            ->whereNull('users.deleted_at')
            ->where('tenants.status', TENANT_STATUS_ACTIVE);

        if ($this->supportsTemporalAssignments()) {
            $query->where('tenant_unit_assignments.is_current', true);
        }
        if (! is_null($ownerUserId)) {
            $query->where('tenants.owner_user_id', (int) $ownerUserId);
        }

        $activeTenantNamesExpression = $this->activeTenantNamesExpression();

        return $query
            ->selectRaw(
                "tenant_unit_assignments.unit_id,
                COUNT(DISTINCT tenant_unit_assignments.tenant_id) as active_tenant_count,
                {$activeTenantNamesExpression} as active_tenant_names,
                MIN(tenants.id) as first_tenant_id"
            )
            ->groupBy('tenant_unit_assignments.unit_id');
    }

    private function assignmentSummaryQuery(?int $ownerUserId = null)
    {
        $query = TenantUnitAssignment::query();

        if (! is_null($ownerUserId)) {
            $query->join('tenants', 'tenant_unit_assignments.tenant_id', '=', 'tenants.id')
                ->where('tenants.owner_user_id', (int) $ownerUserId);
        }

        $releasedColumn = $this->supportsTemporalAssignments()
            ? 'tenant_unit_assignments.released_at'
            : 'tenant_unit_assignments.updated_at';

        return $query
            ->selectRaw(
                "tenant_unit_assignments.unit_id,
                COUNT(DISTINCT tenant_unit_assignments.id) as assignment_count,
                MAX({$releasedColumn}) as latest_released_at"
            )
            ->groupBy('tenant_unit_assignments.unit_id');
    }

    private function activeTenantNamesExpression(): string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return "GROUP_CONCAT(DISTINCT (users.first_name || ' ' || users.last_name))";
        }

        return "GROUP_CONCAT(DISTINCT CONCAT(users.first_name, ' ', users.last_name) ORDER BY users.first_name SEPARATOR ', ')";
    }
}
