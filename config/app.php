<?php

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ServiceProvider;

return [
    'name' => env('APP_NAME', 'IAFuture'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => 'UTC',
    'locale' => 'en',
    'fallback_locale' => 'en',
    'faker_locale' => 'en_US',
    'key' => env('APP_KEY'),
    'cipher' => 'AES-256-CBC',
    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],
    'providers' => ServiceProvider::defaultProviders()->merge([
        App\Providers\AppServiceProvider::class,
        App\Providers\AuthServiceProvider::class,
    ])->toArray(),
    'aliases' => Facade::defaultAliases()->merge([
        //
    ])->toArray(),
];
