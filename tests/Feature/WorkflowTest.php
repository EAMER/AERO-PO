<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus as S;
use App\Enums\Role;
use App\Models\PurchaseOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WorkflowService;
use App\Support\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::set(Tenant::create(['name' => 'Aero', 'slug' => 'aero']));
    }

    protected function tearDown(): void
    {
        TenantContext::flush();
        parent::tearDown();
    }

    private function user(Role $role): User
    {
        return User::create([
            'name' => $role->value,
            'email' => $role->value.'@aero.test',
            'password' => 'password',
            'role' => $role,
        ]);
    }

    public function test_legal_transition_is_applied_and_logged(): void
    {
        $store = $this->user(Role::StoreStaff);
        $po = PurchaseOrder::create(['reference' => 'PO-1']);

        $po = app(WorkflowService::class)->transition($po, S::StockChecked, $store, 'checked');

        $this->assertSame(S::StockChecked, $po->fresh()->status);
        $this->assertDatabaseHas('po_status_logs', [
            'purchase_order_id' => $po->id,
            'from_status' => 'requisition_raised',
            'to_status' => 'stock_checked',
            'actor_id' => $store->id,
        ]);
    }

    public function test_illegal_jump_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $po = PurchaseOrder::create(['reference' => 'PO-1']);
        app(WorkflowService::class)->transition($po, S::Closed, $this->user(Role::TenantAdmin));
    }

    public function test_wrong_role_is_refused(): void
    {
        $this->expectException(AuthorizationException::class);

        $po = PurchaseOrder::create(['reference' => 'PO-1']);
        app(WorkflowService::class)->transition($po, S::StockChecked, $this->user(Role::Cfo));
    }
}
