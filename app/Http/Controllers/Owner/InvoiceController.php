<?php

namespace App\Http\Controllers\Owner;

use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Controller;
use App\Http\Requests\InvoiceRequest;
use App\Http\Requests\PaymentStatusRequest;
use App\Http\Requests\NotificationRequest;
use App\Models\Bank;
use App\Models\EmailTemplate;
use App\Models\Gateway;
use App\Models\GatewayCurrency;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceType;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantUnitAssignment;
use App\Services\GatewayService;
use App\Services\InvoiceService;
use App\Services\Payment\Payment;
use App\Services\PropertyService;
use App\Services\SmsMail\MailService;
use App\Services\TenantService;
use App\Traits\ResponseTrait;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    use ResponseTrait;

    public $invoiceService;
    public $tenantService;
    public $propertyService;

    public function __construct()
    {
        $this->invoiceService = new InvoiceService();
        $this->tenantService = new TenantService();
        $this->propertyService = new PropertyService;
    }

    public function index(Request $request)
    {
        if ($request->ajax()) {
            return $this->invoiceService->getAllInvoicesData($request);
        } else {
            $responseData = $this->invoiceService->getAllInvoices();
            $gatewayService = new GatewayService();
            $responseData['properties'] = $this->propertyService->getAll();
            $responseData['gateways'] = $gatewayService->getActiveAll(getOwnerUserId());
            $responseData['banks'] = Bank::where('owner_user_id', getOwnerUserId())->where('status', ACTIVE)->get();;
            $responseData['invoiceTenants'] = Tenant::query()
                ->join('users', 'tenants.user_id', '=', 'users.id')
                ->whereNull('users.deleted_at')
                ->where('tenants.owner_user_id', getOwnerUserId())
                ->whereIn('tenants.status', [TENANT_STATUS_ACTIVE, TENANT_STATUS_DRAFT])
                ->select([
                    'tenants.id',
                    'tenants.property_id',
                    'tenants.unit_id',
                    'users.first_name',
                    'users.last_name',
                    'users.email',
                ])
                ->orderBy('users.first_name')
                ->orderBy('users.last_name')
                ->get();
            $responseData['tenantAssignments'] = TenantUnitAssignment::query()
                ->join('tenants', 'tenant_unit_assignments.tenant_id', '=', 'tenants.id')
                ->where('tenants.owner_user_id', getOwnerUserId())
                ->whereIn('tenants.status', [TENANT_STATUS_ACTIVE, TENANT_STATUS_DRAFT])
                ->select([
                    'tenant_unit_assignments.tenant_id',
                    'tenant_unit_assignments.property_id',
                    'tenant_unit_assignments.unit_id',
                ])
                ->get();
            return view('owner.invoice.index')->with($responseData);
        }
    }

    public function paidInvoiceIndex(Request $request)
    {
        if ($request->ajax()) {
            return $this->invoiceService->getPaidInvoicesData($request);
        }
    }

    public function pendingInvoiceIndex(Request $request)
    {
        if ($request->ajax()) {
            return $this->invoiceService->getPendingInvoicesData($request);
        }
    }

    public function bankPendingInvoice(Request $request)
    {
        if ($request->ajax()) {
            return $this->invoiceService->getBankPendingInvoicesData($request);
        }
    }

    public function overDueInvoiceIndex(Request $request)
    {
        if ($request->ajax()) {
            return $this->invoiceService->getOverDueInvoicesData($request);
        }
    }

    public function details($id)
    {
        $data['invoice'] = $this->invoiceService->getById($id);

        if ($data['invoice']->due_date < date('Y-m-d')) {
            $tenant = Tenant::findOrFail($data['invoice']->tenant_id);
            if ($tenant->late_fee_type == TYPE_PERCENTAGE) {
                $lateFeeAmount = $data['invoice']->amount * $tenant->late_fee * 0.01;
            } else {
                $lateFeeAmount =  $tenant->late_fee;
            }
            $data['invoice']->update(['late_fee' => $lateFeeAmount]);
        }

        $data['items'] = $this->invoiceService->getItemsByInvoiceId($id);
        $data['tenant'] = $this->tenantService->getDetailsById($data['invoice']->tenant_id);
        $data['order'] = $this->invoiceService->getOrderById($data['invoice']->order_id);
        $data['owner'] = $this->invoiceService->ownerInfo(auth()->id());
        return $this->success($data);
    }

    public function print($id)
    {
        $data['invoice'] = $this->invoiceService->getById($id);
        $data['items'] = $this->invoiceService->getItemsByInvoiceId($id);
        $data['owner'] = $this->invoiceService->ownerInfo(getOwnerUserId());
        $data['tenant'] = $this->tenantService->getDetailsById($data['invoice']->tenant_id);
        $data['order'] = $this->invoiceService->getOrderById($data['invoice']->order_id);
        return view('tenant.invoices.print', $data);
    }

    public function pay(Request $request, $id)
    {
        $request->validate([
            'gateway_id' => 'required',
            'currency_id' => 'required',
            'transactionId' => 'required',
        ]);

        $invoice = $this->invoiceService->getById($id);
        $gateway = Gateway::where(['owner_user_id' => getOwnerUserId(), 'id' => $request->gateway_id, 'status' => ACTIVE])->firstOrFail();
        $gatewayCurrency = GatewayCurrency::where(['owner_user_id' => getOwnerUserId(), 'gateway_id' => $gateway->id, 'id' => $request->currency_id])->firstOrFail();

        $payment = new PaymentController();
        if ($gateway->slug == 'bank') {
            $bank = Bank::where(['gateway_id' => $gateway->id, 'id' => $request->bank_id])->firstOrFail();
            $bank_id = $bank->id;
            $bank_name = $bank->name;
            $bank_account_number = $bank->bank_account_number;
            $order = $payment->placeOrder($invoice, $gateway, $gatewayCurrency, $bank_id, $bank_name, $bank_account_number);
        } else {
            $order = $payment->placeOrder($invoice, $gateway, $gatewayCurrency);
        }

        DB::beginTransaction();
        try {
            $order->payment_id = $request->transactionId;
            $order->payment_status = INVOICE_STATUS_PAID;
            $order->save();
            $invoice = Invoice::find($order->invoice_id);
            $invoice->status = INVOICE_STATUS_PAID;
            $invoice->order_id = $order->id;
            $invoice->save();
            DB::commit();
            return $this->success([], __('Payment Successful!'));
        } catch (Exception $e) {
            DB::rollBack();
            return $this->error([], __('Payment Failed!'));
        }
    }

    public function store(InvoiceRequest $request)
    {
        return $this->invoiceService->store($request);
    }

    public function paymentStatus(PaymentStatusRequest $request)
    {
        return $this->invoiceService->paymentStatusChange($request);
    }

    public function destroy($id)
    {
        return $this->invoiceService->destroy($id);
    }

    public function types()
    {
        $invoiceTypes = $this->invoiceService->types();
        return $this->success($invoiceTypes);
    }

    public function sendNotification(NotificationRequest $request)
    {
        try {
            if ($request->notification_type == NOTIFICATION_TYPE_SINGLE) {
                return $this->invoiceService->sendSingleNotification($request);
            } elseif ($request->notification_type == NOTIFICATION_TYPE_MULTIPLE) {
                return $this->invoiceService->sendMultiNotification($request);
            }
        } catch (Exception $e) {
            return $this->error([]);
        }
    }

    public function getCurrencyByGateway(Request $request)
    {
        $currencies = GatewayCurrency::where('owner_user_id', getOwnerUserId())->where('gateway_id', $request->id)->get();
        foreach ($currencies as $currency) {
            $currency->symbol = $currency->symbol;
        }
        $data = $currencies?->makeHidden(['created_at', 'updated_at', 'deleted_at', 'gateway_id', 'owner_user_id']);

        return $this->success($data);
    }

    public function rentArrears(Request $request)
    {
        $data['pageTitle'] = __('Rent Arrears');
        $data['properties'] = $this->propertyService->getAll();
        $data['filters'] = [
            'property_id' => $request->get('property_id', ''),
            'unit_id' => $request->get('unit_id', ''),
            'month' => $request->get('month', ''),
            'start_date' => $request->get('start_date', ''),
            'end_date' => $request->get('end_date', ''),
        ];

        $request->merge($data['filters']);
        $request->validate([
            'property_id' => 'nullable|integer',
            'unit_id' => 'nullable|integer',
            'month' => 'nullable|integer|between:1,12',
            'start_date' => 'nullable|date|required_with:end_date',
            'end_date' => 'nullable|date|required_with:start_date|after_or_equal:start_date',
        ]);

        $rows = [];
        $summary = [
            'missing_count' => 0,
            'unpaid_count' => 0,
            'estimated_due' => 0,
            'estimated_penalty' => 0,
        ];

        $data['hasDateFilter'] = !empty($data['filters']['start_date']) && !empty($data['filters']['end_date']);
        if ($data['hasDateFilter']) {
            [$rows, $summary] = $this->buildRentArrearsRows(
                Carbon::parse($data['filters']['start_date']),
                Carbon::parse($data['filters']['end_date']),
                $data['filters']['property_id'] ?: null,
                $data['filters']['unit_id'] ?: null,
                $data['filters']['month'] ? (int) $data['filters']['month'] : null
            );
        }

        $data['rows'] = $rows;
        $data['summary'] = $summary;

        return view('owner.invoice.arrears', $data);
    }

    public function generateRentArrearsInvoices(Request $request)
    {
        $request->validate([
            'entries' => 'required|array|min:1',
            'entries.*' => 'required|string',
        ]);

        DB::beginTransaction();
        try {
            $invoiceType = $this->resolveRentInvoiceType();
            if (!$invoiceType) {
                throw new Exception(__('Please add at least one active invoice type before generating invoices'));
            }

            $created = 0;
            $skipped = 0;
            foreach ($request->entries as $encodedEntry) {
                $entry = json_decode(base64_decode($encodedEntry), true);
                if (!$entry || !isset($entry['tenant_id'], $entry['property_id'], $entry['unit_id'], $entry['year'], $entry['month'])) {
                    $skipped++;
                    continue;
                }

                $tenant = Tenant::query()
                    ->where('owner_user_id', getOwnerUserId())
                    ->whereIn('status', [TENANT_STATUS_ACTIVE, TENANT_STATUS_DRAFT])
                    ->where('id', (int) $entry['tenant_id'])
                    ->first();
                if (!$tenant) {
                    $skipped++;
                    continue;
                }

                $year = (int) $entry['year'];
                $month = (int) $entry['month'];
                $propertyId = (int) $entry['property_id'];
                $unitId = (int) $entry['unit_id'];
                $monthDate = Carbon::create($year, $month, 1);
                $monthEnd = $monthDate->copy()->endOfMonth();

                $alreadyExists = Invoice::query()
                    ->where('owner_user_id', getOwnerUserId())
                    ->where('tenant_id', $tenant->id)
                    ->where('property_id', $propertyId)
                    ->where('property_unit_id', $unitId)
                    ->whereYear('due_date', $year)
                    ->whereMonth('due_date', $month)
                    ->exists();

                if ($alreadyExists) {
                    $skipped++;
                    continue;
                }

                $rentAmount = (float) $tenant->general_rent;
                $taxAmount = $this->calculateTaxAmount($rentAmount);
                $penaltyAmount = $this->calculatePenaltyAmount($tenant, $rentAmount, $monthEnd);

                $invoice = new Invoice();
                $invoice->name = 'INV';
                $invoice->tenant_id = $tenant->id;
                $invoice->owner_user_id = getOwnerUserId();
                $invoice->property_id = $propertyId;
                $invoice->property_unit_id = $unitId;
                $invoice->month = month($month);
                $invoice->due_date = $monthEnd->toDateString();
                $invoice->amount = $rentAmount;
                $invoice->tax_amount = $taxAmount;
                $invoice->late_fee = $penaltyAmount;
                $invoice->save();

                $invoiceItem = new InvoiceItem();
                $invoiceItem->invoice_id = $invoice->id;
                $invoiceItem->invoice_type_id = $invoiceType->id;
                $invoiceItem->amount = $rentAmount;
                $invoiceItem->tax_amount = $taxAmount;
                $invoiceItem->description = __('Rent for :month', ['month' => $monthDate->format('F Y')]);
                $invoiceItem->save();

                $created++;
            }

            DB::commit();
            if ($created > 0 && $skipped > 0) {
                return redirect()->route('owner.invoice.rent-arrears')->with('success', __(':created invoices created, :skipped skipped (already existed/invalid).', ['created' => $created, 'skipped' => $skipped]));
            }
            if ($created > 0) {
                return redirect()->route('owner.invoice.rent-arrears')->with('success', __(':created invoices created successfully.', ['created' => $created]));
            }
            return redirect()->route('owner.invoice.rent-arrears')->with('error', __('No invoice was created. Selected rows may already have invoices.'));
        } catch (Exception $e) {
            DB::rollBack();
            return redirect()->route('owner.invoice.rent-arrears')->with('error', getErrorMessage($e, $e->getMessage()));
        }
    }

    public function sendRentArrearsReminder(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'nullable|string',
            'tenant_ids' => 'required|array|min:1',
            'tenant_ids.*' => 'required|integer',
        ]);

        try {
            $tenantIds = collect($request->tenant_ids)->map(fn($id) => (int) $id)->unique()->values()->toArray();
            $tenants = Tenant::query()
                ->join('users', 'tenants.user_id', '=', 'users.id')
                ->whereNull('users.deleted_at')
                ->where('tenants.owner_user_id', getOwnerUserId())
                ->whereIn('tenants.status', [TENANT_STATUS_ACTIVE, TENANT_STATUS_DRAFT])
                ->whereIn('tenants.id', $tenantIds)
                ->select(['tenants.id as tenant_id', 'users.id as user_id', 'users.email'])
                ->get();

            if ($tenants->isEmpty()) {
                return redirect()->route('owner.invoice.rent-arrears')->with('error', __('No valid tenants found for reminder.'));
            }

            $mailService = new MailService();
            $title = $request->title;
            $body = $request->body ?: __('Please review your outstanding rent and make payment as soon as possible.');
            $ownerUserId = getOwnerUserId();

            foreach ($tenants as $tenant) {
                addNotification($title, $body, null, null, $tenant->user_id, $ownerUserId);

                if (getOption('send_email_status', 0) == ACTIVE && !empty($tenant->email)) {
                    $emails = [$tenant->email];
                    $template = EmailTemplate::where('owner_user_id', $ownerUserId)
                        ->where('category', EMAIL_TEMPLATE_REMINDER)
                        ->where('status', ACTIVE)
                        ->first();

                    if ($template) {
                        $content = getEmailTemplate($template->body, [
                            '{{app_name}}' => getOption('app_name'),
                        ]);
                        $mailService->sendCustomizeMail($emails, $template->subject, $content);
                    } else {
                        $mailService->sendReminderMail($emails, $title, $body, $ownerUserId);
                    }
                }
            }

            return redirect()->route('owner.invoice.rent-arrears')->with('success', __('Reminder sent successfully.'));
        } catch (Exception $e) {
            return redirect()->route('owner.invoice.rent-arrears')->with('error', getErrorMessage($e, $e->getMessage()));
        }
    }

    private function buildRentArrearsRows(Carbon $startDate, Carbon $endDate, ?int $propertyId, ?int $unitId, ?int $specificMonth = null): array
    {
        $monthStart = $startDate->copy()->startOfMonth();
        $monthEnd = $endDate->copy()->startOfMonth();
        $period = CarbonPeriod::create($monthStart, '1 month', $monthEnd);

        $assignments = TenantUnitAssignment::query()
            ->join('tenants', 'tenant_unit_assignments.tenant_id', '=', 'tenants.id')
            ->join('users', 'tenants.user_id', '=', 'users.id')
            ->join('properties', 'tenant_unit_assignments.property_id', '=', 'properties.id')
            ->join('property_units', 'tenant_unit_assignments.unit_id', '=', 'property_units.id')
            ->where('tenants.owner_user_id', getOwnerUserId())
            ->whereNull('users.deleted_at')
            ->whereIn('tenants.status', [TENANT_STATUS_ACTIVE, TENANT_STATUS_DRAFT])
            ->when($propertyId, function ($q) use ($propertyId) {
                $q->where('tenant_unit_assignments.property_id', $propertyId);
            })
            ->when($unitId, function ($q) use ($unitId) {
                $q->where('tenant_unit_assignments.unit_id', $unitId);
            })
            ->select([
                'tenant_unit_assignments.tenant_id',
                'tenant_unit_assignments.property_id',
                'tenant_unit_assignments.unit_id',
                'tenant_unit_assignments.created_at as assigned_at',
                'tenants.general_rent',
                'tenants.late_fee',
                'tenants.late_fee_type',
                'tenants.created_at as tenant_created_at',
                'users.first_name',
                'users.last_name',
                'users.email',
                'properties.name as property_name',
                'property_units.unit_name',
            ])
            ->get();

        $directAssignments = Tenant::query()
            ->join('users', 'tenants.user_id', '=', 'users.id')
            ->join('properties', 'tenants.property_id', '=', 'properties.id')
            ->join('property_units', 'tenants.unit_id', '=', 'property_units.id')
            ->where('tenants.owner_user_id', getOwnerUserId())
            ->whereNull('users.deleted_at')
            ->whereIn('tenants.status', [TENANT_STATUS_ACTIVE, TENANT_STATUS_DRAFT])
            ->whereNotNull('tenants.property_id')
            ->whereNotNull('tenants.unit_id')
            ->when($propertyId, function ($q) use ($propertyId) {
                $q->where('tenants.property_id', $propertyId);
            })
            ->when($unitId, function ($q) use ($unitId) {
                $q->where('tenants.unit_id', $unitId);
            })
            ->select([
                'tenants.id as tenant_id',
                'tenants.property_id',
                'tenants.unit_id',
                DB::raw('NULL as assigned_at'),
                'tenants.general_rent',
                'tenants.late_fee',
                'tenants.late_fee_type',
                'tenants.created_at as tenant_created_at',
                'users.first_name',
                'users.last_name',
                'users.email',
                'properties.name as property_name',
                'property_units.unit_name',
            ])
            ->get();

        $assignmentMap = [];
        foreach ($assignments as $assignment) {
            $key = $assignment->tenant_id . '-' . $assignment->property_id . '-' . $assignment->unit_id;
            $assignmentMap[$key] = $assignment;
        }
        foreach ($directAssignments as $assignment) {
            $key = $assignment->tenant_id . '-' . $assignment->property_id . '-' . $assignment->unit_id;
            if (!isset($assignmentMap[$key])) {
                $assignmentMap[$key] = $assignment;
            }
        }

        $assignments = collect(array_values($assignmentMap));

        $rows = [];
        $summary = [
            'missing_count' => 0,
            'unpaid_count' => 0,
            'estimated_due' => 0,
            'estimated_penalty' => 0,
        ];

        foreach ($assignments as $assignment) {
            $eligibilityStartDate = null;
            if (!empty($assignment->assigned_at)) {
                $eligibilityStartDate = Carbon::parse($assignment->assigned_at)->startOfMonth();
            } elseif (!empty($assignment->tenant_created_at)) {
                $eligibilityStartDate = Carbon::parse($assignment->tenant_created_at)->startOfMonth();
            }

            foreach ($period as $periodDate) {
                if ($eligibilityStartDate && $periodDate->copy()->startOfMonth()->lt($eligibilityStartDate)) {
                    continue;
                }

                if (!is_null($specificMonth) && (int) $periodDate->month !== $specificMonth) {
                    continue;
                }

                $invoiceList = Invoice::query()
                    ->where('owner_user_id', getOwnerUserId())
                    ->where('tenant_id', $assignment->tenant_id)
                    ->where('property_id', $assignment->property_id)
                    ->where('property_unit_id', $assignment->unit_id)
                    ->whereYear('due_date', $periodDate->year)
                    ->whereMonth('due_date', $periodDate->month)
                    ->select(['id', 'status', 'amount', 'tax_amount', 'late_fee', 'invoice_no'])
                    ->get();

                $hasPaidInvoice = $invoiceList->contains(function ($invoice) {
                    return (int) $invoice->status === INVOICE_STATUS_PAID;
                });
                if ($hasPaidInvoice) {
                    continue;
                }

                $unpaidInvoice = $invoiceList->first(function ($invoice) {
                    return in_array((int) $invoice->status, [INVOICE_STATUS_PENDING, INVOICE_STATUS_OVER_DUE], true);
                });

                $rentAmount = (float) $assignment->general_rent;
                $taxAmount = $this->calculateTaxAmount($rentAmount);
                $monthEndDate = Carbon::create($periodDate->year, $periodDate->month, 1)->endOfMonth();
                $penaltyAmount = $this->calculatePenaltyAmount((object) [
                    'late_fee' => $assignment->late_fee,
                    'late_fee_type' => $assignment->late_fee_type,
                ], $rentAmount, $monthEndDate);

                $status = 'missing';
                $invoiceId = null;
                $estimatedTotal = $rentAmount + $taxAmount + $penaltyAmount;
                if ($unpaidInvoice) {
                    $status = 'unpaid';
                    $invoiceId = $unpaidInvoice->id;
                    $estimatedTotal = (float) $unpaidInvoice->amount + (float) $unpaidInvoice->tax_amount + (float) $unpaidInvoice->late_fee;
                }

                $entryPayload = base64_encode(json_encode([
                    'tenant_id' => (int) $assignment->tenant_id,
                    'property_id' => (int) $assignment->property_id,
                    'unit_id' => (int) $assignment->unit_id,
                    'year' => (int) $periodDate->year,
                    'month' => (int) $periodDate->month,
                ]));

                $rows[] = [
                    'tenant_id' => (int) $assignment->tenant_id,
                    'tenant_name' => trim($assignment->first_name . ' ' . $assignment->last_name),
                    'tenant_email' => $assignment->email,
                    'property_name' => $assignment->property_name,
                    'unit_name' => $assignment->unit_name,
                    'month_label' => $periodDate->format('F Y'),
                    'status' => $status,
                    'invoice_id' => $invoiceId,
                    'rent_amount' => $rentAmount,
                    'tax_amount' => $taxAmount,
                    'penalty_amount' => $penaltyAmount,
                    'estimated_total' => $estimatedTotal,
                    'can_generate' => $status === 'missing',
                    'entry_payload' => $entryPayload,
                ];

                if ($status === 'missing') {
                    $summary['missing_count']++;
                } else {
                    $summary['unpaid_count']++;
                }
                $summary['estimated_due'] += $estimatedTotal;
                $summary['estimated_penalty'] += $penaltyAmount;
            }
        }

        return [$rows, $summary];
    }

    private function calculateTaxAmount(float $rentAmount): float
    {
        $tax = taxSetting(getOwnerUserId());
        if (!isset($tax)) {
            return 0.0;
        }

        if ((int) $tax->type === TAX_TYPE_PERCENTAGE) {
            return round(($rentAmount * (float) $tax->amount) / 100, 2);
        }

        return round((float) $tax->amount, 2);
    }

    private function calculatePenaltyAmount(object $tenant, float $rentAmount, Carbon $monthEndDate): float
    {
        if (now()->startOfDay()->lte($monthEndDate->startOfDay())) {
            return 0.0;
        }

        if ((int) $tenant->late_fee_type === TYPE_PERCENTAGE) {
            return round(($rentAmount * (float) $tenant->late_fee) / 100, 2);
        }

        return round((float) $tenant->late_fee, 2);
    }

    private function resolveRentInvoiceType(): ?InvoiceType
    {
        return InvoiceType::query()
            ->where('owner_user_id', getOwnerUserId())
            ->active()
            ->orderByRaw("CASE WHEN LOWER(name) LIKE '%rent%' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->first();
    }
}
