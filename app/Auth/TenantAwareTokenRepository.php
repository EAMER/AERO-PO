<?php

namespace App\Auth;

use App\Support\TenantContext;
use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use RuntimeException;

/**
 * Laravel's default password_reset_tokens table is keyed by email alone,
 * which collides when the same email string exists in two tenants: one
 * tenant's reset request can silently overwrite the other's token. This
 * scopes every read/write by tenant_id as well.
 *
 * Registered via Password::extend('users', ...) in AppServiceProvider.
 * Requires the 2026_09_28_000008 migration (adds tenant_id to the table).
 */
class TenantAwareTokenRepository extends DatabaseTokenRepository
{
    private function tenantId(): int
    {
        $id = TenantContext::id();

        if ($id === null) {
            // Password reset routes only exist inside the tenant domain
            // group, so this should be unreachable - fail loudly rather
            // than silently operate without tenant scoping.
            throw new RuntimeException('TenantAwareTokenRepository used with no tenant context set.');
        }

        return $id;
    }

    public function create(CanResetPasswordContract $user)
    {
        $email = $user->getEmailForPasswordReset();

        $this->deleteExisting($user);

        $token = $this->createNewToken();

        $this->getTable()->insert($this->getPayload($email, $token));

        return $token;
    }

    protected function deleteExisting(CanResetPasswordContract $user)
    {
        return $this->getTable()
            ->where('tenant_id', $this->tenantId())
            ->where('email', $user->getEmailForPasswordReset())
            ->delete();
    }

    protected function getPayload($email, $token)
    {
        $payload = parent::getPayload($email, $token);
        $payload['tenant_id'] = $this->tenantId();

        return $payload;
    }

    public function exists(CanResetPasswordContract $user, $token)
    {
        $record = (array) $this->getTable()
            ->where('tenant_id', $this->tenantId())
            ->where('email', $user->getEmailForPasswordReset())
            ->first();

        return $record
            && ! $this->tokenExpired($record['created_at'])
            && $this->hasher->check($token, $record['token']);
    }

    public function recentlyCreatedToken(CanResetPasswordContract $user)
    {
        $record = (array) $this->getTable()
            ->where('tenant_id', $this->tenantId())
            ->where('email', $user->getEmailForPasswordReset())
            ->first();

        return $record && $this->tokenRecentlyCreated($record['created_at'] ?? null);
    }

    public function delete(CanResetPasswordContract $user)
    {
        $this->deleteExisting($user);
    }
}
