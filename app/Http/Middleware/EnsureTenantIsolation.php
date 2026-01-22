<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantIsolation
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!TenantContext::hasTenant()) {
            return response()->json([
                'message' => 'Tenant not resolved.',
            ], 400);
        }

        return $next($request);
    }
}
