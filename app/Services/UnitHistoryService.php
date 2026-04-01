<?php

namespace App\Services;

use App\Models\PropertyUnit;
use App\Models\PropertyUnitActivityLog;
use App\Models\Tenant;
use App\Models\TenantUnitAssignment;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UnitHistoryService
{
    public function supportsTemporalAssignments(): bool
    {
        return Schema::hasColumn('tenant_unit_assignments', 'is_current')
            && Schema::hasColumn('tenant_unit_assignments', 'assigned_at')
            && Schema::hasColumn('tenant_unit_assignments', 'released_at');
    }

    public function syncPrimaryAssignment(Tenant $tenant, int $propertyId, int $unitId, ?int $actorUserId = null): void
    {
        if (! is_null($tenant->property_id) && ! is_null($tenant->unit_id)) {
            $currentPropertyId = (int) $tenant->property_id;
            $currentUnitId = (int) $tenant->unit_id;

            if ($currentPropertyId !== $propertyId || $currentUnitId !== $unitId) {
                $this->closeCurrentAssignmentForUnit(
                    $tenant,
                    $currentPropertyId,
                    $currentUnitId,
                    __('Tenant reassigned to another unit'),
                    null,
                    $actorUserId
                );
            }
        }

        $this->beginAssignment($tenant, $propertyId, $unitId, $actorUserId, $tenant->lease_start_date);
    }

    public function beginAssignment(
        Tenant $tenant,
        int $propertyId,
        int $unitId,
        ?int $actorUserId = null,
        ?string $assignedAt = null
    ): TenantUnitAssignment {
        if (! $this->supportsTemporalAssignments()) {
            return TenantUnitAssignment::query()->firstOrCreate([
                'tenant_id' => $tenant->id,
                'property_id' => $propertyId,
                'unit_id' => $unitId,
            ]);
        }

        $existing = TenantUnitAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->where('property_id', $propertyId)
            ->where('unit_id', $unitId)
            ->where('is_current', true)
            ->first();

        if ($existing) {
            return $existing;
        }

        $assignment = TenantUnitAssignment::query()->create([
            'tenant_id' => $tenant->id,
            'property_id' => $propertyId,
            'unit_id' => $unitId,
            'assigned_at' => $assignedAt ? Carbon::parse($assignedAt) : now(),
            'is_current' => true,
        ]);

        $this->logUnitActivity(
            $tenant->owner_user_id,
            $propertyId,
            $unitId,
            $tenant->id,
            $actorUserId,
            'tenant_assigned',
            null,
            [
                'tenant_id' => $tenant->id,
                'assigned_at' => optional($assignment->assigned_at)->toDateTimeString(),
            ],
            __('Tenant assigned to unit')
        );

        return $assignment;
    }

    public function closeAllCurrentAssignmentsForTenant(
        Tenant $tenant,
        ?string $reason = null,
        ?string $releasedAt = null,
        ?int $actorUserId = null
    ): void {
        $this->ensurePrimaryAssignmentExists($tenant);

        $query = TenantUnitAssignment::query()->where('tenant_id', $tenant->id);
        if ($this->supportsTemporalAssignments()) {
            $query->where('is_current', true);
        }

        $assignments = $query->get();
        foreach ($assignments as $assignment) {
            $this->closeAssignmentRecord(
                $assignment,
                $reason ?: __('Tenant moved out'),
                $releasedAt,
                $actorUserId,
                'tenant_moved_out'
            );
        }
    }

    public function closeCurrentAssignmentForUnit(
        Tenant $tenant,
        int $propertyId,
        int $unitId,
        ?string $reason = null,
        ?string $releasedAt = null,
        ?int $actorUserId = null
    ): void {
        $this->ensurePrimaryAssignmentExists($tenant);

        $query = TenantUnitAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->where('property_id', $propertyId)
            ->where('unit_id', $unitId);

        if ($this->supportsTemporalAssignments()) {
            $query->where('is_current', true);
        }

        $assignment = $query->latest('id')->first();
        if (! $assignment) {
            return;
        }

        $this->closeAssignmentRecord(
            $assignment,
            $reason ?: __('Assignment closed'),
            $releasedAt,
            $actorUserId,
            'tenant_reassigned'
        );
    }

    public function updateUnitOperationalSettings(PropertyUnit $unit, array $payload, int $actorUserId): PropertyUnit
    {
        $activeTenantCount = $this->getActiveOccupantCount($unit->id);
        $oldStatus = $unit->manual_availability_status ?: PropertyUnit::MANUAL_AVAILABILITY_ACTIVE;
        $oldReason = $unit->manual_status_reason;
        $oldCapacity = (int) ($unit->max_occupancy ?? 0);

        $nextStatus = $payload['manual_availability_status'] ?? $oldStatus;
        $nextReason = $payload['manual_status_reason'] ?? null;
        $nextCapacity = max((int) ($payload['max_occupancy'] ?? $oldCapacity), 1);

        if ($nextCapacity < $activeTenantCount) {
            throw new \Exception(__('Max occupancy cannot be lower than current active occupants.'));
        }

        $unit->max_occupancy = $nextCapacity;
        $unit->manual_availability_status = $nextStatus;
        $unit->manual_status_reason = $nextStatus === PropertyUnit::MANUAL_AVAILABILITY_ACTIVE ? null : $nextReason;
        $unit->manual_status_changed_at = now();
        $unit->manual_status_changed_by = $actorUserId;
        $unit->save();

        if ($oldCapacity !== $nextCapacity) {
            $this->logUnitActivity(
                $unit->property?->owner_user_id,
                $unit->property_id,
                $unit->id,
                null,
                $actorUserId,
                'capacity_changed',
                ['max_occupancy' => $oldCapacity],
                ['max_occupancy' => $nextCapacity],
                __('Unit capacity updated')
            );
        }

        if ($oldStatus !== $nextStatus || $oldReason !== $unit->manual_status_reason) {
            $eventType = match ($nextStatus) {
                PropertyUnit::MANUAL_AVAILABILITY_ON_HOLD => 'manual_hold_set',
                PropertyUnit::MANUAL_AVAILABILITY_OFF_MARKET => 'unit_retired',
                default => 'manual_hold_cleared',
            };

            $this->logUnitActivity(
                $unit->property?->owner_user_id,
                $unit->property_id,
                $unit->id,
                null,
                $actorUserId,
                $eventType,
                [
                    'manual_availability_status' => $oldStatus,
                    'manual_status_reason' => $oldReason,
                ],
                [
                    'manual_availability_status' => $nextStatus,
                    'manual_status_reason' => $unit->manual_status_reason,
                ],
                __('Unit availability controls updated')
            );
        }

        return $unit->refresh();
    }

    public function getUnitHistory(PropertyUnit $unit): array
    {
        $assignmentQuery = TenantUnitAssignment::query()
            ->where('unit_id', $unit->id)
            ->with([
                'tenant.user',
                'property',
                'releasedBy',
            ]);

        if ($this->supportsTemporalAssignments()) {
            $assignmentQuery
                ->orderByDesc('is_current')
                ->orderByDesc('assigned_at')
                ->orderByDesc('id');
        } else {
            $assignmentQuery->orderByDesc('id');
        }

        $assignments = $assignmentQuery->get();
        $currentAssignments = $assignments->filter(function ($assignment) {
            return ! $this->supportsTemporalAssignments() || $assignment->is_current;
        })->values();
        $pastAssignments = $assignments->reject(function ($assignment) {
            return $this->supportsTemporalAssignments() && $assignment->is_current;
        })->values();
        $lastReleasedAssignment = $pastAssignments
            ->sortByDesc(fn ($assignment) => optional($assignment->released_at)->timestamp ?? 0)
            ->first();

        $activityLogs = collect();
        if (Schema::hasTable('property_unit_activity_logs')) {
            $activityLogs = PropertyUnitActivityLog::query()
                ->where('unit_id', $unit->id)
                ->with(['tenant.user', 'actor'])
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->get();
        }

        return [
            'currentAssignments' => $currentAssignments,
            'pastAssignments' => $pastAssignments,
            'lastReleasedAssignment' => $lastReleasedAssignment,
            'activityLogs' => $activityLogs,
        ];
    }

    private function ensurePrimaryAssignmentExists(Tenant $tenant): void
    {
        if (is_null($tenant->property_id) || is_null($tenant->unit_id)) {
            return;
        }

        $query = TenantUnitAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->where('property_id', $tenant->property_id)
            ->where('unit_id', $tenant->unit_id);

        if ($this->supportsTemporalAssignments()) {
            $query->where('is_current', true);
        }

        if ($query->exists()) {
            return;
        }

        $payload = [
            'tenant_id' => $tenant->id,
            'property_id' => $tenant->property_id,
            'unit_id' => $tenant->unit_id,
        ];

        if ($this->supportsTemporalAssignments()) {
            $payload['assigned_at'] = $tenant->lease_start_date ? Carbon::parse($tenant->lease_start_date) : now();
            $payload['is_current'] = true;
        }

        TenantUnitAssignment::query()->create($payload);
    }

    private function closeAssignmentRecord(
        TenantUnitAssignment $assignment,
        string $reason,
        ?string $releasedAt,
        ?int $actorUserId,
        string $eventType
    ): void {
        if ($this->supportsTemporalAssignments()) {
            $assignment->released_at = $releasedAt ? Carbon::parse($releasedAt) : now();
            $assignment->release_reason = $reason;
            $assignment->released_by_user_id = $actorUserId;
            $assignment->is_current = false;
            $assignment->save();
        }

        if (Schema::hasColumn('property_units', 'last_vacated_at')) {
            DB::table('property_units')
                ->where('id', $assignment->unit_id)
                ->update(['last_vacated_at' => $releasedAt ? Carbon::parse($releasedAt) : now()]);
        }

        $tenant = $assignment->relationLoaded('tenant') ? $assignment->tenant : Tenant::find($assignment->tenant_id);

        $this->logUnitActivity(
            $tenant?->owner_user_id,
            $assignment->property_id,
            $assignment->unit_id,
            $assignment->tenant_id,
            $actorUserId,
            $eventType,
            [
                'is_current' => true,
            ],
            [
                'released_at' => $releasedAt ? Carbon::parse($releasedAt)->toDateTimeString() : now()->toDateTimeString(),
                'release_reason' => $reason,
                'is_current' => false,
            ],
            $reason
        );
    }

    private function getActiveOccupantCount(int $unitId): int
    {
        $query = TenantUnitAssignment::query()
            ->join('tenants', 'tenant_unit_assignments.tenant_id', '=', 'tenants.id')
            ->join('users', 'tenants.user_id', '=', 'users.id')
            ->whereNull('users.deleted_at')
            ->where('tenants.status', TENANT_STATUS_ACTIVE)
            ->where('tenant_unit_assignments.unit_id', $unitId);

        if ($this->supportsTemporalAssignments()) {
            $query->where('tenant_unit_assignments.is_current', true);
        }

        return (int) $query->count(DB::raw('DISTINCT tenants.id'));
    }

    private function logUnitActivity(
        ?int $ownerUserId,
        int $propertyId,
        int $unitId,
        ?int $tenantId,
        ?int $actorUserId,
        string $eventType,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $notes = null
    ): void {
        if (! Schema::hasTable('property_unit_activity_logs')) {
            return;
        }

        PropertyUnitActivityLog::query()->create([
            'owner_user_id' => $ownerUserId,
            'property_id' => $propertyId,
            'unit_id' => $unitId,
            'tenant_id' => $tenantId,
            'actor_user_id' => $actorUserId,
            'event_type' => $eventType,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'notes' => $notes,
            'occurred_at' => now(),
        ]);
    }
}
