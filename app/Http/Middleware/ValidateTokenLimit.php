<?php

namespace App\Http\Middleware;

use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ValidateTokenLimit
{
    /**
     * Routes que NÃO precisam checar limite de tokens
     * (GET requests, metadata, etc)
     */
    protected array $excludedRoutes = [
        'GET /api/knowledge/documents',
        'GET /api/knowledge/documents/*',
        'GET /api/usage/monthly',
        'GET /api/analytics/top-questions',
        'GET /api/tenant',
        'GET /api/tenant/users',
        'GET /api/ai/settings',
        'GET /api/auth/me',
        'GET /api/health/openai',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Verificar se é rota excluída
        if ($this->isExcludedRoute($request)) {
            return $next($request);
        }

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

        if (!$subscription || !$subscription->plan) {
            return response()->json([
                'message' => 'No active subscription',
                'code' => 'NO_ACTIVE_SUBSCRIPTION',
            ], 402);
        }

        // Pegar uso do mês atual
        $currentMonth = now()->format('Y-m');
        $usage = $tenant->usageMonthly()
            ->where('month', 'LIKE', $currentMonth . '%')
            ->first();

        $tokensUsed = $usage?->tokens_used ?? 0;
        $monthlyLimit = $subscription->plan->monthly_token_limit;

        // Verificar se já atingiu o limite
        if ($tokensUsed >= $monthlyLimit) {
            return response()->json([
                'message' => 'Monthly token limit reached',
                'code' => 'LIMIT_REACHED',
                'data' => [
                    'limit' => $monthlyLimit,
                    'used' => $tokensUsed,
                    'remaining' => 0,
                    'reset_date' => $subscription->current_period_end->format('Y-m-d'),
                ],
            ], 429); // 429 Too Many Requests
        }

        // Armazenar no request para uso posterior
        $request->merge([
            'subscription' => $subscription,
            'tokens_limit' => $monthlyLimit,
            'tokens_used' => $tokensUsed,
            'tokens_remaining' => $monthlyLimit - $tokensUsed,
        ]);

        return $next($request);
    }

    protected function isExcludedRoute(Request $request): bool
    {
        $method = $request->getMethod();
        $path = $request->path();

        foreach ($this->excludedRoutes as $route) {
            [$routeMethod, $routePath] = explode(' ', $route);

            if ($method !== $routeMethod) {
                continue;
            }

            // Converter wildcard em regex
            $pattern = str_replace('*', '.*', $routePath);
            $pattern = '/^' . str_replace('/', '\/', $pattern) . '$/';

            if (preg_match($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
