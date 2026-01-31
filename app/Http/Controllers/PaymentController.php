<?php

namespace App\Http\Controllers;

use App\Services\MercadoPagoService;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    public function __construct(private MercadoPagoService $mercadopago)
    {}

    /**
     * POST /api/payments/create
     * 
     * Criar novo pagamento (PIX, Cartão Crédito, Cartão Débito)
     * 
     * Request JSON:
     * {
     *   "plan_id": "2",
     *   "payment_method": "pix",  // ou "credit_card" | "debit_card"
     *   "token": "token_mp",        // OBRIGATÓRIO para cartão (gerado pelo SDK MP)
     *   "card_brand": "visa",       // (opcional) visa, mastercard
     *   "installments": 1,          // (opcional, default 1)
     *   "idempotency_key": "uuid"   // (opcional, gerado automaticamente se vazio)
     * }
     */
    public function createPayment(Request $request)
    {
        $validated = $request->validate([
            'plan_id' => 'required|exists:plans,id',
            'payment_method' => 'required|in:pix,credit_card,debit_card',
            'token' => 'nullable|string',
            'card_brand' => 'nullable|in:visa,mastercard,elo,amex,debelo',
            'installments' => 'nullable|integer|min:1|max:12',
            'idempotency_key' => 'nullable|string',
        ]);

        $user = auth()->user();
        $tenant = $user->tenant;
        $plan = Plan::findOrFail($validated['plan_id']);

        // Verificar se já tem subscription ativa
        $existingSubscription = $tenant->subscriptions()
            ->where('status', 'active')
            ->whereDate('period_end', '>=', now())
            ->first();

        if ($existingSubscription) {
            return response()->json([
                'status' => 'error',
                'message' => 'Você já possui uma assinatura ativa',
                'current_plan' => $existingSubscription->plan->name,
            ], 400);
        }

        // Validar token para cartão
        if (in_array($validated['payment_method'], ['credit_card', 'debit_card']) && empty($validated['token'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Token de cartão obrigatório. Use Mercado Pago SDK para tokenizar.',
            ], 422);
        }

        // Criar pagamento
        $result = $this->mercadopago->createPayment(
            paymentMethod: $validated['payment_method'],
            amountInCents: $plan->price_cents,
            tenantId: $tenant->id,
            email: $user->email,
            token: $validated['token'] ?? null,
            options: [
                'idempotency_key' => $validated['idempotency_key'] ?? null,
                'installments' => $validated['installments'] ?? 1,
                'card_brand' => $validated['card_brand'] ?? null,
                'payer_first_name' => $user->name ?? 'Cliente',
            ]
        );

        if ($result['status'] === 'error') {
            Log::warning('Payment creation failed', [
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'method' => $validated['payment_method'],
                'error' => $result['message'],
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $result['message'],
                'code' => $result['code'] ?? null,
            ], 422);
        }

        $orderId = $result['order_id'];

        // Criar subscription em estado "pending" (será ativada pelo webhook ou /debug)
        $subscription = Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => $validated['payment_method'] === 'pix' ? 'pending' : 'pending',
            'period_start' => now(),
            'period_end' => now()->addMonth(),
            'next_billing_date' => now()->addMonth(),
            'external_payment_id' => $orderId,
        ]);

        Log::info('Payment created', [
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'order_id' => $orderId,
            'method' => $validated['payment_method'],
            'amount' => $plan->price_cents / 100,
        ]);

        // Adicionar subscription_id à resposta
        $result['subscription_id'] = $subscription->id;
        $result['plan_name'] = $plan->name;

        $statusCode = $result['status'] === 'pending' ? 202 : 200;
        return response()->json($result, $statusCode);
    }

    /**
     * GET /api/payments/{payment_id}/status
     * 
     * Verificar status de um pagamento
     */
    public function getPaymentStatus(string $paymentId)
    {
        $user = auth()->user();
        $tenant = $user->tenant;

        // Verificar se o pagamento pertence ao tenant
        $subscription = Subscription::where('tenant_id', $tenant->id)
            ->where('external_payment_id', $paymentId)
            ->first();

        if (!$subscription) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pagamento não encontrado',
            ], 404);
        }

        $paymentStatus = $this->mercadopago->getPaymentStatus($paymentId);

        if ($paymentStatus['status'] !== 'success') {
            return response()->json([
                'status' => 'error',
                'message' => $paymentStatus['message'],
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'payment_id' => $paymentStatus['id'],
            'payment_status' => $paymentStatus['payment_status'],
            'amount' => $paymentStatus['amount'],
            'currency' => 'BRL',
            'subscription_status' => $subscription->status,
            'plan_name' => $subscription->plan->name,
        ], 200);
    }

    /**
     * GET /api/subscription
     * 
     * Obter subscription ativa do tenant
     * (Já existe, mas documentando aqui para referência)
     */
    public function getCurrentSubscription()
    {
        $user = auth()->user();
        $tenant = $user->tenant;

        $subscription = $tenant->subscriptions()
            ->where('status', 'active')
            ->whereDate('period_end', '>=', now())
            ->with('plan')
            ->first();

        if (!$subscription) {
            return response()->json([
                'status' => 'error',
                'message' => 'Nenhuma assinatura ativa',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'subscription' => [
                'id' => $subscription->id,
                'plan' => $subscription->plan->name,
                'monthly_tokens' => $subscription->plan->monthly_token_limit,
                'period_start' => $subscription->period_start->toIso8601String(),
                'period_end' => $subscription->period_end->toIso8601String(),
                'status' => $subscription->status,
            ],
        ], 200);
    }

    /**
     * GET /api/config/payment
     * 
     * Retorna configuração de pagamento para o frontend
     * (Public Key do Mercado Pago, etc)
     */
    public function getConfig()
    {
        return response()->json([
            'mercadopago_public_key' => config('services.mercadopago.public_key'),
            'mercadopago_sandbox' => str_starts_with(config('services.mercadopago.access_token'), 'TEST-'),
            'environment' => config('app.env'),
        ], 200);
    }

    /**
     * GET /api/payments/debug/{payment_id}
     * 
     * DEBUG ONLY - Simular webhook localmente (para testes)
     * Remove em produção!
     */
    public function debugWebhook(string $paymentId)
    {
        if (!config('app.debug')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Not available in production',
            ], 403);
        }

        $result = $this->mercadopago->debugTestWebhook($paymentId);

        return response()->json($result, 200);
    }
}
