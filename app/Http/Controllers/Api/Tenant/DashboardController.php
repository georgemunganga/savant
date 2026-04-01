<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\MaintenanceRequest;
use App\Models\NoticeBoard;
use App\Models\Property;
use App\Models\PropertyUnit;
use App\Models\Ticket;
use App\Traits\ResponseTrait;
use Carbon\Carbon;

class DashboardController extends Controller
{
    use ResponseTrait;

    private function mapInvoiceStatus(int $status): string
    {
        return (int) $status === INVOICE_STATUS_PAID ? 'PAID' : 'UNPAID';
    }

    private function mapTicketStatus(int $status): string
    {
        return match ((int) $status) {
            TICKET_STATUS_INPROGRESS, TICKET_STATUS_REOPEN => 'PENDING',
            TICKET_STATUS_CLOSE, TICKET_STATUS_RESOLVED => 'CLOSED',
            default => 'OPEN',
        };
    }

    private function invoicePeriod($invoice): string
    {
        if (!empty($invoice->name)) {
            return $invoice->name;
        }

        return Carbon::parse($invoice->due_date)->format('F Y');
    }

    public function dashboard()
    {
        $tenantUser = auth()->user()->tenant;
        $today = Carbon::today();
        $property = Property::select(['name', 'thumbnail_image', 'description'])->findOrFail($tenantUser->property_id);
        $unit = PropertyUnit::select(['unit_name'])->findOrFail($tenantUser->unit_id);
        $invoices = Invoice::query()
            ->where('tenant_id', $tenantUser->id)
            ->orderByDesc('due_date')
            ->get();
        $tickets = Ticket::query()
            ->where('unit_id', $tenantUser->unit_id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();
        $maintenanceBase = MaintenanceRequest::query()
            ->where('owner_user_id', auth()->user()->owner_user_id)
            ->where('property_id', $tenantUser->property_id)
            ->where('unit_id', $tenantUser->unit_id);
        $notices = NoticeBoard::query()
            ->where(function ($q) use ($tenantUser) {
                $q->where('unit_id', $tenantUser->unit_id)
                    ->orWhere('unit_all', ACTIVE);
            })
            ->where('start_date', '<=', $today->toDateString())
            ->where('end_date', '>=', $today->toDateString())
            ->orderByDesc('id')
            ->select(['id', 'title', 'details', 'start_date', 'end_date'])
            ->get();
        $openInvoices = $invoices->filter(fn ($invoice) => (int) $invoice->status !== INVOICE_STATUS_PAID);
        $nextInvoice = $openInvoices->sortBy('due_date')->first();

        $data['property'] = $property;
        $data['unit'] = $unit;
        $data['tenant'] = [
            'general_rent' => $tenantUser->general_rent,
        ];
        $data['invoices'] = $invoices;
        $data['totalTickets'] = $tickets->count();
        $data['today'] = $today->toDateString();
        $data['notices'] = $notices;
        $data['webapp'] = [
            'activeStay' => $property->name,
            'unitName' => $unit->unit_name,
            'nextPayment' => $nextInvoice ? Carbon::parse($nextInvoice->due_date)->format('d M Y') : 'No unpaid invoice',
            'nextInvoiceId' => $nextInvoice?->id ?? 0,
            'openInvoices' => $openInvoices->count(),
            'openInvoiceAmount' => (float) $openInvoices->sum('amount'),
            'openRequests' => (clone $maintenanceBase)->where('status', MAINTENANCE_REQUEST_STATUS_PENDING)->count(),
            'scheduledRequests' => (clone $maintenanceBase)->where('status', MAINTENANCE_REQUEST_STATUS_INPROGRESS)->count(),
            'recentInvoices' => $invoices->take(3)->map(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'period' => $this->invoicePeriod($invoice),
                    'status' => $this->mapInvoiceStatus((int) $invoice->status),
                ];
            })->values(),
            'tickets' => $tickets->take(3)->map(function ($ticket) {
                return [
                    'id' => $ticket->id,
                    'title' => $ticket->title,
                    'status' => $this->mapTicketStatus((int) $ticket->status),
                ];
            })->values(),
            'notices' => $notices->take(3)->map(function ($notice) {
                return [
                    'id' => $notice->id,
                    'title' => $notice->title,
                    'publishedAt' => Carbon::parse($notice->start_date)->format('d M Y'),
                ];
            })->values(),
        ];

        return $this->success($data);
    }
}
