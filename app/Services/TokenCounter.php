<?php

namespace App\Services;

use App\Models\BillingEvent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageMonthly;
use Carbon\Carbon;

class TokenCounter
{
    public function estimateTokens(string $text): int
    {
        return max(1, (int) ceil(str_word_count($text) * 1.33));
    }

    public function recordUsage(int $tokens): UsageMonthly
    {
        $tenant = TenantContext::getTenant();
        if (!$tenant) {
            throw new \RuntimeException('Tenant context not set.');
        }

        $month = Carbon::now()->format('Y-m');

        $usage = UsageMonthly::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'month' => $month],
            ['tokens_used' => 0, 'requests_count' => 0]
        );

        $usage->increment('tokens_used', $tokens);
        $usage->increment('requests_count');

        $this->enforcePlanLimits($tenant->id, $usage->tokens_used);

        return $usage;
    }

    private function enforcePlanLimits(int $tenantId, int $tokensUsed): void
    {
        $subscription = Subscription::query()->where('tenant_id', $tenantId)->first();
        if (!$subscription) {
            return;
        }

        $plan = Plan::query()->find($subscription->plan_id);
        if (!$plan) {
            return;
        }

        if ($tokensUsed > $plan->monthly_token_limit && !config('app.debug')) {
            BillingEvent::query()->create([
                'tenant_id' => $tenantId,
                'event_type' => 'plan_limit_exceeded',
                'payload' => [
                    'tokens_used' => $tokensUsed,
                    'limit' => $plan->monthly_token_limit,
                ],
            ]);

            if (!filter_var(env('BILLING_OVERAGE_ALLOWED', false), FILTER_VALIDATE_BOOLEAN)
                && filter_var(env('BILLING_HARD_LIMIT', true), FILTER_VALIDATE_BOOLEAN)
            ) {
                throw new \RuntimeException('Monthly token limit exceeded.');
            }
        }
    }
}
