<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\User;
use App\Services\UnitManagementService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class UnitController extends Controller
{
    public function __construct(
        private readonly UnitManagementService $unitManagementService = new UnitManagementService()
    ) {
    }

    public function index(Request $request)
    {
        $data['pageTitle'] = __('Unit Oversight');
        $data['navUnitActiveClass'] = 'active';
        $data['owners'] = User::query()
            ->where('role', USER_ROLE_OWNER)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'email']);
        $data['properties'] = Property::query()
            ->when($request->filled('owner_id'), function ($query) use ($request) {
                $query->where('owner_user_id', (int) $request->owner_id);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'owner_user_id']);

        $units = $this->unitManagementService->getAdminUnits([
            'owner_user_id' => $request->filled('owner_id') ? (int) $request->owner_id : null,
            'property_ids' => $request->filled('property_id') ? [(int) $request->property_id] : [],
            'search' => $request->get('search'),
        ]);

        if ($request->filled('status')) {
            $status = (string) $request->status;
            $units = $units->filter(function ($unit) use ($status) {
                return $unit->manual_availability_status === $status
                    || $unit->occupancy_state === $status
                    || ($status === 'available' && $unit->is_available_for_assignment);
            })->values();
        }

        $data['units'] = $this->paginateCollection($units, 20, $request)->withQueryString();

        return view('admin.units.index')->with($data);
    }

    public function show($id)
    {
        $data['pageTitle'] = __('Unit History');
        $data['navUnitActiveClass'] = 'active';
        $data = array_merge($data, $this->unitManagementService->getAdminUnitDetail((int) $id));

        return view('admin.units.show')->with($data);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'manual_availability_status' => ['required', 'in:active,on_hold,off_market'],
            'manual_status_reason' => ['nullable', 'string', 'max:2000'],
            'max_occupancy' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $this->unitManagementService->updateAdminUnitSettings((int) $id, $validated);

            return redirect()->back()->with('success', __(UPDATED_SUCCESSFULLY));
        } catch (\Exception $e) {
            return redirect()->back()->with('error', getErrorMessage($e, $e->getMessage()));
        }
    }

    private function paginateCollection(Collection $items, int $perPage, Request $request): LengthAwarePaginator
    {
        $page = max((int) $request->get('page', 1), 1);
        $offset = ($page - 1) * $perPage;

        return new LengthAwarePaginator(
            $items->slice($offset, $perPage)->values(),
            $items->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );
    }
}
