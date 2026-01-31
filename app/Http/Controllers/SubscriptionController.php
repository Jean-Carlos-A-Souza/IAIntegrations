<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Subscription;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionController extends Controller
{
    /**
     * Listar planos disponíveis
     */
    public function listPlans()
    {
        $plans = Plan::all();

        return response()->json($plans);
    }

    /**
     * Pegar subscription atual do tenant
     */
    public function getCurrent(Request $request)
    {
        $tenant = TenantContext::getTenant();

        $subscription = $tenant->subscription()
            ->with('plan')
            ->first();

        if (!$subscription) {
            return response()->json([
                'message' => 'No active subscription',
                'subscription' => null,
                'plans' => Plan::all(),
            ], 200);
        }

        return response()->json($subscription);
    }

    /**
     * Assinar um plano
     */
    public function subscribe(Request $request)
    {
        $request->validate([
            'plan_id' => 'required|exists:plans,id',
        ]);

        $tenant = TenantContext::getTenant();
        $planId = $request->input('plan_id');

        DB::transaction(function () use ($tenant, $planId) {
            // Cancelar subscription anterior se houver
            if ($tenant->subscription) {
                $tenant->subscription()->update([
                    'status' => 'canceled',
                    'cancel_at' => now(),
                ]);
            }

            // Criar nova subscription
            Subscription::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $planId,
                'status' => 'active',
                'current_period_start' => now(),
                'current_period_end' => now()->addMonth(),
            ]);
        });

        return response()->json([
            'message' => 'Subscribed successfully',
            'subscription' => $tenant->subscription()->with('plan')->first(),
        ], 201);
    }

    /**
     * Cancelar subscription
     */
    public function cancel(Request $request)
    {
        $tenant = TenantContext::getTenant();

        $subscription = $tenant->subscription();

        if (!$subscription) {
            return response()->json([
                'message' => 'No active subscription to cancel',
            ], 404);
        }

        $subscription->update([
            'status' => 'canceled',
            'cancel_at' => now(),
        ]);

        return response()->json([
            'message' => 'Subscription canceled',
            'subscription' => $subscription,
        ]);
    }

    /**
     * Renovar subscription (simula renovação)
     */
    public function renew(Request $request)
    {
        $tenant = TenantContext::getTenant();

        $subscription = $tenant->subscription();

        if (!$subscription) {
            return response()->json([
                'message' => 'No active subscription to renew',
            ], 404);
        }

        $subscription->update([
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
            'cancel_at' => null,
        ]);

        return response()->json([
            'message' => 'Subscription renewed',
            'subscription' => $subscription,
        ]);
    }
}
