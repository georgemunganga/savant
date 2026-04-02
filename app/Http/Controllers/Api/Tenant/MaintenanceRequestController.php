<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\MaintenanceRequest;
use App\Models\TenantUnitAssignment;
use App\Services\MaintenanceIssueService;
use App\Services\MaintenanceRequestService;
use App\Traits\ResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;

class MaintenanceRequestController extends Controller
{
    use ResponseTrait;

    public $maintenanceRequestService;
    public $maintenanceIssueService;

    public function __construct()
    {
        $this->maintenanceRequestService = new MaintenanceRequestService;
        $this->maintenanceIssueService = new MaintenanceIssueService;
    }

    public function index()
    {
        $tenant = auth()->user()->tenant;
        $assignments = TenantUnitAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->with([
                'property:id,name',
                'unit:id,property_id,unit_name',
            ])
            ->get()
            ->map(function ($assignment) {
                return [
                    'property_id' => $assignment->property_id,
                    'unit_id' => $assignment->unit_id,
                    'property_name' => $assignment->property?->name ?? __('Property'),
                    'unit_name' => $assignment->unit?->unit_name ?? __('Unit'),
                ];
            })
            ->values();

        if ($tenant->property_id && $tenant->unit_id && !$assignments->contains(function ($assignment) use ($tenant) {
            return (int) $assignment['property_id'] === (int) $tenant->property_id
                && (int) $assignment['unit_id'] === (int) $tenant->unit_id;
        })) {
            $assignments->push([
                'property_id' => $tenant->property_id,
                'unit_id' => $tenant->unit_id,
                'property_name' => $tenant->property?->name ?? __('Property'),
                'unit_name' => $tenant->unit?->unit_name ?? __('Unit'),
            ]);
        }

        $requests = $this->maintenanceRequestService
            ->getByPropertyId($assignments->pluck('property_id')->all())
            ->filter(function ($request) use ($assignments, $tenant) {
                if ((int) $request->property_id === (int) $tenant->property_id && (int) $request->unit_id === (int) $tenant->unit_id) {
                    return true;
                }

                return $assignments->contains(function ($assignment) use ($request) {
                    return (int) $assignment['property_id'] === (int) $request->property_id
                        && (int) $assignment['unit_id'] === (int) $request->unit_id;
                });
            })
            ->map(function ($request) {
                return [
                    'id' => $request->id,
                    'request_id' => $request->request_id,
                    'property_id' => $request->property_id,
                    'unit_id' => $request->unit_id,
                    'issue_id' => $request->issue_id,
                    'issue_name' => $request->issue_name,
                    'property_name' => $request->property_name,
                    'unit_name' => $request->unit_name,
                    'details' => $request->details,
                    'status' => (int) $request->status,
                    'updated_at' => Carbon::parse($request->updated_at)->format('Y-m-d'),
                ];
            })
            ->values();

        $issues = $this->maintenanceIssueService->getActiveAll()
            ->map(fn ($issue) => [
                'id' => $issue->id,
                'name' => $issue->name,
            ])
            ->values();

        return $this->success([
            'requests' => $requests,
            'issues' => $issues,
            'assignments' => $assignments,
        ]);
    }

    public function issues()
    {
        $issues = $this->maintenanceIssueService->getActiveAll()
            ->map(fn ($issue) => [
                'id' => $issue->id,
                'name' => $issue->name,
            ])
            ->values();

        return $this->success([
            'issues' => $issues,
        ]);
    }

    public function store(MaintenanceRequest $request)
    {
        return $this->maintenanceRequestService->store($request);
    }

    public function getInfo(Request $request)
    {
        $data = $this->maintenanceRequestService->getInfo($request->id);

        return $this->success([
            'request' => [
                'id' => $data->id,
                'request_id' => $data->request_id,
                'property_id' => $data->property_id,
                'unit_id' => $data->unit_id,
                'issue_id' => $data->issue_id,
                'issue_name' => $data->issue_name,
                'property_name' => $data->property_name,
                'unit_name' => $data->unit_name,
                'details' => $data->details,
                'status' => (int) $data->status,
                'attach' => str_contains((string) $data->attach, 'no-image.jpg') ? null : $data->attach,
                'invoice' => str_contains((string) $data->invoice, 'no-image.jpg') ? null : $data->invoice,
            ],
        ]);
    }

    public function delete($id)
    {
        return $this->maintenanceRequestService->deleteById($id);
    }
}
