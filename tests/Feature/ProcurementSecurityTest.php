<?php

namespace Tests\Feature;

use App\Enums\DocumentType as D;
use App\Enums\PurchaseOrderStatus as S;
use App\Enums\Role;
use App\Jobs\SendProcurementMail;
use App\Mail\ProcurementMessage;
use App\Models\ActivityLog;
use App\Models\AmountChange;
use App\Models\Invoice;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vendor;
use App\Policies\PurchaseOrderPolicy;
use App\Policies\VendorPolicy;
use App\Services\SourcingService;
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ProcurementTestCase;

class ProcurementSecurityTest extends ProcurementTestCase
{
    public function test_tenant_admin_creates_users_with_an_encrypted_one_time_password_display(): void
    {
        $this->beginProcurement();
        $this->actingAs($this->person(Role::TenantAdmin));

        $response = $this->post($this->address('/users'), ['name' => 'New requester', 'email' => 'new@example.test', 'role' => 'requester']);

        $response->assertRedirect()->assertSessionHas('encrypted_temporary_password');
        $password = Crypt::decryptString(session('encrypted_temporary_password'));
        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertTrue(Hash::check($password, $user->password));
        $this->assertSame(Role::Requester, $user->role);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $user->id, 'subject_type' => User::class, 'action' => 'user.created']);
        $this->assertStringNotContainsString($password, ActivityLog::latest('id')->first()->toJson());
    }

    public function test_requesters_cannot_queue_supplier_rfqs(): void
    {
        $this->beginProcurement();
        $order = $this->raisedOrder();

        $this->actingAs($this->person(Role::Requester))->postJson($this->address('/requisitions/'.$order->id.'/actions/rfqs'), ['kind' => 'parts'])->assertForbidden();

        Queue::assertNothingPushed();
        Mail::assertNothingSent();
    }

    public function test_a_loaded_model_cannot_be_written_from_another_tenant_context(): void
    {
        $this->beginProcurement();
        $vendor = Vendor::factory()->create(['name' => 'Original name']);
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('outside its tenant context');

        TenantContext::runAs($other, fn () => $vendor->update(['name' => 'Foreign write']));
    }

    public function test_an_inactive_account_cannot_log_in(): void
    {
        $this->beginProcurement();
        $user = User::factory()->create(['is_active' => false]);

        $this->post($this->address('/login'), ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_net_terms_finance_cannot_start_before_stores_receipt(): void
    {
        $this->beginProcurement();
        $order = $this->commercialFixture($this->raisedOrder());
        $order->forceFill(['status' => S::Acknowledged, 'payment_terms' => 'net', 'po_approved_at' => now()])->save();
        $document = $this->document($order, D::CommercialInvoice);

        $this->actingAs($this->person(Role::Payables))->postJson($this->address('/requisitions/'.$order->id.'/actions/invoice'), ['reference' => 'TOO-EARLY', 'po_document_id' => $document->id, 'amount' => '20.00', 'currency' => 'USD', 'reason' => 'Early payment request'])->assertUnprocessable()->assertJsonValidationErrors('workflow');

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_treasury_cannot_skip_the_invoice_approval_chain(): void
    {
        $this->beginProcurement();
        $order = PurchaseOrder::factory()->create(['status' => S::AwaitingPayment, 'payment_terms' => 'cia']);
        $invoice = Invoice::factory()->create(['purchase_order_id' => $order->id, 'status' => 'pending_approval']);
        $this->document($order, D::TelexCopy);

        $this->actingAs($this->person(Role::Treasury))->postJson($this->address('/requisitions/'.$order->id.'/actions/pay'), ['payment_reference' => 'SKIP-APPROVAL'])->assertUnprocessable()->assertJsonValidationErrors('workflow');

        $this->assertNull($invoice->fresh()->paid_at);
        $this->assertSame(S::AwaitingPayment, $order->fresh()->status);
    }

    public function test_guests_cannot_register_themselves_into_a_tenant(): void
    {
        $this->beginProcurement();

        $this->get($this->address('/register'))->assertNotFound();
        $this->post($this->address('/register'), ['name' => 'Uninvited', 'email' => 'uninvited@example.test', 'password' => 'password', 'password_confirmation' => 'password'])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'uninvited@example.test']);
    }

    public function test_guest_procurement_requests_redirect_to_the_tenant_login(): void
    {
        $this->beginProcurement();

        $this->get($this->address('/vendors'))->assertRedirect($this->address('/login'));
    }

    public function test_requesters_cannot_read_another_requesters_order_or_documents(): void
    {
        $this->beginProcurement();
        $order = $this->raisedOrder();
        $document = $this->document($order, D::Quotation);
        $other = User::factory()->create(['role' => Role::Requester]);

        $this->actingAs($other)->get($this->address('/requisitions/'.$order->id))->assertForbidden();
        $this->get($this->address('/documents/'.$document->id.'/download'))->assertForbidden();
        $this->get($this->address('/requisitions'))->assertOk()->assertDontSee($order->reference);
    }

    public function test_cross_tenant_routes_do_not_reveal_foreign_records(): void
    {
        $this->beginProcurement();
        $otherTenant = Tenant::create(['name' => 'Other', 'slug' => 'other']);
        [$order, $document, $vendor] = TenantContext::runAs($otherTenant, function () {
            $order = PurchaseOrder::factory()->create();

            return [$order, PoDocument::factory()->create(['purchase_order_id' => $order->id]), Vendor::factory()->create()];
        });
        $this->actingAs($this->person(Role::TenantAdmin));

        $this->get($this->address('/requisitions/'.$order->id))->assertNotFound();
        $this->get($this->address('/documents/'.$document->id.'/download'))->assertNotFound();
        $this->patch($this->address('/vendors/'.$vendor->id), [])->assertNotFound();
    }

    public static function rolePermissions(): array
    {
        return array_combine(array_map(fn ($role) => $role->value, Role::cases()), array_map(fn ($role) => [$role, in_array($role, [Role::LogisticsOfficer, Role::TenantAdmin, Role::SuperAdmin], true), in_array($role, [Role::Tlm, Role::TenantAdmin, Role::SuperAdmin], true)], Role::cases()));
    }

    #[DataProvider('rolePermissions')]
    public function test_policies_apply_the_role_matrix(Role $role, bool $canSource, bool $canApproveVendor): void
    {
        $this->beginProcurement();
        $actor = $this->person($role);
        $order = PurchaseOrder::factory()->create(['requested_by' => $actor->id]);
        $vendor = Vendor::factory()->create();

        $this->assertSame($canSource, (new PurchaseOrderPolicy)->source($actor, $order));
        $this->assertSame($canApproveVendor, (new VendorPolicy)->approve($actor, $vendor));
    }

    public function test_vendor_creation_ignores_injected_approval_and_requires_tlm_approval(): void
    {
        $this->beginProcurement();
        $this->actingAs($this->person(Role::LogisticsOfficer));

        $this->post($this->address('/vendors'), ['name' => 'Supplier', 'email' => 'supplier@example.test', 'kind' => 'supplier', 'payment_terms' => 'net', 'is_approved' => true, 'tenant_id' => 999])->assertRedirect();

        $vendor = Vendor::firstOrFail();
        $this->assertFalse($vendor->is_approved);
        $this->assertSame($this->procurementTenant->id, $vendor->tenant_id);
        $this->post($this->address('/vendors/'.$vendor->id.'/approval'), ['is_approved' => true])->assertForbidden();
        $this->actingAs($this->person(Role::Tlm))->post($this->address('/vendors/'.$vendor->id.'/approval'), ['is_approved' => true])->assertRedirect();
        $this->assertTrue($vendor->fresh()->is_approved);
    }

    public function test_requisition_payload_cannot_set_status_amount_or_owner_tenant(): void
    {
        $this->beginProcurement();
        $requester = $this->person(Role::Requester);

        $this->actingAs($requester)->post($this->address('/requisitions'), ['reference' => 'SAFE-PR', 'tenant_id' => 999, 'requested_by' => 999, 'status' => 'closed', 'total_minor' => 999999, 'lines' => [['part_number' => 'P1', 'description' => 'Bracket', 'quantity' => 2]]])->assertRedirect();

        $order = PurchaseOrder::firstOrFail();
        $this->assertSame(S::RequisitionRaised, $order->status);
        $this->assertSame(0, $order->total_minor);
        $this->assertSame($requester->id, $order->requested_by);
        $this->assertSame($this->procurementTenant->id, $order->tenant_id);
    }

    public function test_awaiting_cash_payment_cannot_switch_to_net_terms(): void
    {
        $this->beginProcurement();
        $order = PurchaseOrder::factory()->create(['status' => S::AwaitingPayment, 'payment_terms' => 'cia']);
        $this->actingAs($this->person(Role::LogisticsOfficer));

        $this->postJson($this->address('/requisitions/'.$order->id.'/payment-terms'), ['payment_terms' => 'net'])->assertUnprocessable()->assertJsonValidationErrors('workflow');
        $this->postJson($this->address('/requisitions/'.$order->id.'/ready-to-ship'))->assertUnprocessable();

        $this->assertSame('cia', $order->fresh()->payment_terms);
        $this->assertSame(S::AwaitingPayment, $order->fresh()->status);
    }

    public function test_logistics_cannot_confirm_a_payment_as_treasury(): void
    {
        $this->beginProcurement();
        $order = PurchaseOrder::factory()->create(['status' => S::AwaitingPayment, 'payment_terms' => 'cia']);
        $invoice = Invoice::factory()->create(['purchase_order_id' => $order->id, 'status' => 'approved']);

        $this->actingAs($this->person(Role::LogisticsOfficer))->postJson($this->address('/requisitions/'.$order->id.'/actions/pay'), ['payment_reference' => 'UNAUTHORIZED'])->assertForbidden();

        $this->assertNull($invoice->fresh()->paid_at);
    }

    public function test_submitted_approval_evidence_cannot_be_deleted(): void
    {
        $this->beginProcurement();
        $order = $this->pendingEvaluation();
        $document = $order->documents()->where('type', D::EvaluationSheet)->first();

        $this->actingAs($this->person(Role::LogisticsOfficer))->deleteJson($this->address('/documents/'.$document->id))->assertUnprocessable()->assertJsonValidationErrors('workflow');

        $this->assertModelExists($document);
        Storage::disk('private')->assertExists($document->disk_path);
        $this->assertSame(S::PendingApproval, $order->fresh()->status);
    }

    public function test_a_renamed_script_is_not_accepted_as_a_pdf(): void
    {
        $this->beginProcurement();
        $order = $this->raisedOrder();
        $fixture = UploadedFile::fake()->createWithContent('quotation.pdf', '<?php echo "not a PDF";');
        $file = new UploadedFile($fixture->getPathname(), 'quotation.pdf', 'application/pdf', null, true);

        $this->actingAs($this->person(Role::LogisticsOfficer))->postJson($this->address('/requisitions/'.$order->id.'/documents'), ['type' => 'quotation', 'file' => $file])->assertUnprocessable()->assertJsonValidationErrors('workflow');

        $this->assertSame(0, $order->documents()->count());
    }

    public function test_duplicate_references_and_emails_produce_validation_errors(): void
    {
        $this->beginProcurement();
        $order = $this->raisedOrder();
        $requester = $this->person(Role::Requester);

        $this->actingAs($requester)->postJson($this->address('/requisitions'), ['reference' => $order->reference, 'lines' => [['part_number' => 'P1', 'description' => 'Part', 'quantity' => 1]]])->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->actingAs($this->person(Role::TenantAdmin))->postJson($this->address('/users'), ['name' => 'Duplicate', 'email' => $requester->email, 'role' => 'requester'])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertSame(1, PurchaseOrder::count());
    }

    public function test_profile_email_uniqueness_is_scoped_to_the_current_tenant(): void
    {
        $this->beginProcurement();
        $otherTenant = Tenant::create(['name' => 'Other', 'slug' => 'other']);
        TenantContext::runAs($otherTenant, fn () => User::factory()->create(['email' => 'shared@example.test']));
        $requester = $this->person(Role::Requester);

        $this->actingAs($requester)->patch($this->address('/profile'), ['name' => 'Updated', 'email' => 'shared@example.test'])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('shared@example.test', $requester->fresh()->email);
    }

    public function test_queued_mail_restores_tenant_context_and_uses_the_correct_vendor(): void
    {
        $this->beginProcurement();
        $order = $this->stockCheckedOrder();
        $vendor = Vendor::factory()->approved()->create();
        $rfq = app(SourcingService::class)->sendRfqs($order, $this->person(Role::LogisticsOfficer))->first();
        $actorId = $this->person(Role::LogisticsOfficer)->id;
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other']);

        TenantContext::runAs($other, function () use ($order, $rfq, $actorId, $other) {
            (new SendProcurementMail($order->tenant_id, 'rfq', $rfq->id, $actorId))->handle();
            $this->assertSame($other->id, TenantContext::id());
        });

        Mail::assertSent(ProcurementMessage::class, fn ($mail) => $mail->hasTo($vendor->email));
        Queue::assertPushed(SendProcurementMail::class, fn ($job) => $job->tenantId === $order->tenant_id);
        $this->assertSame('sent', $rfq->fresh()->status);
    }

    public function test_vendor_names_are_escaped_in_pages_and_outbound_html(): void
    {
        $this->beginProcurement();
        $name = '<script>alert("vendor")</script>';
        Vendor::factory()->create(['name' => $name]);

        $this->actingAs($this->person(Role::LogisticsOfficer))->get($this->address('/vendors'))->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee($name, false);
        $html = (new ProcurementMessage('RFQ', $name))->render();

        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString($name, $html);
    }

    public function test_amount_history_is_immutable(): void
    {
        $this->beginProcurement();
        $change = AmountChange::factory()->create();
        $this->expectException(\LogicException::class);

        $change->update(['reason' => 'Rewrite history']);
    }
}
