<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('tenants:sync', function () {
    $this->info('Tenant schemas sync placeholder.');
})->purpose('Sync tenant schemas');
