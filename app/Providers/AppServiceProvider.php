<?php

namespace App\Providers;

use App\Services\Plaid\PlaidClient;
use Illuminate\Mail\Markdown;
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
        // Account, bill and bank names come from Plaid or a form and end up in
        // alert emails: keep HTML and Markdown links in them from rendering.
        Markdown::withSecuredEncoding();
    }
}
