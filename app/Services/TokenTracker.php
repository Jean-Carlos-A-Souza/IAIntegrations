<?php

namespace App\Services;

use App\Models\BillingEvent;
use App\Models\UsageMonthly;

class TokenTracker
{
    /**
     * Track tokens consumed by an operation.
     *
     * @param int $tenantId
     * @param int $tokensConsumed
     * @param string $operation (e.g., 'document.upload', 'chat.ask')
     * @param array $metadata
     */
    public static function trackTokens(
        int $tenantId,
        int $tokensConsumed,
        string $operation,
        array $metadata = []
    ): void {
        if ($tokensConsumed <= 0) {
            return;
        }

        $currentMonth = now()->format('Y-m');

        $usage = UsageMonthly::query()
            ->where('tenant_id', $tenantId)
            ->where('month', 'LIKE', $currentMonth.'%')
            ->first();

        if ($usage) {
            $usage->increment('tokens_used', $tokensConsumed);
            $usage->increment('requests_count');
        } else {
            UsageMonthly::create([
                'tenant_id' => $tenantId,
                'month' => now()->format('Y-m-01'),
                'tokens_used' => $tokensConsumed,
                'requests_count' => 1,
            ]);
        }

        BillingEvent::create([
            'tenant_id' => $tenantId,
            'event_type' => 'token_consumed',
            'payload' => [
                'amount_cents' => 0,
                'tokens' => $tokensConsumed,
                'description' => $operation,
                'metadata' => $metadata,
            ],
        ]);

        self::checkOverage($tenantId);
    }

    /**
     * Track requests (rate limiting).
     */
    public static function trackRequest(int $tenantId): void
    {
        $currentMonth = now()->format('Y-m');

        $usage = UsageMonthly::query()
            ->where('tenant_id', $tenantId)
            ->where('month', 'LIKE', $currentMonth.'%')
            ->first();

        if ($usage) {
            $usage->increment('requests_count');
        } else {
            UsageMonthly::create([
                'tenant_id' => $tenantId,
                'month' => now()->format('Y-m-01'),
                'tokens_used' => 0,
                'requests_count' => 1,
            ]);
        }
    }

    /**
     * Get usage for current month.
     */
    public static function getCurrentMonthUsage(int $tenantId): array
    {
        $currentMonth = now()->format('Y-m');

        $usage = UsageMonthly::query()
            ->where('tenant_id', $tenantId)
            ->where('month', 'LIKE', $currentMonth.'%')
            ->first();

        return [
            'tokens_used' => $usage?->tokens_used ?? 0,
            'requests_count' => $usage?->requests_count ?? 0,
            'month' => $currentMonth,
        ];
    }

    /**
     * Get usage percentage.
     */
    public static function getUsagePercentage(int $tenantId, int $monthlyLimit): float
    {
        $usage = self::getCurrentMonthUsage($tenantId);
        return ($usage['tokens_used'] / $monthlyLimit) * 100;
    }

    /**
     * Check overage and create event if needed.
     */
    private static function checkOverage(int $tenantId): void
    {
        $tenant = \App\Models\Tenant::find($tenantId);

        if (!$tenant?->subscription) {
            return;
        }

        $currentUsage = self::getCurrentMonthUsage($tenantId);
        $monthlyLimit = $tenant->subscription->plan->monthly_token_limit;

        if ($currentUsage['tokens_used'] > $monthlyLimit) {
            $overageTokens = $currentUsage['tokens_used'] - $monthlyLimit;

            if ($tenant->subscription->plan->overage_allowed) {
                $overagePrice = $overageTokens * 10;

                BillingEvent::create([
                    'tenant_id' => $tenantId,
                    'event_type' => 'overage_charged',
                    'payload' => [
                        'amount_cents' => $overagePrice,
                        'tokens' => $overageTokens,
                        'description' => 'Overage tokens charged',
                    ],
                ]);
            }
        }
    }

    /**
     * Reset monthly usage (call via scheduled job).
     */
    public static function resetMonthlyUsage(int $tenantId): void
    {
        $previousMonth = now()->subMonth()->format('Y-m');

        UsageMonthly::query()
            ->where('tenant_id', $tenantId)
            ->where('month', 'LIKE', $previousMonth.'%')
            ->update(['tokens_used' => 0, 'requests_count' => 0]);
    }
}
