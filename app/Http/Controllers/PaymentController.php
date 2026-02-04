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
        $traceId = (string) \Illuminate\Support\Str::uuid();

        $validated = $request->validate([
            'plan_id' => 'required|exists:plans,id',
            'payment_method' => 'required|in:pix,credit_card,debit_card',
            'token' => 'nullable|string',
            'card_brand' => 'nullable|in:visa,mastercard,elo,amex,debelo',
            'installments' => 'nullable|integer|min:1|max:12',
            'idempotency_key' => 'nullable|string',
            'description' => 'nullable|string',
            'additional_info' => 'nullable|array',
            'payer' => 'nullable|array',
            'payer.email' => 'nullable|email',
            'payer.first_name' => 'nullable|string',
            'payer.last_name' => 'nullable|string',
            'payer.entity_type' => 'nullable|in:individual,association',
            'payer.identification' => 'nullable|array',
            'payer.identification.type' => 'nullable|in:CPF,CNPJ',
            'payer.identification.number' => 'nullable|string',
            'payer.phone' => 'nullable|array',
            'payer.phone.area_code' => 'nullable|string',
            'payer.phone.number' => 'nullable|string',
            'payer.address' => 'nullable|array',
            'payer.address.zip_code' => 'nullable|string',
            'payer.address.street_name' => 'nullable|string',
            'payer.address.street_number' => 'nullable|string',
            'payer.address.neighborhood' => 'nullable|string',
            'payer.address.state' => 'nullable|string',
            'payer.address.city' => 'nullable|string',
            'payer.address.complement' => 'nullable|string',
        ]);

        $user = auth()->user();
        $tenant = $user->tenant;
        $plan = Plan::findOrFail($validated['plan_id']);

        Log::info('Payment create request', [
            'trace_id' => $traceId,
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'payment_method' => $validated['payment_method'],
            'installments' => $validated['installments'] ?? 1,
            'payer_override' => !empty($validated['payer'] ?? null),
        ]);

        // Verificar se já tem subscription ativa
        $existingSubscription = Subscription::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->where('current_period_end', '>=', now())
            ->first();

        if ($existingSubscription) {
            return response()->json([
                'status' => 'error',
                'message' => 'Você já possui uma assinatura ativa',
                'current_plan' => $existingSubscription->plan->name,
                'trace_id' => $traceId,
            ], 400);
        }

        // Validar token para cartão
        if (in_array($validated['payment_method'], ['credit_card', 'debit_card']) && empty($validated['token'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'Token de cartão obrigatório. Use Mercado Pago SDK para tokenizar.',
                'trace_id' => $traceId,
            ], 422);
        }

        // Criar pagamento
        $payer = $validated['payer'] ?? [];
        $payer['email'] = $payer['email'] ?? $user->email;
        $payer['first_name'] = $payer['first_name'] ?? ($user->name ?? 'Cliente');

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
                'description' => $validated['description'] ?? null,
                'additional_info' => $validated['additional_info'] ?? null,
                'payer' => $payer,
                'trace_id' => $traceId,
            ]
        );

        if ($result['status'] === 'error') {
            Log::warning('Payment creation failed', [
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'method' => $validated['payment_method'],
                'error' => $result['message'],
                'code' => $result['code'] ?? null,
                'trace_id' => $traceId,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $result['message'],
                'code' => $result['code'] ?? null,
                'trace_id' => $traceId,
            ], 422);
        }

        $paymentId = $result['payment_id'];

        // Criar subscription em estado "pending" (será ativada pelo webhook ou /debug)
        $subscription = Subscription::create([
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'status' => $validated['payment_method'] === 'pix' ? 'pending' : 'pending',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
            'next_billing_date' => now()->addMonth(),
            'external_payment_id' => $paymentId,
        ]);

        Log::info('Payment created', [
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'payment_id' => $paymentId,
            'method' => $validated['payment_method'],
            'amount' => $plan->price_cents / 100,
            'trace_id' => $traceId,
        ]);

        // Adicionar subscription_id à resposta
        $result['subscription_id'] = $subscription->id;
        $result['plan_name'] = $plan->name;
        $result['trace_id'] = $traceId;

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
            'payment_id' => $paymentStatus['payment_id'],
            'payment_status' => $paymentStatus['payment_status'],
            'amount' => $paymentStatus['amount'],
            'currency' => 'BRL',
            'subscription_status' => $subscription->status,
            'plan_name' => $subscription->plan->name,
            'mp_payment' => $paymentStatus,
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

        $subscription = Subscription::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->where('current_period_end', '>=', now())
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
                'period_start' => $subscription->current_period_start->toIso8601String(),
                'period_end' => $subscription->current_period_end->toIso8601String(),
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
