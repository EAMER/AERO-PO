<?php

namespace Tests\Feature;

use App\Enums\DocumentType as D;
use App\Enums\PurchaseOrderStatus as S;
use App\Enums\Role;
use App\Models\Approval;
use App\Models\Tenant;
use App\Models\User;
use App\Services\DocumentChecklistService;
use App\Services\DocumentService;
use App\Services\RequisitionService;
use App\Support\FakeStockChecker;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class DocumentChecklistTest extends TestCase
{
    use RefreshDatabase;

    private RequisitionService $requisitions;
    private DocumentService $documents;
    private DocumentChecklistService $checklist;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        TenantContext::set(Tenant::create(['name' => 'Aero', 'slug' => 'aero']));

        $this->requisitions = app(RequisitionService::class);
        $this->documents = app(DocumentService::class);
        $this->checklist = app(DocumentChecklistService::class);
    }

    protected function tearDown(): void
    {
        TenantContext::flush();
        parent::tearDown();
    }

    private function user(Role $role): User
    {
        return User::firstOrCreate(
            ['email' => $role->value.'@aero.test'],
            ['name' => $role->value, 'password' => 'password', 'role' => $role],
        );
    }

    private function poAtQuotesIn()
    {
        $po = $this->requisitions->raise('PO-1', $this->user(Role::Requester), [
            ['part_number' => 'SHORT-1', 'description' => 'Gasket', 'quantity' => 1],
        ]);
        $logistics = $this->user(Role::LogisticsOfficer);
        $po = $this->requisitions->checkStock($po, $this->user(Role::StoreStaff), new FakeStockChecker());
        $po = $this->requisitions->markRfqSent($po, $logistics);

        return $this->requisitions->markQuotesIn($po, $logistics);
    }

    private function uploadPack($po, int $quotations = 3): void
    {
        $logistics = $this->user(Role::LogisticsOfficer);

        $this->documents->upload($po, D::EndUserDocument, UploadedFile::fake()->create('enduser.pdf', 10), $logistics);
        $this->documents->upload($po, D::EvaluationSheet, UploadedFile::fake()->create('eval.pdf', 10), $logistics);
        $this->documents->upload($po, D::ProvisioningRequisition, UploadedFile::fake()->create('pr.pdf', 10), $logistics);
        $this->documents->upload($po, D::NegotiationEmail, UploadedFile::fake()->create('email.pdf', 10), $logistics);

        for ($i = 0; $i < $quotations; $i++) {
            $this->documents->upload($po, D::Quotation, UploadedFile::fake()->create("quote{$i}.pdf", 10), $logistics);
        }
    }

    public function test_submit_is_blocked_with_an_incomplete_pack(): void
    {
        $po = $this->poAtQuotesIn();
        $this->uploadPack($po, quotations: 2); // manual requires at least 3

        $this->expectException(RuntimeException::class);

        $this->requisitions->submitForApproval($po, $this->user(Role::LogisticsOfficer));
    }

    public function test_missing_lists_the_short_document_types(): void
    {
        $po = $this->poAtQuotesIn();
        $this->uploadPack($po, quotations: 1);

        $missing = $this->checklist->missing($po, 'evaluation_pack');

        $this->assertContains('quotation (1/3)', $missing);
        $this->assertFalse($this->checklist->isComplete($po, 'evaluation_pack'));
    }

    public function test_submit_succeeds_with_a_complete_pack_and_opens_the_approval_chain(): void
    {
        $po = $this->poAtQuotesIn();
        $this->uploadPack($po);

        $po = $this->requisitions->submitForApproval($po, $this->user(Role::LogisticsOfficer));

        $this->assertSame(S::PendingApproval, $po->status);
        $this->assertDatabaseHas('approvals', ['role' => 'tlm', 'stage' => 'evaluation', 'step_order' => 1]);
        $this->assertDatabaseHas('approvals', ['role' => 'technical_director', 'stage' => 'evaluation', 'step_order' => 2]);
    }

    public function test_amo_request_adds_the_hamo_step(): void
    {
        $po = $this->requisitions->raise('PO-1', $this->user(Role::Requester), [
            ['part_number' => 'SHORT-1', 'description' => 'Gasket', 'quantity' => 1],
        ], isAmoRequest: true);
        $logistics = $this->user(Role::LogisticsOfficer);
        $po = $this->requisitions->checkStock($po, $this->user(Role::StoreStaff), new FakeStockChecker());
        $po = $this->requisitions->markRfqSent($po, $logistics);
        $po = $this->requisitions->markQuotesIn($po, $logistics);
        $this->uploadPack($po);

        $this->requisitions->submitForApproval($po, $logistics);

        $this->assertDatabaseHas('approvals', ['role' => 'hamo', 'stage' => 'evaluation', 'step_order' => 2]);
    }

    public function test_uploaded_file_can_be_retrieved_from_the_private_disk(): void
    {
        $po = $this->poAtQuotesIn();
        $doc = $this->documents->upload($po, D::EndUserDocument, UploadedFile::fake()->create('enduser.pdf', 10), $this->user(Role::LogisticsOfficer));

        Storage::disk('private')->assertExists($doc->disk_path);
    }

    public function test_oversized_file_is_rejected(): void
    {
        $po = $this->poAtQuotesIn();

        $this->expectException(\InvalidArgumentException::class);

        $this->documents->upload(
            $po, D::EndUserDocument,
            UploadedFile::fake()->create('big.pdf', 21 * 1024), // > 20MB
            $this->user(Role::LogisticsOfficer),
        );
    }

    public function test_disallowed_file_type_is_rejected(): void
    {
        $po = $this->poAtQuotesIn();

        $this->expectException(\InvalidArgumentException::class);

        $this->documents->upload(
            $po, D::EndUserDocument,
            UploadedFile::fake()->create('script.exe', 10),
            $this->user(Role::LogisticsOfficer),
        );
    }
}
