<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
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
        $ownerPropertyIds = $data['properties']->pluck('id');
        $data['unitsByPropertyId'] = PropertyUnit::query()
            ->whereIn('property_id', $ownerPropertyIds)
            ->whereNull('deleted_at')
            ->orderBy('property_id')
            ->orderBy('unit_name')
            ->get(['id', 'property_id', 'unit_name', 'max_occupancy'])
            ->groupBy('property_id');

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
            $this->appendBookingBillingState($data['records']);
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
            'property_id' => ['required', 'integer'],
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

        $property = Property::query()
            ->where('owner_user_id', getOwnerUserId())
            ->find((int) $validated['property_id']);

        if (!$property) {
            return back()->with('error', __('Invalid property selected.'));
        }

        $unit = PropertyUnit::query()
            ->where('property_id', $property->id)
            ->whereNull('deleted_at')
            ->find((int) $validated['unit_id']);

        if (!$unit) {
            return back()->with('error', __('Invalid unit selected for the chosen property.'));
        }

        $originalPropertyId = (int) $booking->property_id;
        $assignmentChanged = $this->tenantService->assignTenantToPrimaryUnit(
            $booking->tenant,
            (int) $property->id,
            (int) $unit->id,
            [
                'lease_start_date' => optional($booking->start_date)->toDateString(),
                'lease_end_date' => optional($booking->end_date)->toDateString(),
            ]
        );

        $booking->property_id = $property->id;
        if ((int) $property->id !== $originalPropertyId) {
            $booking->option_id = null;
        }
        $booking->property_unit_id = $unit->id;
        $booking->has_assignment = true;
        $booking->assignment_created = true;
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

    private function appendBookingBillingState($records): void
    {
        $tenantIds = $records->getCollection()
            ->pluck('tenant_id')
            ->filter()
            ->unique()
            ->values();

        if ($tenantIds->isEmpty()) {
            return;
        }

        $pendingInvoicesByTenant = Invoice::query()
            ->with(['property:id,name', 'propertyUnit:id,unit_name'])
            ->whereIn('tenant_id', $tenantIds)
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
            ->get()
            ->groupBy('tenant_id');

        $records->setCollection(
            $records->getCollection()->map(function (PublicPropertyBooking $record) use ($pendingInvoicesByTenant) {
                $invoice = $pendingInvoicesByTenant->get($record->tenant_id)?->first();

                $record->billing_status = $invoice ? 'pending_fee' : 'clear';
                $record->pending_invoice_summary = $invoice
                    ? [
                        'invoice_no' => $invoice->invoice_no ?: ('#' . $invoice->id),
                        'amount_due' => (float) ($invoice->amount ?? 0) + (
                            (int) $invoice->status === INVOICE_STATUS_OVER_DUE
                                ? (float) ($invoice->late_fee ?? 0)
                                : 0
                        ),
                        'due_date' => $invoice->due_date,
                        'property_name' => $invoice->property?->name,
                        'unit_name' => $invoice->propertyUnit?->unit_name,
                        'status' => (int) $invoice->status === INVOICE_STATUS_OVER_DUE ? 'overdue' : 'pending',
                    ]
                    : null;

                return $record;
            })
        );
    }
}
