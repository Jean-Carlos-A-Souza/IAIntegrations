<?php

namespace App\Services;

use App\Models\Tenant;

final class TenantContext
{
    private static ?Tenant $tenant = null;

    public static function setTenant(Tenant $tenant): void
    {
        self::$tenant = $tenant;
    }

    public static function getTenant(): ?Tenant
    {
        return self::$tenant;
    }

    public static function hasTenant(): bool
    {
        return self::$tenant !== null;
    }

    public static function clear(): void
    {
        self::$tenant = null;
    }
}
