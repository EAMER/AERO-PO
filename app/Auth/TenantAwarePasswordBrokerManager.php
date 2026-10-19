<?php

namespace App\Auth;

use Illuminate\Auth\Passwords\PasswordBrokerManager;

/**
 * Laravel builds its DatabaseTokenRepository directly inside
 * createTokenRepository() - hardcoded, not resolved via the container - so
 * there's no clean extension point for a custom repository class.
 * PasswordBrokerManager::extend() (older Laravel versions) is also gone/
 * changed in this version. Subclassing and overriding just this one method
 * is the most version-stable way to swap in TenantAwareTokenRepository.
 */
class TenantAwarePasswordBrokerManager extends PasswordBrokerManager
{
    protected function createTokenRepository(array $config)
    {
        $key = $this->app['config']['app.key'];

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        return new TenantAwareTokenRepository(
            $this->app['db']->connection($config['connection'] ?? null),
            $this->app['hash'],
            $config['table'],
            $key,
            $config['expire'],
            $config['throttle'] ?? 0,
        );
    }
}
