
<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

Route::get('/', fn () => redirect('/docs/api'));



Route::get('/up/db', function () {
    return [
        'host' => config('database.connections.pgsql.host'),
        'db'   => DB::connection()->getDatabaseName(),
        'ping' => DB::selectOne('select 1 as ok')->ok,
    ];
});
