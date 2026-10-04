<?php

namespace Tests\Feature;

use App\Enums\DocumentType as D;
use App\Enums\PurchaseOrderStatus as S;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\RequisitionService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/** 7.1.4-7.1.8: acknowledgement, and both payment paths (net terms vs cash in advance). */
class PaymentPathTest extends TestCase
{
    use RefreshDatabase;

    private RequisitionService $requisitions;
    private DocumentService $documents;
    private User $logistics;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        TenantContext::set(Tenant::create(['name' => 'Aero', 'slug' => 'aero']));

        $this->requisitions = app(RequisitionService::class);
        $this->documents = app(DocumentService::class);
        $this->logistics = User::create(['name' => 'L', 'email' => 'l@aero.test', 'password' => 'x', 'role' => Role::LogisticsOfficer]);
    }

    protected function tearDown(): void
    {
        TenantContext::flush();
        parent::tearDown();
    }

    /** A PO sitting at po_sent, as if it had already cleared approvals. */
    private function poAtPoSent()
    {
        $po = $this->requisitions->raise('PO-1', $this->logistics, [
            ['part_number' => 'SHORT-1', 'description' => 'Gasket', 'quantity' => 1],
        ]);
        $po->forceFill(['status' => S::PoSent])->save();

        return $po->fresh();
    }

    public function test_payment_terms_must_be_net_or_cia(): void
    {
        $po = $this->poAtPoSent();

        $this->expectException(InvalidArgumentException::class);

        $this->requisitions->setPaymentTerms($po, $this->logistics, 'crypto');
    }

    public function test_acknowledgement_is_blocked_without_payment_terms(): void
    {
        $po = $this->poAtPoSent();
        $this->documents->upload($po, D::AcknowledgedPo, UploadedFile::fake()->create('ack.pdf', 10), $this->logistics);

        $this->expectException(RuntimeException::class);

        $this->requisitions->markAcknowledged($po, $this->logistics);
    }

    public function test_acknowledgement_is_blocked_without_the_document(): void
    {
        $po = $this->poAtPoSent();
        $this->requisitions->setPaymentTerms($po, $this->logistics, 'net');

        $this->expectException(RuntimeException::class);

        $this->requisitions->markAcknowledged($po, $this->logistics);
    }

    public function test_net_terms_goes_straight_to_ready_to_ship(): void
    {
        $po = $this->poAtPoSent();
        $this->requisitions->setPaymentTerms($po, $this->logistics, 'net');
        $this->documents->upload($po, D::AcknowledgedPo, UploadedFile::fake()->create('ack.pdf', 10), $this->logistics);
        $po = $this->requisitions->markAcknowledged($po, $this->logistics);

        $this->assertSame(S::Acknowledged, $po->status);

        $po = $this->requisitions->markReadyToShipOnNetTerms($po, $this->logistics);

        $this->assertSame(S::ReadyToShip, $po->status);
    }

    public function test_net_terms_po_cannot_use_the_cia_payment_path(): void
    {
        $po = $this->poAtPoSent();
        $this->requisitions->setPaymentTerms($po, $this->logistics, 'net');
        $this->documents->upload($po, D::AcknowledgedPo, UploadedFile::fake()->create('ack.pdf', 10), $this->logistics);
        $po = $this->requisitions->markAcknowledged($po, $this->logistics);

        $this->expectException(RuntimeException::class);

        $this->requisitions->proceedToPayment($po, $this->logistics);
    }

    public function test_cia_path_requires_proforma_and_telex_before_confirming_payment(): void
    {
        $po = $this->poAtPoSent();
        $this->requisitions->setPaymentTerms($po, $this->logistics, 'cia');
        $this->documents->upload($po, D::AcknowledgedPo, UploadedFile::fake()->create('ack.pdf', 10), $this->logistics);
        $po = $this->requisitions->markAcknowledged($po, $this->logistics);
        $po = $this->requisitions->proceedToPayment($po, $this->logistics);

        $this->assertSame(S::AwaitingPayment, $po->status);

        $this->expectException(RuntimeException::class);

        $this->requisitions->confirmPayment($po, $this->logistics);
    }

    public function test_cia_path_reaches_ready_to_ship_once_payment_is_confirmed(): void
    {
        $po = $this->poAtPoSent();
        $this->requisitions->setPaymentTerms($po, $this->logistics, 'cia');
        $this->documents->upload($po, D::AcknowledgedPo, UploadedFile::fake()->create('ack.pdf', 10), $this->logistics);
        $po = $this->requisitions->markAcknowledged($po, $this->logistics);
        $po = $this->requisitions->proceedToPayment($po, $this->logistics);

        $this->documents->upload($po, D::ProformaInvoice, UploadedFile::fake()->create('pfi.pdf', 10), $this->logistics);
        $this->documents->upload($po, D::TelexCopy, UploadedFile::fake()->create('telex.pdf', 10), $this->logistics);

        $po = $this->requisitions->confirmPayment($po, $this->logistics);

        $this->assertSame(S::ReadyToShip, $po->status);
    }
}
