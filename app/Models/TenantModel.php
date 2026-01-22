<?php

namespace App\Models;

use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Model;

abstract class TenantModel extends Model
{
    protected static function booted(): void
    {
        static::creating(function () {
            if (!TenantContext::hasTenant()) {
                throw new \RuntimeException('Tenant context not set.');
            }
        });
    }
}
