<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus as S;
use App\Enums\Role;
use App\Models\PurchaseOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\ReportService;
use App\Services\TenantSettingsService;
use App\Support\TenantContext;
use Tests\ProcurementTestCase;

class TenantReportingTest extends ProcurementTestCase
{
    public function test_kpi_targets_are_empty_until_the_tlm_defines_them(): void
    {
        $this->beginProcurement();

        $this->assertCount(0, app(ReportService::class)->kpis());
        $this->actingAs($this->person(Role::Tlm))->post($this->address('/settings/kpis'), ['name' => 'Completed orders', 'metric' => 'closed_orders', 'target' => 2, 'direction' => 'at_least'])->assertRedirect();

        $rows = app(ReportService::class)->kpis();
        $this->assertSame(0, $rows->first()['actual']);
        $this->assertFalse($rows->first()['met']);
        $this->get($this->address('/reports'))->assertOk()->assertSee('Completed orders');
    }

    public function test_requesters_cannot_define_company_kpis(): void
    {
        $this->beginProcurement();

        $this->actingAs($this->person(Role::Requester))->postJson($this->address('/settings/kpis'), ['name' => 'Injected target', 'metric' => 'closed_orders', 'target' => 2, 'direction' => 'at_least'])->assertForbidden();

        $this->assertDatabaseCount('kpi_definitions', 0);
    }

    public function test_stage_timers_use_the_defined_target_and_actual_elapsed_time(): void
    {
        $this->beginProcurement();
        $this->travelTo('2026-11-05 12:00:00 UTC');
        PurchaseOrder::factory()->create(['status' => S::QuotesIn, 'created_at' => now()->subHours(3)]);
        app(TenantSettingsService::class)->saveSlas($this->person(Role::Tlm), ['quotes_in' => 2], []);

        $row = app(ReportService::class)->stageTimers()->first();

        $this->assertSame(3.0, $row['hours']);
        $this->assertSame(2, $row['target_hours']);
        $this->assertTrue($row['overdue']);
    }

    public function test_the_next_approval_step_timer_starts_when_that_step_opens(): void
    {
        $this->beginProcurement();
        $this->travelTo('2026-11-05 08:00:00 UTC');
        app(TenantSettingsService::class)->saveSlas($this->person(Role::Tlm), [], ['evaluation' => 2]);
        $order = PurchaseOrder::factory()->create(['status' => S::PendingApproval]);
        app(ApprovalService::class)->request($order, [Role::Tlm, Role::TechnicalDirector]);
        $first = $order->approvals()->where('step_order', 1)->first();
        $this->travel(1)->hours();

        app(ApprovalService::class)->approve($first, $this->person(Role::Tlm));

        $second = $order->approvals()->where('step_order', 2)->first();
        $this->assertSame('2026-11-05 09:00:00', $second->opened_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-05 11:00:00', $second->due_at->format('Y-m-d H:i:s'));
        $this->assertSame(0.0, app(ReportService::class)->approvalTimers()->first()['hours']);
    }

    public function test_configured_approver_is_snapshotted_and_other_role_holders_cannot_decide(): void
    {
        $this->beginProcurement();
        $firstTlm = $this->person(Role::Tlm);
        $assigned = User::factory()->create(['role' => Role::Tlm]);
        app(TenantSettingsService::class)->saveApprovers($this->person(Role::TenantAdmin), ['evaluation' => ['tlm' => $assigned->id]]);
        $order = PurchaseOrder::factory()->create(['status' => S::PendingApproval]);
        app(ApprovalService::class)->request($order, [Role::Tlm, Role::TechnicalDirector]);
        $step = $order->approvals()->where('step_order', 1)->first();

        $this->actingAs($firstTlm)->post($this->address('/approvals/'.$step->id.'/approve'))->assertForbidden();
        $this->actingAs($assigned)->post($this->address('/approvals/'.$step->id.'/approve'))->assertRedirect();

        $this->assertSame($assigned->id, $step->fresh()->decided_by);
        $this->assertSame('approved', $step->fresh()->decision->value);
    }

    public function test_approver_settings_reject_users_from_another_tenant(): void
    {
        $this->beginProcurement();
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other']);
        $foreign = TenantContext::runAs($other, fn () => User::factory()->create(['role' => Role::Tlm]));

        $this->actingAs($this->person(Role::TenantAdmin))->postJson($this->address('/settings/approvers'), ['approvers' => ['evaluation' => ['tlm' => $foreign->id]]])->assertUnprocessable()->assertJsonValidationErrors('workflow');

        $this->assertNull(data_get($this->procurementTenant->fresh()->settings, 'approvers.evaluation.tlm'));
    }

    public function test_malformed_approver_assignments_return_validation_errors_without_changing_settings(): void
    {
        $this->beginProcurement();
        $this->actingAs($this->person(Role::TenantAdmin));

        $this->postJson($this->address('/settings/approvers'), ['approvers' => ['evaluation' => 'invalid']])->assertUnprocessable()->assertJsonValidationErrors('approvers.evaluation');
        $this->postJson($this->address('/settings/approvers'), ['approvers' => ['evaluation' => ['tlm' => ['invalid']]]])->assertUnprocessable()->assertJsonValidationErrors('approvers.evaluation.tlm');

        $this->assertNull(data_get($this->procurementTenant->fresh()->settings, 'approvers'));
    }

    public function test_reports_do_not_include_another_tenants_orders_or_kpis(): void
    {
        $this->beginProcurement();
        PurchaseOrder::factory()->create(['status' => S::Closed]);
        app(TenantSettingsService::class)->defineKpi($this->person(Role::Tlm), ['name' => 'Aero only', 'metric' => 'closed_orders', 'target' => 1, 'direction' => 'at_least']);
        $other = Tenant::create(['name' => 'Other', 'slug' => 'other']);

        TenantContext::runAs($other, function () {
            $this->assertSame([], app(ReportService::class)->statusCounts());
            $this->assertCount(0, app(ReportService::class)->kpis());
            $this->assertCount(0, app(ReportService::class)->stageTimers());
        });
    }

    public function test_invalid_sla_and_kpi_values_do_not_change_settings(): void
    {
        $this->beginProcurement();
        $this->actingAs($this->person(Role::Tlm));

        $this->postJson($this->address('/settings/slas'), ['sla_hours' => ['quotes_in' => -1], 'approval_sla_hours' => []])->assertUnprocessable()->assertJsonValidationErrors('sla_hours.quotes_in');
        $this->postJson($this->address('/settings/kpis'), ['name' => 'Unsupported', 'metric' => 'arbitrary_sql', 'target' => 1, 'direction' => 'at_most'])->assertUnprocessable()->assertJsonValidationErrors('workflow');

        $this->assertDatabaseCount('kpi_definitions', 0);
        $this->assertNull(data_get($this->procurementTenant->fresh()->settings, 'sla_hours.quotes_in'));
    }
}
