<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus as S;
use App\Enums\Role;
use App\Models\Approval;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ApprovalActionRequired;
use App\Notifications\ApprovalQueryRaised;
use App\Notifications\ApprovalQueryWasAnswered;
use App\Notifications\RequisitionDecided;
use App\Services\ApprovalService;
use App\Services\WorkflowService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ApprovalNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private ApprovalService $approvals;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        TenantContext::set(Tenant::create(['name' => 'Aero', 'slug' => 'aero']));
        $this->approvals = app(ApprovalService::class);
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
            ['name' => $role->value, 'password' => 'x', 'role' => $role],
        );
    }

    private function poAtPendingApproval()
    {
        $wf = app(WorkflowService::class);
        $logistics = $this->user(Role::LogisticsOfficer);
        $store = $this->user(Role::StoreStaff);

        $po = \App\Models\PurchaseOrder::create(['reference' => 'PO-1', 'requested_by' => $this->user(Role::Requester)->id]);
        $po = $wf->transition($po, S::StockChecked, $store);
        $po = $wf->transition($po, S::RfqSent, $logistics);
        $po = $wf->transition($po, S::QuotesIn, $logistics);

        return $wf->transition($po, S::PendingApproval, $logistics);
    }

    public function test_requesting_a_chain_notifies_the_first_step_role(): void
    {
        $po = $this->poAtPendingApproval();
        $tlm = $this->user(Role::Tlm);
        $this->user(Role::TechnicalDirector);

        $this->approvals->request($po, [Role::Tlm, Role::TechnicalDirector]);

        Notification::assertSentTo($tlm, ApprovalActionRequired::class);
    }

    public function test_approving_notifies_the_next_step_role_not_the_one_after(): void
    {
        $po = $this->poAtPendingApproval();
        $tlm = $this->user(Role::Tlm);
        $td = $this->user(Role::TechnicalDirector);
        $hamo = $this->user(Role::Hamo);
        $this->approvals->request($po, [Role::Tlm, Role::Hamo, Role::TechnicalDirector]);

        $this->approvals->approve(Approval::where('step_order', 1)->first(), $tlm);

        Notification::assertSentTo($hamo, ApprovalActionRequired::class);
        Notification::assertNotSentTo($td, ApprovalActionRequired::class);
    }

    public function test_a_query_notifies_the_target_and_an_answer_notifies_the_asker(): void
    {
        $po = $this->poAtPendingApproval();
        $tlm = $this->user(Role::Tlm);
        $td = $this->user(Role::TechnicalDirector);
        $this->approvals->request($po, [Role::Tlm, Role::TechnicalDirector]);

        $query = $this->approvals->query(Approval::where('step_order', 1)->first(), $tlm, $td, 'Why?');
        Notification::assertSentTo($td, ApprovalQueryRaised::class);

        $this->approvals->answerQuery($query, $td, 'Because.');
        Notification::assertSentTo($tlm, ApprovalQueryWasAnswered::class);
    }

    public function test_rejection_notifies_the_requester(): void
    {
        $po = $this->poAtPendingApproval();
        $requester = $po->requester;
        $tlm = $this->user(Role::Tlm);
        $this->approvals->request($po, [Role::Tlm]);

        $this->approvals->reject(Approval::where('step_order', 1)->first(), $tlm, 'No budget');

        Notification::assertSentTo($requester, RequisitionDecided::class);
    }
}
