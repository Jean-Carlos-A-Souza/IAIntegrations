<?php

namespace App\Http\Controllers;

use App\Models\FaqCache;
use App\Models\Plan;
use App\Models\QuestionEvent;
use App\Models\Subscription;
use App\Models\UsageMonthly;
use App\Services\TenantContext;
use Carbon\Carbon;
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

    public function questionsSummary()
    {
        $tenant = TenantContext::getTenant();

        $baseQuery = $this->questionEventsQuery($tenant->id);

        $uniqueQuestions = (clone $baseQuery)
            ->select('question_normalized')
            ->distinct()
            ->count('question_normalized');

        $totalQuestions = (clone $baseQuery)->count();

        $mostAsked = (clone $baseQuery)
            ->select('question_normalized', DB::raw('count(*) as hits'))
            ->groupBy('question_normalized')
            ->orderByDesc('hits')
            ->first();

        $topQuestions = (clone $baseQuery)
            ->select('question_normalized', DB::raw('count(*) as hits'))
            ->groupBy('question_normalized')
            ->orderByDesc('hits')
            ->limit(10)
            ->get();

        return response()->json([
            'unique_questions' => $uniqueQuestions,
            'total_questions' => (int) $totalQuestions,
            'most_asked' => $mostAsked?->question_normalized,
            'most_asked_hits' => (int) ($mostAsked?->hits ?? 0),
            'top_questions' => $topQuestions,
        ]);
    }

    public function allQuestions()
    {
        $tenant = TenantContext::getTenant();

        $results = $this->questionEventsQuery($tenant->id)
            ->select('question_normalized', DB::raw('count(*) as hits'))
            ->groupBy('question_normalized')
            ->orderByDesc('hits')
            ->get();

        return response()->json($results);
    }

    public function questionsTimeseries()
    {
        $tenant = TenantContext::getTenant();

        $results = $this->questionEventsQuery($tenant->id)
            ->select(DB::raw("DATE(created_at) as date"), DB::raw('count(*) as count'))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy(DB::raw('DATE(created_at)'))
            ->get();

        return response()->json($results);
    }

    private function questionEventsQuery(int $tenantId)
    {
        $query = QuestionEvent::query()->where('tenant_id', $tenantId);

        $start = request()->query('start_date');
        $end = request()->query('end_date');

        if ($start) {
            $query->where('created_at', '>=', Carbon::parse($start)->startOfDay());
        }
        if ($end) {
            $query->where('created_at', '<=', Carbon::parse($end)->endOfDay());
        }

        return $query;
    }
}
