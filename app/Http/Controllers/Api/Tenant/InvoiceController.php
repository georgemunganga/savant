<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\GatewayService;
use App\Services\InvoiceService;
use App\Services\TenantService;
use App\Traits\ResponseTrait;
use Carbon\Carbon;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    use ResponseTrait;
    public $invoiceService;
    public $tenantService;
    public $gatewayService;

    public function __construct()
    {
        $this->invoiceService = new InvoiceService;
        $this->tenantService = new TenantService();
        $this->gatewayService = new GatewayService;
    }

    public function index()
    {
        $data['invoices'] = $this->invoiceService->getByTenantId(auth()->user()->tenant->id);
        return $this->success($data);
    }

    public function details($id)
    {
        $data['invoice'] = $this->invoiceService->getByIdCheckTenantAuthId($id);
        $data['items'] = $this->invoiceService->getItemsByInvoiceId($id);
        $data['tenant'] = $this->tenantService->getDetailsById($data['invoice']->tenant_id);
        $data['order'] = Order::query()
            ->leftJoin('gateways', 'orders.gateway_id', '=', 'gateways.id')
            ->leftJoin('file_managers', function ($join) {
                $join->on('orders.deposit_slip_id', '=', 'file_managers.id')
                    ->where('file_managers.origin_type', '=', 'App\Models\Order');
            })
            ->where('orders.id', $data['invoice']->order_id)
            ->select([
                'orders.*',
                'gateways.title as gatewayTitle',
                'gateways.slug as gatewaySlug',
                'file_managers.folder_name',
                'file_managers.file_name',
            ])
            ->first();

        $lineItems = $data['items']->map(function ($item) {
            return [
                'title' => $item->description ?? __('Line Item'),
                'amount' => (float) $item->amount + (float) ($item->tax_amount ?? 0),
            ];
        })->values();

        $data['webapp'] = [
            'id' => $data['invoice']->id,
            'invoiceNo' => $data['invoice']->invoice_no ?? ('#' . $data['invoice']->id),
            'period' => $data['invoice']->name ?: Carbon::parse($data['invoice']->due_date)->format('F Y'),
            'dueDate' => Carbon::parse($data['invoice']->due_date)->format('Y-m-d'),
            'amount' => (float) $data['invoice']->amount,
            'status' => (int) $data['invoice']->status === INVOICE_STATUS_PAID ? 'PAID' : 'UNPAID',
            'lineItems' => $lineItems,
            'paymentMethod' => $data['order']->gatewayTitle ?? null,
            'transactionId' => $data['order']->transaction_id ?? null,
            'depositBy' => $data['order']->deposit_by ?? null,
            'proofUrl' => !empty($data['order']?->folder_name) && !empty($data['order']?->file_name)
                ? getFileUrl($data['order']->folder_name, $data['order']->file_name)
                : null,
        ];

        return $this->success($data);
    }

    public function pay($id)
    {
        $data['invoice'] = $this->invoiceService->getByIdCheckTenantAuthId($id);
        $data['items'] = $this->invoiceService->getItemsByInvoiceId($id)->load('invoiceType');
        $data['gateways'] = $this->gatewayService->getActiveAllWithCurrencies(auth()->user()->owner_user_id);
        $data['banks'] = $this->gatewayService->getActiveBanks();

        $lineItems = $data['items']->map(function ($item) {
            $amount = (float) $item->amount + (float) ($item->tax_amount ?? 0);

            return [
                'title' => $item->invoiceType?->name ?? $item->description ?? __('Line Item'),
                'amount' => $amount,
            ];
        })->values();

        if ($data['invoice']->due_date < date('Y-m-d') && (float) $data['invoice']->late_fee > 0) {
            $lineItems->push([
                'title' => __('Late Fee'),
                'amount' => (float) $data['invoice']->late_fee,
            ]);
        }

        $data['webapp'] = [
            'id' => $data['invoice']->id,
            'status' => (int) $data['invoice']->status === INVOICE_STATUS_PAID ? 'PAID' : 'UNPAID',
            'total' => (float) $data['invoice']->amount + (($data['invoice']->due_date < date('Y-m-d')) ? (float) $data['invoice']->late_fee : 0),
            'dueDate' => Carbon::parse($data['invoice']->due_date)->format('Y-m-d'),
            'lineItems' => $lineItems,
            'gateways' => collect($data['gateways'])->map(function ($gateway) {
                return [
                    'id' => $gateway->id,
                    'slug' => $gateway->slug,
                    'label' => $gateway->title,
                    'requiresBankDetails' => $gateway->slug === 'bank',
                    'currencies' => collect($gateway->currencies ?? [])->map(function ($currency) {
                        return [
                            'code' => $currency->currency,
                            'label' => trim($currency->currency . ' ' . ($currency->symbol ?? '')),
                            'symbol' => $currency->symbol ?? null,
                        ];
                    })->values(),
                ];
            })->values(),
            'banks' => collect($data['banks'])->map(function ($bank) {
                return [
                    'id' => $bank->id,
                    'name' => $bank->name,
                    'details' => $bank->details,
                ];
            })->values(),
        ];

        return $this->success($data);
    }

    public function getCurrencyByGateway(Request $request)
    {
        $data = $this->invoiceService->getCurrencyByGatewayId($request->id);
        return $this->success($data);
    }
}
