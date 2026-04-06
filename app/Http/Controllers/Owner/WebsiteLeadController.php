<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyUnit;
use App\Models\PublicPropertyBooking;
use App\Models\PublicPropertyWaitlist;
use App\Services\TenantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WebsiteLeadController extends Controller
{
    public function __construct(
        private readonly TenantService $tenantService = new TenantService()
    ) {
    }

    public function index(Request $request): View
    {
        $activeTab = $request->get('tab', 'bookings') === 'waitlist' ? 'waitlist' : 'bookings';
        $ownerUserId = getOwnerUserId();

        $data['pageTitle'] = __('Website Leads');
        $data['navTenantMMShowClass'] = 'mm-show';
        $data['subNavWebsiteLeadsMMActiveClass'] = 'mm-active';
        $data['subNavWebsiteLeadsActiveClass'] = 'active';
        $data['activeTab'] = $activeTab;
        $data['filters'] = [
            'search' => trim((string) $request->get('search', '')),
            'property_id' => (string) $request->get('property_id', ''),
            'status' => (string) $request->get('status', ''),
        ];

        $data['properties'] = Property::query()
            ->where('owner_user_id', $ownerUserId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $data['bookingCount'] = PublicPropertyBooking::query()
            ->where('owner_user_id', $ownerUserId)
            ->count();

        $data['waitlistCount'] = PublicPropertyWaitlist::query()
            ->whereHas('property', function ($query) use ($ownerUserId) {
                $query->where('owner_user_id', $ownerUserId);
            })
            ->count();

        $data['bookingStatuses'] = PublicPropertyBooking::STATUSES;
        $data['waitlistStatuses'] = PublicPropertyWaitlist::STATUSES;

        if ($activeTab === 'waitlist') {
            $query = PublicPropertyWaitlist::query()
                ->with(['property:id,name', 'option:id,rental_kind,property_id,property_unit_id'])
                ->whereHas('property', function ($propertyQuery) use ($ownerUserId) {
                    $propertyQuery->where('owner_user_id', $ownerUserId);
                })
                ->latest();

            $this->applyCommonFilters($query, $data['filters']);

            $data['records'] = $query->paginate(12)->withQueryString();
        } else {
            $query = PublicPropertyBooking::query()
                ->with([
                    'property:id,name',
                    'option:id,rental_kind,property_id,property_unit_id',
                    'unit:id,unit_name',
                    'tenant:id,user_id,property_id,unit_id',
                ])
                ->where('owner_user_id', $ownerUserId)
                ->latest('confirmed_at')
                ->latest();

            $this->applyCommonFilters($query, $data['filters']);

            $data['records'] = $query->paginate(12)->withQueryString();
            $propertyIds = $data['records']->getCollection()->pluck('property_id')->filter()->unique()->values();
            $data['unitsByPropertyId'] = PropertyUnit::query()
                ->whereIn('property_id', $propertyIds)
                ->whereNull('deleted_at')
                ->orderBy('unit_name')
                ->get(['id', 'property_id', 'unit_name', 'max_occupancy'])
                ->groupBy('property_id');
        }

        return view('owner.website-leads.index', $data);
    }

    public function updateBookingStatus(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:' . implode(',', PublicPropertyBooking::STATUSES)],
        ]);

        $booking = PublicPropertyBooking::query()
            ->where('owner_user_id', getOwnerUserId())
            ->findOrFail($id);

        $booking->status = $validated['status'];
        $booking->save();

        return back()->with('success', __('Booking status updated successfully.'));
    }

    public function assignBookingUnit(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'unit_id' => ['required', 'integer'],
        ]);

        $booking = PublicPropertyBooking::query()
            ->with(['tenant.user', 'property', 'unit'])
            ->where('owner_user_id', getOwnerUserId())
            ->findOrFail($id);

        if (!$booking->tenant) {
            return back()->with('error', __('This booking is not linked to a tenant account.'));
        }

        if (in_array($booking->status, [
            PublicPropertyBooking::STATUS_CANCELLED,
            PublicPropertyBooking::STATUS_COMPLETED,
        ], true)) {
            return back()->with('error', __('This booking can no longer be assigned from the leads page.'));
        }

        if ($booking->has_assignment && !is_null($booking->property_unit_id)) {
            return back()->with('error', __('This booking already has an assigned unit.'));
        }

        $unit = PropertyUnit::query()
            ->where('property_id', $booking->property_id)
            ->whereNull('deleted_at')
            ->findOrFail((int) $validated['unit_id']);

        $assignmentChanged = $this->tenantService->assignTenantToPrimaryUnit(
            $booking->tenant,
            (int) $booking->property_id,
            (int) $unit->id,
            [
                'lease_start_date' => optional($booking->start_date)->toDateString(),
                'lease_end_date' => optional($booking->end_date)->toDateString(),
            ]
        );

        $booking->property_unit_id = $unit->id;
        $booking->has_assignment = true;
        $booking->assignment_created = $booking->assignment_created || $assignmentChanged;
        $booking->save();

        return back()->with('success', __('Tenant assigned to unit successfully.'));
    }

    public function updateWaitlistStatus(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:' . implode(',', PublicPropertyWaitlist::STATUSES)],
        ]);

        $waitlist = PublicPropertyWaitlist::query()
            ->whereHas('property', function ($query) {
                $query->where('owner_user_id', getOwnerUserId());
            })
            ->findOrFail($id);

        $waitlist->status = $validated['status'];
        $waitlist->save();

        return back()->with('success', __('Waiting list status updated successfully.'));
    }

    private function applyCommonFilters($query, array $filters): void
    {
        if ($filters['property_id'] !== '') {
            $query->where('property_id', (int) $filters['property_id']);
        }

        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if ($filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(function ($searchQuery) use ($search) {
                $searchQuery
                    ->where('full_name', 'like', '%' . $search . '%')
                    ->orWhere('email', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%');
            });
        }
    }
}
