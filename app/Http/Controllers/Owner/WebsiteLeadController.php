<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PublicPropertyBooking;
use App\Models\PublicPropertyWaitlist;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WebsiteLeadController extends Controller
{
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
