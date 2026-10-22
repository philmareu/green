<?php

namespace App\Providers;

use App\Services\Plaid\PlaidClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PlaidClient::class, fn ($app) => new PlaidClient($app['config']['services.plaid']));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
