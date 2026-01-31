<?php

namespace App\Providers;

use App\Services\OpenAIService;
use App\Services\RAGService;
use App\Services\TenantResolver;
use App\Services\TokenCounter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantResolver::class);
        $this->app->singleton(OpenAIService::class);
        $this->app->singleton(RAGService::class);
        $this->app->singleton(TokenCounter::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/public'));
        $this->loadMigrationsFrom(database_path('migrations/tenant'));

        // Registrar middleware aliases para Laravel 11
        $this->app['router']->aliasMiddleware('verify.active.subscription', \App\Http\Middleware\VerifyActiveSubscription::class);
        $this->app['router']->aliasMiddleware('verify.api.key', \App\Http\Middleware\VerifyApiKey::class);
        $this->app['router']->aliasMiddleware('validate.token.limit', \App\Http\Middleware\ValidateTokenLimit::class);
        $this->app['router']->aliasMiddleware('enforce.request.rate.limit', \App\Http\Middleware\EnforceRequestRateLimit::class);
    }
}
