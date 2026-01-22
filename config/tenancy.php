<?php

return [
    'resolution_mode' => env('TENANT_RESOLUTION_MODE', 'header'),
    'header' => env('TENANT_HEADER', 'X-Tenant-ID'),
    'subdomain_base' => env('TENANT_SUBDOMAIN_BASE', 'iafuture.local'),
    'schema_prefix' => env('TENANT_SCHEMA_PREFIX', 'tenant_'),
];
