<?php

namespace App\Http\Controllers;

use App\Models\FaqCache;
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
            ->get();

        return response()->json($usage);
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
