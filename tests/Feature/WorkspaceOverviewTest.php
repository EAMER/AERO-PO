<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus as S;
use App\Enums\Role;
use App\Models\PoLine;
use App\Models\PurchaseOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\ProcurementTestCase;

class WorkspaceOverviewTest extends ProcurementTestCase
{
    public function test_dashboard_totals_and_pipeline_only_include_the_current_company(): void
    {
        $this->beginProcurement();
        foreach ([S::RequisitionRaised, S::AwaitingPayment, S::InTransit, S::Closed, S::Rejected, S::Cancelled] as $status) {
            PurchaseOrder::factory()->create(['status' => $status]);
        }
        $other = Tenant::create(['name' => 'Other company', 'slug' => 'other']);
        TenantContext::runAs($other, function () {
            PurchaseOrder::factory()->create(['status' => S::Closed]);
            PurchaseOrder::factory()->create(['status' => S::InTransit]);
        });

        $response = $this->actingAs($this->person(Role::TenantAdmin))->get($this->address('/dashboard'));

        $response->assertOk()->assertViewHas('overview', ['total' => 6, 'active' => 3, 'in_transit' => 1, 'closed' => 1]);
        $response->assertViewHas('pipeline', fn ($rows) => $rows->all() === ['Stores & stock check' => 1, 'Sourcing & evaluation' => 0, 'Approvals & payment' => 1, 'Shipping & delivery' => 1, 'Receipt & close-out' => 0]);
    }

    public function test_requesters_dashboard_counts_only_their_own_requisitions(): void
    {
        $this->beginProcurement();
        $requester = $this->person(Role::Requester);
        PurchaseOrder::factory()->create(['requested_by' => $requester->id, 'status' => S::RequisitionRaised]);
        PurchaseOrder::factory()->create(['requested_by' => $requester->id, 'status' => S::Closed]);
        $other = User::factory()->create(['role' => Role::Requester]);
        PurchaseOrder::factory()->create(['requested_by' => $other->id, 'status' => S::InTransit, 'reference' => 'PRIVATE-OTHER-REQUEST']);

        $response = $this->actingAs($requester)->get($this->address('/dashboard'));

        $response->assertOk()->assertViewHas('overview', ['total' => 2, 'active' => 1, 'in_transit' => 0, 'closed' => 1]);
        $response->assertDontSee('PRIVATE-OTHER-REQUEST');
    }

    public static function searchFields(): array
    {
        return ['reference' => ['REQ-UI-100'], 'part number' => ['B737-BRK'], 'description' => ['Brake assembly'], 'zero is valid search text' => ['0']];
    }

    #[DataProvider('searchFields')]
    public function test_requisition_search_and_status_filter_preserve_requester_and_tenant_isolation(string $search): void
    {
        $this->beginProcurement();
        $requester = $this->person(Role::Requester);
        $target = PurchaseOrder::factory()->create(['requested_by' => $requester->id, 'status' => S::QuotesIn, 'reference' => 'REQ-UI-100']);
        PoLine::factory()->create(['purchase_order_id' => $target->id, 'part_number' => 'B737-BRK', 'description' => 'Brake assembly']);
        $unrelated = PurchaseOrder::factory()->create(['requested_by' => $requester->id, 'status' => S::QuotesIn, 'reference' => 'UNRELATED-REQUEST']);
        PoLine::factory()->create(['purchase_order_id' => $unrelated->id, 'part_number' => 'D-77', 'description' => 'Unrelated component']);
        $closed = PurchaseOrder::factory()->create(['requested_by' => $requester->id, 'status' => S::Closed, 'reference' => 'REQ-UI-100-CLOSED']);
        PoLine::factory()->create(['purchase_order_id' => $closed->id, 'part_number' => 'B737-BRK', 'description' => 'Brake assembly']);
        $otherRequester = User::factory()->create(['role' => Role::Requester]);
        $private = PurchaseOrder::factory()->create(['requested_by' => $otherRequester->id, 'status' => S::QuotesIn, 'reference' => 'REQ-UI-100-PRIVATE']);
        PoLine::factory()->create(['purchase_order_id' => $private->id, 'part_number' => 'B737-BRK', 'description' => 'Brake assembly']);
        $other = Tenant::create(['name' => 'Other company', 'slug' => 'other']);
        TenantContext::runAs($other, function () {
            $foreign = PurchaseOrder::factory()->create(['status' => S::QuotesIn, 'reference' => 'REQ-UI-100-FOREIGN']);
            PoLine::factory()->create(['purchase_order_id' => $foreign->id, 'part_number' => 'B737-BRK', 'description' => 'Brake assembly']);
        });

        $response = $this->actingAs($requester)->get($this->address('/requisitions?'.http_build_query(['search' => $search, 'status' => 'quotes_in'])));

        $response->assertOk()->assertViewHas('pos', fn ($rows) => $rows->getCollection()->modelKeys() === [$target->id]);
        $response->assertSee('REQ-UI-100')->assertDontSee('REQ-UI-100-PRIVATE')->assertDontSee('REQ-UI-100-FOREIGN')->assertDontSee('REQ-UI-100-CLOSED');
    }

    public function test_unknown_status_filters_return_a_validation_error(): void
    {
        $this->beginProcurement();

        $this->actingAs($this->person(Role::Requester))->getJson($this->address('/requisitions?status=unrecognized'))->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_search_text_is_escaped_when_redisplayed_in_the_filter(): void
    {
        $this->beginProcurement();
        $search = '<script>alert(1)</script>';

        $response = $this->actingAs($this->person(Role::Requester))->get($this->address('/requisitions?'.http_build_query(['search' => $search])));

        $response->assertOk()->assertSee($search)->assertDontSee($search, false);
    }
}
