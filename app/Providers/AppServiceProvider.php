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
    }
}
