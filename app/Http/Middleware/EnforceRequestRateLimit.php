<?php

namespace App\Http\Middleware;

use App\Models\UsageMonthly;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class EnforceRequestRateLimit
{
    /**
     * Limites de requisições por minuto (por tipo de plano)
     */
    protected array $requestLimits = [
        'free' => 10,      // 10 req/min
        'basic' => 30,     // 30 req/min
        'pro' => 100,      // 100 req/min
        'enterprise' => 500, // 500 req/min
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = TenantContext::getTenant();

        if (!$tenant) {
            return $next($request);
        }

        $subscription = $tenant->subscription()
            ->where('status', 'active')
            ->first();

        if (!$subscription || !$subscription->plan) {
            return $next($request);
        }

        // Pegar limite baseado no plano
        $planName = $subscription->plan->name;
        $limit = $this->requestLimits[$planName] ?? 30;

        // Chave de cache para rate limit
        $cacheKey = "rate_limit:tenant:{$tenant->id}:" . now()->format('Y-m-d-H-i');

        // Incrementar contador
        $count = Cache::increment($cacheKey);

        // Se for a primeira vez neste minuto, definir expiração
        if ($count === 1) {
            Cache::put($cacheKey, 1, 60); // 60 segundos
        }

        // Adicionar headers
        $response = $next($request);

        return $response
            ->header('X-RateLimit-Limit', $limit)
            ->header('X-RateLimit-Remaining', max(0, $limit - $count))
            ->header('X-RateLimit-Reset', now()->addMinute()->timestamp);
    }
}
