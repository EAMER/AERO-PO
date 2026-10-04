<?php

namespace Tests\Feature;

use App\Enums\DocumentType as D;
use App\Enums\PurchaseOrderStatus as S;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\RequisitionService;
use App\Services\ShippingService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/** 7.1.9-7.1.20: dispatch, delivery, receipt, GRN, Cardex, and close-out. */
class ShippingAndReceivingTest extends TestCase
{
    use RefreshDatabase;

    private ShippingService $shipping;
    private DocumentService $documents;
    private User $logistics;
    private User $store;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        TenantContext::set(Tenant::create(['name' => 'Aero', 'slug' => 'aero']));

        $this->shipping = app(ShippingService::class);
        $this->documents = app(DocumentService::class);
        $this->logistics = User::create(['name' => 'L', 'email' => 'l@aero.test', 'password' => 'x', 'role' => Role::LogisticsOfficer]);
        $this->store = User::create(['name' => 'S', 'email' => 's@aero.test', 'password' => 'x', 'role' => Role::StoreStaff]);
    }

    protected function tearDown(): void
    {
        TenantContext::flush();
        parent::tearDown();
    }

    private function poAtReadyToShip()
    {
        $po = app(RequisitionService::class)->raise('PO-1', $this->logistics, [
            ['part_number' => 'SHORT-1', 'description' => 'Gasket', 'quantity' => 1],
        ]);
        $po->forceFill(['status' => S::ReadyToShip])->save();

        return $po->fresh();
    }

    public function test_light_route_requires_customs_duty_and_its_documents(): void
    {
        $po = $this->poAtReadyToShip();
        $this->shipping->recordShipmentDetails($po, $this->logistics, 10, false);

        $this->assertFalse($this->shipping->isHeavyOrDangerous($po->fresh()));

        $this->expectException(RuntimeException::class);

        $this->shipping->dispatch($po->fresh(), $this->logistics, 'AWB-1');
    }

    public function test_light_route_dispatch_succeeds_once_complete(): void
    {
        $po = $this->poAtReadyToShip();
        $this->shipping->recordShipmentDetails($po, $this->logistics, 10, false);
        $po = $po->fresh();
        $this->shipping->markCustomsDutyPaid($po, $this->logistics);
        $this->documents->upload($po, D::CustomsDutyReceipt, UploadedFile::fake()->create('duty.pdf', 10), $this->logistics);
        $this->documents->upload($po, D::AirwayBill, UploadedFile::fake()->create('awb.pdf', 10), $this->logistics);

        $po = $this->shipping->dispatch($po->fresh(), $this->logistics, 'AWB-123');

        $this->assertSame(S::InTransit, $po->status);
        $this->assertSame('AWB-123', $po->awb_number);
    }

    public function test_heavy_route_does_not_need_customs_duty_but_needs_commercial_invoice(): void
    {
        $po = $this->poAtReadyToShip();
        $this->shipping->recordShipmentDetails($po, $this->logistics, 80, false); // over 50kg -> heavy route
        $po = $po->fresh();

        $this->assertTrue($this->shipping->isHeavyOrDangerous($po));

        $this->expectException(RuntimeException::class);

        $this->shipping->dispatch($po, $this->logistics, 'AWB-1');
    }

    public function test_heavy_route_dispatch_succeeds_once_complete(): void
    {
        $po = $this->poAtReadyToShip();
        $this->shipping->recordShipmentDetails($po, $this->logistics, 5, true); // DG flag alone makes it heavy
        $po = $po->fresh();
        $this->documents->upload($po, D::CommercialInvoice, UploadedFile::fake()->create('ci.pdf', 10), $this->logistics);
        $this->documents->upload($po, D::AirwayBill, UploadedFile::fake()->create('awb.pdf', 10), $this->logistics);

        $po = $this->shipping->dispatch($po->fresh(), $this->logistics, 'AWB-999');

        $this->assertSame(S::InTransit, $po->status);
    }

    public function test_full_receiving_flow_reaches_closed(): void
    {
        $po = $this->poAtReadyToShip();
        $this->shipping->recordShipmentDetails($po, $this->logistics, 10, false);
        $po = $po->fresh();
        $this->shipping->markCustomsDutyPaid($po, $this->logistics);
        $this->documents->upload($po, D::CustomsDutyReceipt, UploadedFile::fake()->create('duty.pdf', 10), $this->logistics);
        $this->documents->upload($po, D::AirwayBill, UploadedFile::fake()->create('awb.pdf', 10), $this->logistics);
        $po = $this->shipping->dispatch($po->fresh(), $this->logistics, 'AWB-1');

        $po = $this->shipping->markDelivered($po, $this->logistics);
        $this->assertSame(S::Delivered, $po->status);

        $po = $this->shipping->confirmReceipt($po, $this->store);
        $this->assertSame(S::Received, $po->status);

        $po = $this->shipping->issueGrn($po, $this->store, 'GRN-001');
        $this->assertSame(S::GrnIssued, $po->status);
        $this->assertSame('GRN-001', $po->grn_number);
    }

    public function test_close_out_is_blocked_without_label_and_cardex(): void
    {
        $po = $this->poAtReadyToShip();
        $po->forceFill(['status' => S::GrnIssued, 'grn_number' => 'GRN-002'])->save();

        $this->expectException(RuntimeException::class);

        $this->shipping->closeOut($po->fresh(), $this->logistics);
    }

    public function test_close_out_succeeds_and_records_the_digimaint_handover(): void
    {
        $po = $this->poAtReadyToShip();
        $po->forceFill(['status' => S::GrnIssued, 'grn_number' => 'GRN-003'])->save();
        $po = $po->fresh();

        $this->shipping->markServiceableLabelApplied($po, $this->store);
        $this->shipping->markCardexSent($po->fresh(), $this->logistics);

        $po = $this->shipping->closeOut($po->fresh(), $this->logistics);

        $this->assertSame(S::Closed, $po->status);
        $this->assertNotNull($po->digimaint_synced_at);
    }

    public function test_an_empty_awb_number_is_rejected(): void
    {
        $po = $this->poAtReadyToShip();
        $this->shipping->recordShipmentDetails($po, $this->logistics, 10, false);
        $po = $po->fresh();
        $this->shipping->markCustomsDutyPaid($po, $this->logistics);
        $this->documents->upload($po, D::CustomsDutyReceipt, UploadedFile::fake()->create('duty.pdf', 10), $this->logistics);
        $this->documents->upload($po, D::AirwayBill, UploadedFile::fake()->create('awb.pdf', 10), $this->logistics);

        $this->expectException(InvalidArgumentException::class);

        $this->shipping->dispatch($po->fresh(), $this->logistics, '  ');
    }

    public function test_fulfilled_from_stock_closes_without_any_shipping(): void
    {
        $po = app(RequisitionService::class)->raise('PO-2', $this->logistics, [
            ['part_number' => 'INSTOCK-1', 'description' => 'Bracket', 'quantity' => 1],
        ]);
        $po->forceFill(['status' => S::FulfilledFromStock])->save();

        $po = $this->shipping->closeFulfilledFromStock($po->fresh(), $this->logistics);

        $this->assertSame(S::Closed, $po->status);
        $this->assertNotNull($po->digimaint_synced_at);
    }
}
