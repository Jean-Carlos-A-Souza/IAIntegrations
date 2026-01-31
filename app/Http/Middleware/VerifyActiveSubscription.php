<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyActiveSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = TenantContext::getTenant();

        if (!$tenant) {
            return response()->json([
                'message' => 'Tenant not found',
                'code' => 'TENANT_NOT_FOUND',
            ], 400);
        }

        $subscription = $tenant->subscription()
            ->where('status', 'active')
            ->where('current_period_end', '>=', now())
            ->first();

        if (!$subscription) {
            return response()->json([
                'message' => 'No active subscription. Please upgrade your plan.',
                'code' => 'NO_ACTIVE_SUBSCRIPTION',
                'action' => 'Visit /plans to subscribe',
            ], 402); // 402 Payment Required
        }

        // Armazenar subscription no request para uso posterior
        $request->merge(['subscription' => $subscription]);

        return $next($request);
    }
}
