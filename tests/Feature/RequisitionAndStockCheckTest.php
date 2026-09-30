<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus as S;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RequisitionService;
use App\Support\FakeStockChecker;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class RequisitionAndStockCheckTest extends TestCase
{
    use RefreshDatabase;

    private RequisitionService $requisitions;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::set(Tenant::create(['name' => 'Aero', 'slug' => 'aero']));
        $this->requisitions = app(RequisitionService::class);
    }

    protected function tearDown(): void
    {
        TenantContext::flush();
        parent::tearDown();
    }

    private function user(Role $role): User
    {
        return User::create(['name' => $role->value, 'email' => $role->value.'@aero.test', 'password' => 'password', 'role' => $role]);
    }

    public function test_raising_a_requisition_needs_at_least_one_line(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->requisitions->raise('PO-1', $this->user(Role::Requester), []);
    }

    public function test_raise_creates_the_po_and_its_lines(): void
    {
        $po = $this->requisitions->raise('PO-1', $this->user(Role::Requester), [
            ['part_number' => 'ABC-123', 'description' => 'Bracket', 'quantity' => 2],
            ['part_number' => 'XYZ-999', 'description' => 'Gasket', 'quantity' => 5, 'condition' => 'new'],
        ]);

        $this->assertSame(S::RequisitionRaised, $po->status);
        $this->assertCount(2, $po->lines);
        $this->assertSame('new', $po->lines()->where('part_number', 'XYZ-999')->first()->condition);
    }

    public function test_all_lines_in_stock_closes_the_po_via_fulfilled_from_stock(): void
    {
        $po = $this->requisitions->raise('PO-1', $this->user(Role::Requester), [
            ['part_number' => 'INSTOCK-1', 'description' => 'Bracket', 'quantity' => 1],
            ['part_number' => 'INSTOCK-2', 'description' => 'Gasket', 'quantity' => 1],
        ]);

        $po = $this->requisitions->checkStock($po, $this->user(Role::StoreStaff), new FakeStockChecker());

        $this->assertSame(S::FulfilledFromStock, $po->status);
        $this->assertTrue($po->lines->every(fn ($l) => $l->stock_available === true));
    }

    public function test_any_line_short_leaves_the_po_at_stock_checked(): void
    {
        $po = $this->requisitions->raise('PO-1', $this->user(Role::Requester), [
            ['part_number' => 'INSTOCK-1', 'description' => 'Bracket', 'quantity' => 1],
            ['part_number' => 'SHORT-1', 'description' => 'Gasket', 'quantity' => 1],
        ]);

        $po = $this->requisitions->checkStock($po, $this->user(Role::StoreStaff), new FakeStockChecker());

        $this->assertSame(S::StockChecked, $po->status);
    }

    public function test_the_short_flow_reaches_quotes_in(): void
    {
        $po = $this->requisitions->raise('PO-1', $this->user(Role::Requester), [
            ['part_number' => 'SHORT-1', 'description' => 'Gasket', 'quantity' => 1],
        ]);
        $logistics = $this->user(Role::LogisticsOfficer);

        $po = $this->requisitions->checkStock($po, $this->user(Role::StoreStaff), new FakeStockChecker());
        $po = $this->requisitions->markRfqSent($po, $logistics);
        $po = $this->requisitions->markQuotesIn($po, $logistics);

        $this->assertSame(S::QuotesIn, $po->status);
    }
}
