<?php

namespace App\Services;

use App\Models\PropertyUnit;
use App\Models\PublicPropertyOption;

class UnitManagementService
{
    public function __construct(
        private readonly UnitAvailabilityService $unitAvailabilityService = new UnitAvailabilityService(),
        private readonly UnitHistoryService $unitHistoryService = new UnitHistoryService()
    ) {
    }

    public function getOwnerUnitDetail(int $unitId): array
    {
        $unit = PropertyUnit::query()
            ->with(['property.propertyDetail', 'fileAttachImage'])
            ->whereHas('property', function ($query) {
                $query->where('owner_user_id', getOwnerUserId());
            })
            ->findOrFail($unitId);

        return $this->buildUnitDetailPayload($unit, ['owner_user_id' => getOwnerUserId(), 'unit_ids' => [$unitId]]);
    }

    public function getAdminUnitDetail(int $unitId): array
    {
        $unit = PropertyUnit::query()
            ->with(['property.propertyDetail', 'fileAttachImage'])
            ->findOrFail($unitId);

        return $this->buildUnitDetailPayload($unit, ['unit_ids' => [$unitId]]);
    }

    public function updateOwnerUnitSettings(int $unitId, array $payload)
    {
        $unit = PropertyUnit::query()
            ->whereHas('property', function ($query) {
                $query->where('owner_user_id', getOwnerUserId());
            })
            ->findOrFail($unitId);

        return $this->unitHistoryService->updateUnitOperationalSettings($unit, $payload, auth()->id());
    }

    public function updateAdminUnitSettings(int $unitId, array $payload)
    {
        $unit = PropertyUnit::query()->findOrFail($unitId);

        return $this->unitHistoryService->updateUnitOperationalSettings($unit, $payload, auth()->id());
    }

    public function getAdminUnits(array $filters = [])
    {
        return $this->unitAvailabilityService->getUnits($filters);
    }

    private function buildUnitDetailPayload(PropertyUnit $unit, array $filters): array
    {
        $unitSummary = $this->unitAvailabilityService->getUnits($filters)->first();
        if (! $unitSummary) {
            $unitSummary = $this->unitAvailabilityService->decorateUnit($unit, false);
        }

        $history = $this->unitHistoryService->getUnitHistory($unit);
        $property = $unit->property;
        $publicOption = PublicPropertyOption::query()
            ->where('property_unit_id', $unit->id)
            ->latest('id')
            ->first();

        return [
            'unit' => $unitSummary,
            'property' => $property,
            'publicOption' => $publicOption,
            'currentAssignments' => $history['currentAssignments'],
            'pastAssignments' => $history['pastAssignments'],
            'lastReleasedAssignment' => $history['lastReleasedAssignment'],
            'activityLogs' => $history['activityLogs'],
            'activeTenantCount' => (int) ($unitSummary->active_tenant_count ?? 0),
            'availableSlots' => (int) ($unitSummary->available_slots ?? 0),
            'totalAssignments' => (int) ($unitSummary->assignment_count ?? 0),
        ];
    }
}
