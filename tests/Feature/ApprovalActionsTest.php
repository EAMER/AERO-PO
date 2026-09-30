<?php

namespace Tests\Feature;

use App\Enums\ApprovalDecision;
use App\Enums\PurchaseOrderStatus as S;
use App\Enums\Role;
use App\Models\Approval;
use App\Models\PurchaseOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\WorkflowService;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/** The three approval actions: approve, reject, query. */
class ApprovalActionsTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseOrder $po;
    private ApprovalService $approvals;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::set(Tenant::create(['name' => 'Aero', 'slug' => 'aero']));

        $wf = app(WorkflowService::class);
        $logistics = $this->user(Role::LogisticsOfficer);
        $store = $this->user(Role::StoreStaff);

        $po = PurchaseOrder::create(['reference' => 'PO-1']);
        $po = $wf->transition($po, S::StockChecked, $store);
        $po = $wf->transition($po, S::RfqSent, $logistics);
        $po = $wf->transition($po, S::QuotesIn, $logistics);
        $this->po = $wf->transition($po, S::PendingApproval, $logistics);

        $this->approvals = app(ApprovalService::class);
        $this->approvals->request($this->po, [Role::Tlm, Role::Hamo, Role::TechnicalDirector]);
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

    private function step(int $n, string $stage = 'evaluation'): Approval
    {
        return Approval::where('stage', $stage)->where('step_order', $n)->firstOrFail();
    }

    // ---- approve ----

    public function test_full_chain_ends_at_po_sent(): void
    {
        $this->approvals->approve($this->step(1), $this->user(Role::Tlm));
        $this->assertSame(S::PendingApproval, $this->po->fresh()->status);

        $this->approvals->approve($this->step(2), $this->user(Role::Hamo));
        $this->approvals->approve($this->step(3), $this->user(Role::TechnicalDirector));
        $this->assertSame(S::PendingCfo, $this->po->fresh()->status);

        $this->approvals->approve($this->step(1, 'cfo'), $this->user(Role::Cfo));
        $this->assertSame(S::PoSent, $this->po->fresh()->status);
    }

    public function test_steps_must_be_decided_in_order(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->approvals->approve($this->step(2), $this->user(Role::Hamo));
    }

    public function test_only_the_steps_role_can_approve(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->approvals->approve($this->step(1), $this->user(Role::Cfo));
    }

    // ---- reject ----

    public function test_reject_needs_a_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->approvals->reject($this->step(1), $this->user(Role::Tlm), '  ');
    }

    public function test_reject_ends_the_chain_and_rejects_the_po(): void
    {
        $this->approvals->reject($this->step(1), $this->user(Role::Tlm), 'Price too high');

        $this->assertSame(S::Rejected, $this->po->fresh()->status);
        $this->assertSame(ApprovalDecision::Rejected, $this->step(1)->decision);
        $this->assertSame('Price too high', $this->step(1)->note);
    }

    // ---- query ----

    public function test_query_can_be_directed_to_anyone_in_the_chain(): void
    {
        $td = $this->user(Role::TechnicalDirector);
        $this->approvals->approve($this->step(1), $this->user(Role::Tlm));

        $q = $this->approvals->query($this->step(2), $this->user(Role::Hamo), $td, 'Why this vendor?');

        $this->assertSame($td->id, $q->directed_to);
        $this->assertTrue($q->isOpen());
    }

    public function test_an_open_query_blocks_the_step(): void
    {
        $this->approvals->approve($this->step(1), $this->user(Role::Tlm));
        $this->approvals->query($this->step(2), $this->user(Role::Hamo), $this->user(Role::TechnicalDirector), 'Why?');

        $this->expectException(InvalidArgumentException::class);

        $this->approvals->approve($this->step(2), $this->user(Role::Hamo));
    }

    public function test_step_can_be_decided_after_the_query_is_answered(): void
    {
        $td = $this->user(Role::TechnicalDirector);
        $this->approvals->approve($this->step(1), $this->user(Role::Tlm));
        $q = $this->approvals->query($this->step(2), $this->user(Role::Hamo), $td, 'Why?');

        $this->approvals->answerQuery($q, $td, 'Only approved vendor in stock.');
        $this->approvals->approve($this->step(2), $this->user(Role::Hamo));

        $this->assertSame(ApprovalDecision::Approved, $this->step(2)->decision);
        $this->assertSame('Only approved vendor in stock.', $q->fresh()->answer);
    }

    public function test_query_cannot_go_to_someone_outside_the_chain(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->approvals->query($this->step(1), $this->user(Role::Tlm), $this->user(Role::StoreStaff), 'Hello?');
    }

    public function test_query_cannot_go_to_yourself(): void
    {
        $tlm = $this->user(Role::Tlm);

        $this->expectException(InvalidArgumentException::class);

        $this->approvals->query($this->step(1), $tlm, $tlm, 'Note to self');
    }

    public function test_only_the_person_asked_can_answer(): void
    {
        $q = $this->approvals->query($this->step(1), $this->user(Role::Tlm), $this->user(Role::TechnicalDirector), 'Why?');

        $this->expectException(AuthorizationException::class);

        $this->approvals->answerQuery($q, $this->user(Role::Hamo), 'I will answer instead');
    }

    public function test_cannot_query_out_of_turn(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->approvals->query($this->step(2), $this->user(Role::Hamo), $this->user(Role::Tlm), 'Too early');
    }

    public function test_only_one_open_query_per_step(): void
    {
        $tlm = $this->user(Role::Tlm);
        $this->approvals->query($this->step(1), $tlm, $this->user(Role::Hamo), 'First');

        $this->expectException(InvalidArgumentException::class);

        $this->approvals->query($this->step(1), $tlm, $this->user(Role::TechnicalDirector), 'Second');
    }
}
