<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TenantResolver
{
    public function resolve(Request $request): ?Tenant
    {
        $mode = config('tenancy.resolution_mode', 'header');

        return match ($mode) {
            'subdomain' => $this->resolveBySubdomain($request),
            'token' => $this->resolveByToken($request),
            default => $this->resolveByHeader($request),
        };
    }

    public function applyTenantSchema(Tenant $tenant): void
    {
        $schema = $tenant->schema;

        DB::statement('SET search_path TO '.$schema.', public');
    }

    private function resolveByHeader(Request $request): ?Tenant
    {
        $header = config('tenancy.header', 'X-Tenant-ID');
        $tenantId = $request->header($header);

        if (!$tenantId) {
            return null;
        }

        return Tenant::query()->where('id', $tenantId)->first();
    }

    private function resolveBySubdomain(Request $request): ?Tenant
    {
        $host = $request->getHost();
        $base = config('tenancy.subdomain_base');

        if (!$host || !str_ends_with($host, $base)) {
            return null;
        }

        $slug = str_replace('.'.$base, '', $host);

        return Tenant::query()->where('slug', $slug)->first();
    }

    private function resolveByToken(Request $request): ?Tenant
    {
        $user = $request->user();

        if (!$user) {
            return null;
        }

        return Tenant::query()->find($user->tenant_id);
    }
}
