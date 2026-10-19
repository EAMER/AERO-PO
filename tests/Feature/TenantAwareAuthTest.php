<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the gap flagged since the foundation was built: login is only
 * ever exercised against the right tenant subdomain, and the same email
 * in two tenants doesn't let you log into the wrong one.
 */
class TenantAwareAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TenantContext::flush();
        parent::tearDown();
    }

    private function makeTenantWithUser(string $slug, string $email, string $password = 'password'): array
    {
        $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug]);

        $user = TenantContext::runAs($tenant, fn () => User::create([
            'name' => 'Test User',
            'email' => $email,
            'password' => $password,
            'role' => Role::LogisticsOfficer,
        ]));

        return [$tenant, $user];
    }

    public function test_login_page_loads_on_a_known_tenant_subdomain(): void
    {
        Tenant::create(['name' => 'Aero', 'slug' => 'aero']);

        $this->get('http://aero.'.config('tenancy.domain').'/login')->assertOk();
    }

    public function test_unknown_tenant_subdomain_is_rejected(): void
    {
        $this->get('http://nope.'.config('tenancy.domain').'/login')->assertNotFound();
    }

    public function test_a_user_can_log_in_on_their_own_tenant(): void
    {
        [$tenant, $user] = $this->makeTenantWithUser('aero', 'shared@example.test');

        $this->post('http://aero.'.config('tenancy.domain').'/login', [
            'email' => 'shared@example.test',
            'password' => 'password',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_same_email_cannot_log_in_on_a_different_tenant(): void
    {
        // Same email exists in both tenants (allowed - uniqueness is per-tenant),
        // but each is a distinct account with its own password.
        $this->makeTenantWithUser('aero', 'shared@example.test', 'aero-password');
        $this->makeTenantWithUser('demo', 'shared@example.test', 'demo-password');

        $this->post('http://aero.'.config('tenancy.domain').'/login', [
            'email' => 'shared@example.test',
            'password' => 'demo-password', // the DEMO tenant's password, tried on AERO
        ])->assertSessionHasErrors();

        $this->assertGuest();
    }

    public function test_a_user_cannot_log_in_on_a_tenant_they_do_not_belong_to(): void
    {
        $this->makeTenantWithUser('aero', 'aero-only@example.test', 'password');
        Tenant::create(['name' => 'Demo', 'slug' => 'demo']);

        $this->post('http://demo.'.config('tenancy.domain').'/login', [
            'email' => 'aero-only@example.test',
            'password' => 'password',
        ])->assertSessionHasErrors();

        $this->assertGuest();
    }
}
