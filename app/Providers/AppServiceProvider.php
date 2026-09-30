<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Contracts\StockChecker;
use App\Support\FakeStockChecker;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->bind(StockChecker::class, FakeStockChecker::class);
    }
}
