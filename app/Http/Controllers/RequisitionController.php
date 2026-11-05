<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseOrderStatus;
use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\AmountChange;
use App\Models\Approval;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\QuoteEvaluation;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ApprovalService;
use App\Services\DocumentChecklistService;
use App\Services\ReceivingService;
use App\Services\RequisitionService;
use App\Services\ShippingService;
use App\Services\SourcingService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RequisitionController extends Controller
{
    public function __construct(private RequisitionService $requisitions, private DocumentChecklistService $checklist) {}

    public function index(Request $request): View
    {
        $filters = $request->validate(['search' => 'nullable|string|max:100', 'status' => ['nullable', Rule::enum(PurchaseOrderStatus::class)]]);
        $search = trim($filters['search'] ?? '');
        $orders = PurchaseOrder::with(['lines', 'requester'])
            ->when($request->user()->role === Role::Requester, fn ($q) => $q->where('requested_by', $request->user()->id))
            ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('reference', 'like', '%'.$search.'%')
                    ->orWhereHas('lines', fn ($q) => $q->where('part_number', 'like', '%'.$search.'%')->orWhere('description', 'like', '%'.$search.'%'));
            }))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status));

        return view('requisitions.index', ['pos' => $orders->latest()->paginate(15)->withQueryString(), 'statuses' => PurchaseOrderStatus::cases()]);
    }

    public function create(): View
    {
        $this->authorize('create', PurchaseOrder::class);

        return view('requisitions.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', PurchaseOrder::class);
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:100', 'not_regex:/[\r\n]/', Rule::unique('purchase_orders')->where('tenant_id', TenantContext::id())],
            'is_amo_request' => 'sometimes|boolean', 'lines' => 'required|array|min:1|max:100',
            'lines.*.part_number' => 'required|string|max:100', 'lines.*.description' => 'required|string|max:255',
            'lines.*.quantity' => 'required|integer|min:1|max:1000000', 'lines.*.condition' => 'nullable|string|max:50',
        ]);
        $po = $this->requisitions->raise($data['reference'], $request->user(), $data['lines'], $data['is_amo_request'] ?? false);

        return redirect()->route('requisitions.show', $po)->with('status', 'Requisition raised.');
    }

    public function show(PurchaseOrder $purchaseOrder, Request $request): View
    {
        $this->authorize('view', $purchaseOrder);
        $po = $purchaseOrder->load(['lines', 'documents.uploader', 'logs.actor', 'evaluations', 'rfqs.vendor', 'shipment.quotes.vendor', 'shipment.rfqs.vendor', 'invoice.steps', 'receipts.lines', 'handovers.recorder', 'handovers.verifier']);
        $subjects = [PurchaseOrder::class => [$po->id], QuoteEvaluation::class => $po->evaluations->modelKeys(), Invoice::class => $po->invoice ? [$po->invoice->id] : [], Shipment::class => $po->shipment ? [$po->shipment->id] : []];
        $approvals = Approval::with(['approvable', 'decidedBy', 'queries.asker', 'queries.target'])->where(function ($q) use ($subjects) {
            foreach ($subjects as $type => $ids) {
                if ($ids !== []) {
                    $q->orWhere(fn ($q) => $q->where('approvable_type', $type)->whereIn('approvable_id', $ids));
                }
            }
        })->orderBy('id')->get();
        $actionable = app(ApprovalService::class)->actionableFor($request->user())->pluck('id')->all();
        $users = User::where('is_active', true)->get();
        $targets = [];
        foreach ($approvals as $approval) {
            $chain = $approvals->where('approvable_type', $approval->approvable_type)->where('approvable_id', $approval->approvable_id)->where('stage', $approval->stage)->where('round', $approval->round);
            $targets[$approval->id] = $users->filter(fn ($u) => $u->id !== $request->user()->id && $chain->contains(fn ($step) => $step->role === $u->role && (! $step->assigned_to || $step->assigned_to === $u->id)));
        }

        return view('requisitions.show', [
            'po' => $po, 'checklist' => $this->checklist->statusFor($po, 'evaluation_pack'), 'comparison' => app(SourcingService::class)->comparison($po),
            'couriers' => Vendor::where('kind', 'courier')->where('is_approved', true)->where('is_active', true)->get(),
            'canSendPartsRfqs' => Vendor::where('kind', 'supplier')->where('is_approved', true)->where('is_active', true)->whereNotIn('id', $po->rfqs->where('kind', 'parts')->where('revision', $po->revision)->pluck('vendor_id'))->exists(),
            'canSendFreightRfqs' => Vendor::where('kind', 'freight_forwarder')->where('is_approved', true)->where('is_active', true)->whereNotIn('id', $po->rfqs->where('kind', 'freight')->pluck('vendor_id'))->exists(),
            'approvals' => $approvals, 'actionable' => $actionable, 'targetsByApproval' => $targets,
            'receivedQuantities' => app(ReceivingService::class)->receivedQuantities($po),
            'receivingChecklist' => $this->checklist->statusFor($po, 'receiving'),
            'shippingChecklist' => $po->total_weight_kg ? $this->checklist->statusFor($po, app(ShippingService::class)->isHeavyOrDangerous($po) ? 'shipping_heavy' : 'shipping_light') : [],
            'auditEntries' => ActivityLog::where('purchase_order_id', $po->id)->with('tenant')->latest('id')->limit(100)->get(),
            'amountChanges' => AmountChange::where('purchase_order_id', $po->id)->latest('id')->get(),
        ]);
    }

    public function checkStock(PurchaseOrder $purchaseOrder, Request $request): RedirectResponse
    {
        $data = $request->validate(['available' => 'sometimes|array', 'available.*' => 'boolean']);
        $availability = [];
        foreach ($purchaseOrder->lines as $line) {
            $availability[$line->id] = (bool) ($data['available'][$line->id] ?? false);
        }

        return $this->complete(fn () => $this->requisitions->checkStock($purchaseOrder, $request->user(), $availability));
    }

    public function markRfqSent(PurchaseOrder $purchaseOrder, Request $request): RedirectResponse
    {
        return $this->complete(fn () => $this->requisitions->markRfqSent($purchaseOrder, $request->user()));
    }

    public function markQuotesIn(PurchaseOrder $purchaseOrder, Request $request): RedirectResponse
    {
        return $this->complete(fn () => $this->requisitions->markQuotesIn($purchaseOrder, $request->user()));
    }

    public function submit(PurchaseOrder $purchaseOrder, Request $request): RedirectResponse
    {
        return $this->complete(fn () => $this->requisitions->submitForApproval($purchaseOrder, $request->user()));
    }

    public function setPaymentTerms(PurchaseOrder $purchaseOrder, Request $request): RedirectResponse
    {
        $data = $request->validate(['payment_terms' => 'required|in:net,cia']);

        return $this->complete(fn () => $this->requisitions->setPaymentTerms($purchaseOrder, $request->user(), $data['payment_terms']));
    }

    public function markAcknowledged(PurchaseOrder $purchaseOrder, Request $request): RedirectResponse
    {
        return $this->complete(fn () => $this->requisitions->markAcknowledged($purchaseOrder, $request->user()));
    }

    public function proceedToPayment(PurchaseOrder $purchaseOrder, Request $request): RedirectResponse
    {
        return $this->complete(fn () => $this->requisitions->proceedToPayment($purchaseOrder, $request->user()));
    }

    public function confirmPayment(PurchaseOrder $purchaseOrder, Request $request): RedirectResponse
    {
        $data = $request->validate(['payment_reference' => 'required|string|max:255']);

        return $this->complete(fn () => $this->requisitions->confirmPayment($purchaseOrder, $request->user(), $data['payment_reference']));
    }

    public function markReadyToShipOnNetTerms(PurchaseOrder $purchaseOrder, Request $request): RedirectResponse
    {
        return $this->complete(fn () => $this->requisitions->markReadyToShipOnNetTerms($purchaseOrder, $request->user()));
    }
}
