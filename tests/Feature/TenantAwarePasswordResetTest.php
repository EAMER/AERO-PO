<?php

namespace Tests\Feature;

use App\Auth\TenantAwareTokenRepository;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Regression test for the exact bug this fixes: the same email existing in
 * two tenants, requesting a password reset in both, and one overwriting the
 * other's token. Exercises the repository directly rather than through
 * Password::broker(), since that facade binding depends on the
 * AppServiceProvider snippet having been applied by hand.
 */
class TenantAwarePasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private TenantAwareTokenRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = new TenantAwareTokenRepository(
            DB::connection(),
            Hash::getFacadeRoot(),
            'password_reset_tokens',
            config('app.key'),
            60,
            0,
        );
    }

    protected function tearDown(): void
    {
        TenantContext::flush();
        parent::tearDown();
    }

    private function makeUser(string $slug, string $email): array
    {
        $tenant = Tenant::create(['name' => ucfirst($slug), 'slug' => $slug]);
        $user = TenantContext::runAs($tenant, fn () => User::create([
            'name' => 'U', 'email' => $email, 'password' => 'x', 'role' => Role::LogisticsOfficer,
        ]));

        return [$tenant, $user];
    }

    public function test_two_tenants_with_the_same_email_get_independent_tokens(): void
    {
        [$tenantA, $userA] = $this->makeUser('aero', 'shared@example.test');
        [$tenantB, $userB] = $this->makeUser('demo', 'shared@example.test');

        $tokenA = TenantContext::runAs($tenantA, fn () => $this->repo->create($userA));
        $tokenB = TenantContext::runAs($tenantB, fn () => $this->repo->create($userB));

        // The bug this fixes: tenant B's token creation used to silently
        // overwrite tenant A's row (both keyed by the same bare email).
        $this->assertTrue(TenantContext::runAs($tenantA, fn () => $this->repo->exists($userA, $tokenA)));
        $this->assertTrue(TenantContext::runAs($tenantB, fn () => $this->repo->exists($userB, $tokenB)));
    }

    public function test_a_tenants_token_does_not_validate_against_the_other_tenant(): void
    {
        [$tenantA, $userA] = $this->makeUser('aero', 'shared@example.test');
        [$tenantB, $userB] = $this->makeUser('demo', 'shared@example.test');

        $tokenA = TenantContext::runAs($tenantA, fn () => $this->repo->create($userA));

        $this->assertFalse(TenantContext::runAs($tenantB, fn () => $this->repo->exists($userB, $tokenA)));
    }

    public function test_deleting_one_tenants_token_does_not_touch_the_others(): void
    {
        [$tenantA, $userA] = $this->makeUser('aero', 'shared@example.test');
        [$tenantB, $userB] = $this->makeUser('demo', 'shared@example.test');

        $tokenA = TenantContext::runAs($tenantA, fn () => $this->repo->create($userA));
        $tokenB = TenantContext::runAs($tenantB, fn () => $this->repo->create($userB));

        TenantContext::runAs($tenantA, fn () => $this->repo->delete($userA));

        $this->assertFalse(TenantContext::runAs($tenantA, fn () => $this->repo->exists($userA, $tokenA)));
        $this->assertTrue(TenantContext::runAs($tenantB, fn () => $this->repo->exists($userB, $tokenB)));
    }
}
