<?php

namespace Tests;

use App\Enums\DocumentType as D;
use App\Enums\Role;
use App\Jobs\SendProcurementMail;
use App\Models\Approval;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ApprovalService;
use App\Services\DocumentService;
use App\Services\PurchaseOrderService;
use App\Services\ReceivingService;
use App\Services\RequisitionService;
use App\Services\SourcingService;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

abstract class ProcurementTestCase extends TestCase
{
    use RefreshDatabase;

    protected ?Tenant $procurementTenant = null;

    protected function beginProcurement(): void
    {
        Storage::fake('private');
        Mail::fake();
        Notification::fake();
        Queue::fake([SendProcurementMail::class]);
        $this->withoutVite();
        $this->procurementTenant = Tenant::create(['name' => 'Aero', 'slug' => 'aero']);
        TenantContext::set($this->procurementTenant);
    }

    protected function tearDown(): void
    {
        TenantContext::flush();
        parent::tearDown();
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $response = parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        if ($this->procurementTenant) {
            TenantContext::set($this->procurementTenant->fresh());
        }

        return $response;
    }

    protected function address(string $path): string
    {
        return 'http://aero.'.config('tenancy.domain').$path;
    }

    protected function person(Role $role): User
    {
        return User::where('role', $role)->first() ?? User::factory()->create(['role' => $role]);
    }

    protected function document(PurchaseOrder $order, D $type): PoDocument
    {
        $role = match ($type) {
            D::DigimaintEvidence, D::DeliveryNote => Role::StoreStaff,
            D::TelexCopy => Role::Treasury,
            default => Role::LogisticsOfficer,
        };

        return app(DocumentService::class)->upload($order, $type, UploadedFile::fake()->create($type->value.'.pdf', 1), $this->person($role));
    }

    protected function raisedOrder(bool $amo = false): PurchaseOrder
    {
        return app(RequisitionService::class)->raise('PR-1', $this->person(Role::Requester), [['part_number' => 'PART-1', 'description' => 'Aircraft bracket', 'quantity' => 2, 'condition' => 'new']], $amo);
    }

    protected function stockCheckedOrder(bool $available = false, bool $amo = false): PurchaseOrder
    {
        $order = $this->raisedOrder($amo);
        $line = $order->lines->first();
        $evidence = $this->document($order, D::DigimaintEvidence);
        $handover = app(ReceivingService::class)->recordHandover($order, $this->person(Role::StoreStaff), ['activity' => 'stock_check', 'po_line_id' => $line->id, 'transaction_reference' => 'DM-CHECK-1', 'po_document_id' => $evidence->id, 'quantity' => $available ? 2 : 0, 'performed_at' => now()->toIso8601String()]);
        app(ReceivingService::class)->verify($handover, $this->person(Role::StoreSupervisor), true, 'Quantity checked against DigiMaint');

        return app(RequisitionService::class)->checkStock($order, $this->person(Role::StoreStaff), [$line->id => $available]);
    }

    protected function quotedOrder(string $terms = 'net', bool $amo = false): PurchaseOrder
    {
        $order = $this->stockCheckedOrder(false, $amo);

        return $this->captureQuotesFor($order, $terms);
    }

    protected function captureQuotesFor(PurchaseOrder $order, string $terms = 'net'): PurchaseOrder
    {
        Vendor::factory()->approved()->count(3)->create(['payment_terms' => $terms]);
        app(SourcingService::class)->sendRfqs($order, $this->person(Role::LogisticsOfficer));
        foreach ($order->rfqs()->get() as $rfq) {
            (new SendProcurementMail($order->tenant_id, 'rfq', $rfq->id, $this->person(Role::LogisticsOfficer)->id))->handle();
        }
        $order->refresh();
        foreach ($order->rfqs()->get() as $index => $rfq) {
            $document = $this->document($order, D::Quotation);
            app(SourcingService::class)->captureQuote($order, $this->person(Role::LogisticsOfficer), $rfq, ['reference' => 'Q-'.$index, 'currency' => 'USD', 'po_document_id' => $document->id, 'reason' => 'Vendor response', 'lines' => [['po_line_id' => $order->lines->first()->id, 'unit_price' => (string) (10 + $index), 'condition' => 'new', 'warranty' => '12 months']]]);
            $order->refresh();
        }

        return $order;
    }

    protected function pendingEvaluation(string $terms = 'net', bool $amo = false): PurchaseOrder
    {
        $order = $this->quotedOrder($terms, $amo);
        foreach ([D::EndUserDocument, D::EvaluationSheet, D::ProvisioningRequisition, D::NegotiationEmail, D::Certificate] as $type) {
            $this->document($order, $type);
        }
        app(SourcingService::class)->evaluate($order, $this->person(Role::LogisticsOfficer), $order->quotes()->first()->id, 'Selected best compliant offer after comparing three vendors');

        return app(RequisitionService::class)->submitForApproval($order, $this->person(Role::LogisticsOfficer));
    }

    protected function approveSubject(Model $subject, string $stage): void
    {
        foreach (Approval::where('approvable_type', $subject->getMorphClass())->where('approvable_id', $subject->id)->where('stage', $stage)->where('is_active', true)->orderBy('step_order')->get() as $approval) {
            app(ApprovalService::class)->approve($approval, $this->person($approval->role));
        }
    }

    protected function sentOrder(string $terms = 'net'): PurchaseOrder
    {
        $order = $this->pendingEvaluation($terms);
        $this->approveSubject($order->evaluations()->first(), 'evaluation');
        $this->approveSubject($order->fresh(), 'cfo');
        $order->refresh();
        app(PurchaseOrderService::class)->issue($order, $this->person(Role::LogisticsOfficer));
        (new SendProcurementMail($order->tenant_id, 'purchase_order', $order->id, $this->person(Role::LogisticsOfficer)->id))->handle();

        return $order->fresh();
    }

    protected function acknowledgedOrder(string $terms = 'net'): PurchaseOrder
    {
        $order = $this->sentOrder($terms);
        $this->document($order, D::AcknowledgedPo);

        return app(RequisitionService::class)->markAcknowledged($order, $this->person(Role::LogisticsOfficer));
    }
}
