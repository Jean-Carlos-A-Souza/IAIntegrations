<?php

namespace App\Http\Controllers;

use App\Models\FaqCache;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageMonthly;
use App\Services\TenantContext;
use Illuminate\Support\Facades\DB;

class UsageController extends Controller
{
    public function monthly()
    {
        $tenant = TenantContext::getTenant();

        $usage = UsageMonthly::query()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('month')
            ->first();

        $tokensUsed = $usage?->tokens_used ?? 0;
        $requestsCount = $usage?->requests_count ?? 0;

        $subscription = Subscription::query()
            ->where('tenant_id', $tenant->id)
            ->first();

        $plan = $subscription ? Plan::query()->find($subscription->plan_id) : null;
        $monthlyLimit = $plan?->monthly_token_limit ?? 0;
        $tokensRemaining = max(0, $monthlyLimit - $tokensUsed);
        $percentUsed = $monthlyLimit > 0
            ? round(($tokensUsed / $monthlyLimit) * 100, 2)
            : 0.0;

        return response()->json([
            'month' => $usage?->month,
            'tokens_used' => $tokensUsed,
            'requests_count' => $requestsCount,
            'tokens_limit' => $monthlyLimit,
            'tokens_remaining' => $tokensRemaining,
            'percent_used' => $percentUsed,
        ]);
    }

    public function topQuestions()
    {
        $tenant = TenantContext::getTenant();
        $results = FaqCache::query()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('hits')
            ->limit(10)
            ->get(['question_normalized', 'hits']);

        return response()->json($results);
    }
}
