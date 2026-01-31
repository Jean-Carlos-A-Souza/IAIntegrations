<?php

namespace App\Services;

use App\Models\UsageMonthly;
use App\Models\BillingEvent;
use Illuminate\Support\Carbon;

class TokenTracker
{
    /**
     * Rastreia tokens consumidos por uma operação
     *
     * @param int $tenantId
     * @param int $tokensConsumed
     * @param string $operation (e.g., 'document.upload', 'chat.ask')
     * @param array $metadata Dados adicionais
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

        // Atualizar ou criar UsageMonthly
        $usage = UsageMonthly::query()
            ->where('tenant_id', $tenantId)
            ->where('month', 'LIKE', $currentMonth . '%')
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

        // Criar evento de billing para auditoria
        BillingEvent::create([
            'tenant_id' => $tenantId,
            'type' => 'token_consumed',
            'amount_cents' => 0, // Será calculado depois se houver overage
            'tokens' => $tokensConsumed,
            'description' => $operation,
            'metadata' => $metadata,
        ]);

        // Verificar se atingiu overage
        self::checkOverage($tenantId);
    }

    /**
     * Rastreia requisições (rate limiting)
     */
    public static function trackRequest(int $tenantId): void
    {
        $currentMonth = now()->format('Y-m');

        $usage = UsageMonthly::query()
            ->where('tenant_id', $tenantId)
            ->where('month', 'LIKE', $currentMonth . '%')
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
     * Retorna uso do mês atual
     */
    public static function getCurrentMonthUsage(int $tenantId): array
    {
        $currentMonth = now()->format('Y-m');

        $usage = UsageMonthly::query()
            ->where('tenant_id', $tenantId)
            ->where('month', 'LIKE', $currentMonth . '%')
            ->first();

        return [
            'tokens_used' => $usage?->tokens_used ?? 0,
            'requests_count' => $usage?->requests_count ?? 0,
            'month' => $currentMonth,
        ];
    }

    /**
     * Retorna porcentagem de uso
     */
    public static function getUsagePercentage(int $tenantId, int $monthlyLimit): float
    {
        $usage = self::getCurrentMonthUsage($tenantId);
        return ($usage['tokens_used'] / $monthlyLimit) * 100;
    }

    /**
     * Verifica se atingiu overage e cria evento
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

            // Se permitir overage, criar evento
            if ($tenant->subscription->plan->overage_allowed) {
                // Preço por token extra (exemplo: 0.01 por token)
                $overagePrice = $overageTokens * 10; // 10 centavos por token extra

                BillingEvent::create([
                    'tenant_id' => $tenantId,
                    'type' => 'overage_charged',
                    'amount_cents' => $overagePrice,
                    'tokens' => $overageTokens,
                    'description' => 'Overage tokens charged',
                ]);
            }
        }
    }

    /**
     * Reseta uso do mês (deve ser chamado via job agendado)
     */
    public static function resetMonthlyUsage(int $tenantId): void
    {
        $previousMonth = now()->subMonth()->format('Y-m');

        UsageMonthly::query()
            ->where('tenant_id', $tenantId)
            ->where('month', 'LIKE', $previousMonth . '%')
            ->update(['tokens_used' => 0, 'requests_count' => 0]);
    }
}
