<?php

namespace Tests\Feature;

use App\Models\Concerns\BelongsToTenant;
use App\Models\PurchaseOrder;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TenantContext::flush();
        parent::tearDown();
    }

    private function twoTenants(): array
    {
        $a = Tenant::create(['name' => 'A', 'slug' => 'a']);
        $b = Tenant::create(['name' => 'B', 'slug' => 'b']);

        $poA = TenantContext::runAs($a, fn () => PurchaseOrder::create(['reference' => 'PO-1']));
        $poB = TenantContext::runAs($b, fn () => PurchaseOrder::create(['reference' => 'PO-1']));

        return [$a, $b, $poA, $poB];
    }

    public function test_each_tenant_only_sees_its_own_records(): void
    {
        [$a, $b, $poA, $poB] = $this->twoTenants();

        TenantContext::runAs($a, fn () => $this->assertSame([$poA->id], PurchaseOrder::pluck('id')->all()));
        TenantContext::runAs($b, fn () => $this->assertSame([$poB->id], PurchaseOrder::pluck('id')->all()));
    }

    public function test_other_tenants_record_cannot_be_loaded_by_id(): void
    {
        [$a, , , $poB] = $this->twoTenants();

        TenantContext::runAs($a, fn () => $this->assertNull(PurchaseOrder::find($poB->id)));
    }

    public function test_queries_fail_closed_without_a_tenant(): void
    {
        $this->twoTenants();

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_creating_without_a_tenant_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        PurchaseOrder::create(['reference' => 'PO-X']);
    }

    public function test_every_model_uses_the_tenant_trait(): void
    {
        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if ($class === Tenant::class) {
                continue;
            }

            $this->assertContains(
                BelongsToTenant::class,
                class_uses_recursive($class),
                "$class must use BelongsToTenant"
            );
        }
    }
}
