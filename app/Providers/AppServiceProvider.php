<?php

namespace App\Providers;

use App\Auth\TenantAwarePasswordBrokerManager;
use App\Contracts\StockChecker;
use App\Support\FakeStockChecker;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    
    }

    public function boot(): void
    {
        $this->app->bind(StockChecker::class, FakeStockChecker::class);

        $this->app->singleton('auth.password', function ($app) {
            return new TenantAwarePasswordBrokerManager($app);
        });
    }
}