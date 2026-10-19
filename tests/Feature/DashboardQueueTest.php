<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus as S;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\WorkflowService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The "My queue" dashboard's two feeds: actionable approvals and open queries. */
class DashboardQueueTest extends TestCase
{
    use RefreshDatabase;

    private ApprovalService $approvals;

    protected function setUp(): void
    {
        parent::setUp();
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

    private function poAtPendingApproval(string $reference = 'PO-1')
    {
        $wf = app(WorkflowService::class);
        $logistics = $this->user(Role::LogisticsOfficer);
        $store = $this->user(Role::StoreStaff);

        $po = \App\Models\PurchaseOrder::create(['reference' => $reference]);
        $po = $wf->transition($po, S::StockChecked, $store);
        $po = $wf->transition($po, S::RfqSent, $logistics);
        $po = $wf->transition($po, S::QuotesIn, $logistics);

        return $wf->transition($po, S::PendingApproval, $logistics);
    }

    public function test_tlm_sees_the_first_step_but_not_the_second(): void
    {
        $po = $this->poAtPendingApproval();
        $this->approvals->request($po, [Role::Tlm, Role::TechnicalDirector]);

        $tlmQueue = $this->approvals->actionableFor($this->user(Role::Tlm));
        $tdQueue = $this->approvals->actionableFor($this->user(Role::TechnicalDirector));

        $this->assertCount(1, $tlmQueue);
        $this->assertCount(0, $tdQueue);
    }

    public function test_approving_moves_the_queue_to_the_next_role(): void
    {
        $po = $this->poAtPendingApproval();
        $this->approvals->request($po, [Role::Tlm, Role::TechnicalDirector]);

        $this->approvals->approve($this->approvals->actionableFor($this->user(Role::Tlm))->first(), $this->user(Role::Tlm));

        $this->assertCount(0, $this->approvals->actionableFor($this->user(Role::Tlm)));
        $this->assertCount(1, $this->approvals->actionableFor($this->user(Role::TechnicalDirector)));
    }

    public function test_an_open_query_removes_the_step_from_the_queue(): void
    {
        $po = $this->poAtPendingApproval();
        $this->approvals->request($po, [Role::Tlm, Role::TechnicalDirector]);
        $step = $this->approvals->actionableFor($this->user(Role::Tlm))->first();

        $this->approvals->query($step, $this->user(Role::Tlm), $this->user(Role::TechnicalDirector), 'Why this vendor?');

        $this->assertCount(0, $this->approvals->actionableFor($this->user(Role::Tlm)));
    }

    public function test_queries_directed_to_a_user_show_up_and_clear_on_answer(): void
    {
        $po = $this->poAtPendingApproval();
        $this->approvals->request($po, [Role::Tlm, Role::TechnicalDirector]);
        $step = $this->approvals->actionableFor($this->user(Role::Tlm))->first();
        $td = $this->user(Role::TechnicalDirector);

        $query = $this->approvals->query($step, $this->user(Role::Tlm), $td, 'Why this vendor?');

        $this->assertCount(1, $this->approvals->openQueriesFor($td));

        $this->approvals->answerQuery($query, $td, 'Only approved vendor in stock.');

        $this->assertCount(0, $this->approvals->openQueriesFor($td));
    }

    public function test_admin_sees_every_pending_step_across_roles(): void
    {
        $this->poAtPendingApproval('PO-1');
        $po2 = $this->poAtPendingApproval('PO-2');
        $this->approvals->request($po2, [Role::TechnicalDirector]);

        // Build a second chain on PO-1 too, via its own request.
        $po1 = \App\Models\PurchaseOrder::where('reference', 'PO-1')->first();
        $this->approvals->request($po1, [Role::Tlm]);

        $admin = $this->user(Role::TenantAdmin);

        $this->assertCount(2, $this->approvals->actionableFor($admin));
    }
}
