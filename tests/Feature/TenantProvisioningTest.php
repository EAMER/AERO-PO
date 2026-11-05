<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Tests\ProcurementTestCase;

class TenantProvisioningTest extends ProcurementTestCase
{
    public function test_a_company_and_its_first_admin_can_be_provisioned_without_public_registration(): void
    {
        $this->beginProcurement();

        $this->artisan('tenant:create', ['slug' => 'operator', 'name' => 'Another Operator', 'email' => 'admin@operator.test'])->assertSuccessful();

        $tenant = Tenant::where('slug', 'operator')->firstOrFail();
        TenantContext::runAs($tenant, function () {
            $admin = User::where('email', 'admin@operator.test')->firstOrFail();
            $this->assertSame(Role::TenantAdmin, $admin->role);
            $this->assertTrue($admin->is_active);
            $this->assertNotSame('password', $admin->password);
            $this->assertDatabaseHas('activity_logs', ['action' => 'tenant.created', 'tenant_id' => TenantContext::id()]);
        });
        $this->assertSame($this->procurementTenant->id, TenantContext::id());
    }

    public function test_invalid_or_duplicate_tenant_slugs_do_not_create_accounts(): void
    {
        $this->beginProcurement();

        $this->artisan('tenant:create', ['slug' => '../invalid', 'name' => 'Invalid', 'email' => 'admin@example.test'])->assertExitCode(2);
        $this->artisan('tenant:create', ['slug' => 'aero', 'name' => 'Duplicate', 'email' => 'admin@example.test'])->assertExitCode(2);

        $this->assertSame(1, Tenant::count());
        $this->assertDatabaseMissing('users', ['email' => 'admin@example.test']);
    }
}
