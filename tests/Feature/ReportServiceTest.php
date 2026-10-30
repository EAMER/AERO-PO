<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus as S;
use App\Models\PoStatusLog;
use App\Models\PurchaseOrder;
use App\Models\Tenant;
use App\Services\ReportService;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();
        TenantContext::set(Tenant::create(['name' => 'Aero', 'slug' => 'aero']));
        $this->reports = app(ReportService::class);
    }

    protected function tearDown(): void
    {
        TenantContext::flush();
        parent::tearDown();
    }

    private function po(string $reference, ?S $status = null): PurchaseOrder
    {
        $po = PurchaseOrder::create(['reference' => $reference]);

        if ($status) {
            $po->forceFill(['status' => $status])->save();
        }

        return $po->fresh();
    }

    private function log(PurchaseOrder $po, S $from, S $to): void
    {
        PoStatusLog::create([
            'purchase_order_id' => $po->id,
            'from_status' => $from,
            'to_status' => $to,
        ]);
    }

    public function test_status_counts_group_by_status(): void
    {
        $this->po('A');
        $this->po('B');
        $this->po('C', S::Closed);

        $counts = $this->reports->statusCounts();

        $this->assertSame(2, $counts['requisition_raised']);
        $this->assertSame(1, $counts['closed']);
    }

    public function test_average_cycle_time_is_null_with_nothing_closed(): void
    {
        $this->po('A');

        $this->assertNull($this->reports->averageCycleDays());
    }

    public function test_average_cycle_time_measures_raised_to_closed(): void
    {
        $po = $this->po('A');
        $this->travel(4)->days();
        $po->forceFill(['status' => S::Closed])->save();
        $this->log($po, S::GrnIssued, S::Closed);

        $this->assertEqualsWithDelta(4.0, $this->reports->averageCycleDays(), 0.01);
    }

    public function test_average_time_per_stage_uses_the_gaps_between_status_changes(): void
    {
        $po = $this->po('A');

        $this->travel(2)->days();
        $this->log($po, S::RequisitionRaised, S::StockChecked);

        $this->travel(3)->days();
        $this->log($po, S::StockChecked, S::RfqSent);

        $stages = collect($this->reports->averageDaysPerStage())->keyBy(fn ($r) => $r['status']->value);

        $this->assertEqualsWithDelta(2.0, $stages['requisition_raised']['avg_days'], 0.01);
        $this->assertEqualsWithDelta(3.0, $stages['stock_checked']['avg_days'], 0.01);
        $this->assertSame(1, $stages['stock_checked']['samples']);
    }

    public function test_longest_waiting_ranks_open_pos_and_skips_finished_ones(): void
    {
        $old = $this->po('OLD');
        $this->travel(9)->days();
        $recent = $this->po('RECENT');
        $this->po('DONE', S::Closed);
        $this->travel(1)->days();

        $rows = $this->reports->longestWaiting();

        $this->assertSame(['OLD', 'RECENT'], $rows->map(fn ($r) => $r['po']->reference)->all());
        $this->assertEqualsWithDelta(10.0, $rows[0]['days'], 0.01);
        $this->assertEqualsWithDelta(1.0, $rows[1]['days'], 0.01);
    }
}
