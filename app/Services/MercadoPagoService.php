<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Subscription;
use App\Models\Plan;
use App\Models\BillingEvent;
use Exception;
use Illuminate\Support\Str;
use stdClass;

class MercadoPagoService
{
    private string $accessToken;
    private string $baseUrl = 'https://api.mercadopago.com';
    private string $sandboxUrl = 'https://api.sandbox.mercadopago.com';
    private bool $isSandbox;

    public function __construct()
    {
        $this->accessToken = config('services.mercadopago.access_token');
        $this->isSandbox = str_contains($this->accessToken, 'TEST-');
    }

    /**
     * Obter URL base conforme ambiente
     */
    private function getBaseUrl(): string
    {
        return $this->isSandbox ? $this->sandboxUrl : $this->baseUrl;
    }

    /**
     * Gerar idempotency key (UUID v4) se não fornecido
     */
    private function getIdempotencyKey(?string $key = null): string
    {
        return $key ?? (string) Str::uuid();
    }

    /**
     * Criar ordem usando /v1/orders (novo modelo Mercado Pago)
     * Suporta: PIX, Cartão Crédito, Cartão Débito
     *
     * @param string $paymentMethod 'pix' | 'credit_card' | 'debit_card'
     * @param int $amountInCents Valor em centavos
     * @param string $tenantId Tenant UUID
     * @param string $email Email do cliente
     * @param ?string $token Token do cartão (gerado por SDK do MP)
     * @param array $options idempotency_key, installments, payer_first_name, etc
     * 
     * @return array ['status' => 'success'|'error'|'pending', ...]
     */
    public function createPayment(
        string $paymentMethod,
        int $amountInCents,
        string $tenantId,
        string $email,
        ?string $token = null,
        array $options = []
    ): array {
        try {
            $amount = number_format($amountInCents / 100, 2, '.', '');
            $idempotencyKey = $this->getIdempotencyKey($options['idempotency_key'] ?? null);

            // Determinar tipo de pagamento e method_id
            $paymentMethodId = match ($paymentMethod) {
                'pix' => 'pix',
                'credit_card' => $options['card_brand'] ?? 'visa',
                'debit_card' => $options['card_brand'] ?? 'debelo',
                default => 'visa',
            };

            // Estrutura base da ordem
            $payload = [
                'type' => 'online',
                'external_reference' => $tenantId,
                'total_amount' => $amount,
                'transactions' => [
                    'payments' => [
                        [
                            'amount' => $amount,
                            'payment_method' => [
                                'id' => $paymentMethodId,
                                'type' => $this->getPaymentType($paymentMethod),
                            ],
                        ],
                    ],
                ],
                'payer' => [
                    'email' => $email,
                    'first_name' => $options['payer_first_name'] ?? 'Cliente',
                ],
                'processing_mode' => 'automatic',
            ];

            // Adicionar token se cartão
            if (in_array($paymentMethod, ['credit_card', 'debit_card']) && $token) {
                $payload['transactions']['payments'][0]['payment_method']['token'] = $token;
                $payload['transactions']['payments'][0]['payment_method']['installments'] = 
                    $options['installments'] ?? 1;
            }

            // PIX: adicionar expiração
            if ($paymentMethod === 'pix') {
                $payload['transactions']['payments'][0]['expiration_time'] = 
                    $options['expiration_time'] ?? 'P1D';
            }

            // Para testes: APRO ativa PIX automático no sandbox
            if ($this->isSandbox && $paymentMethod === 'pix') {
                $payload['payer']['first_name'] = 'APRO';
            }

            $request = Http::withToken($this->accessToken)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Idempotency-Key' => $idempotencyKey,
                ]);

            $response = $request->post("{$this->getBaseUrl()}/v1/orders", $payload);

            if ($response->failed()) {
                Log::error('MercadoPago Order Error', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                    'method' => $paymentMethod,
                ]);

                return [
                    'status' => 'error',
                    'message' => $response->json()['message'] ?? 'Erro ao processar pagamento',
                    'code' => $response->json()['code'] ?? null,
                ];
            }

            $data = $response->json();
            return $this->parseOrderResponse($data, $paymentMethod);

        } catch (Exception $e) {
            Log::error('MercadoPago Exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Erro ao conectar com Mercado Pago',
            ];
        }
    }

    /**
     * Mapear tipo de pagamento
     */
    private function getPaymentType(string $paymentMethod): string
    {
        return match ($paymentMethod) {
            'pix' => 'bank_transfer',
            'credit_card' => 'credit_card',
            'debit_card' => 'debit_card',
            default => 'credit_card',
        };
    }

    /**
     * Parsear resposta da ordem conforme tipo de pagamento
     */
    private function parseOrderResponse(array $data, string $paymentMethod): array
    {
        $orderId = $data['id'] ?? null;
        $status = $data['status'] ?? null;
        $statusDetail = $data['status_detail'] ?? null;
        $totalAmount = $data['total_amount'] ?? 0;

        // Extrair primeiro pagamento
        $payment = $data['transactions']['payments'][0] ?? [];
        $paymentId = $payment['id'] ?? null;
        $paymentStatus = $payment['status'] ?? null;
        $paymentMethodData = $payment['payment_method'] ?? [];

        // PIX: retornar QR Code
        if ($paymentMethodData['type'] === 'bank_transfer') {
            return [
                'status' => 'success',
                'order_id' => $orderId,
                'payment_id' => $paymentId,
                'order_status' => $status,
                'order_status_detail' => $statusDetail,
                'amount' => $totalAmount,
                'currency' => 'BRL',
                'payment_method' => 'pix',
                'qr_code' => $paymentMethodData['qr_code'] ?? null,
                'qr_code_base64' => $paymentMethodData['qr_code_base64'] ?? null,
                'ticket_url' => $paymentMethodData['ticket_url'] ?? null,
                'expiration_time' => $paymentMethodData['date_of_expiration'] ?? null,
                'message' => 'QR Code PIX gerado. Escaneie com seu banco.',
            ];
        }

        // Cartão: retornar status direto
        if (in_array($paymentMethodData['type'] ?? null, ['credit_card', 'debit_card'])) {
            $success = in_array($paymentStatus, ['processed', 'approved']);

            return [
                'status' => $success ? 'success' : ($paymentStatus === 'pending' ? 'pending' : 'error'),
                'order_id' => $orderId,
                'payment_id' => $paymentId,
                'order_status' => $status,
                'payment_status' => $paymentStatus,
                'payment_status_detail' => $payment['status_detail'] ?? null,
                'amount' => $totalAmount,
                'currency' => 'BRL',
                'payment_method' => $paymentMethodData['type'] ?? 'unknown',
                'message' => $this->getCardMessage($paymentStatus),
            ];
        }

        return [
            'status' => 'success',
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'order_status' => $status,
            'amount' => $totalAmount,
        ];
    }

    /**
     * Mensagem conforme status do cartão
     */
    private function getCardMessage(string $status): string
    {
        return match ($status) {
            'processed', 'approved' => '✅ Pagamento aprovado! Sua assinatura está ativa.',
            'pending' => '⏳ Pagamento em análise. Você receberá notificação em breve.',
            'rejected' => '❌ Pagamento recusado. Verifique os dados do cartão.',
            default => 'Processando pagamento...',
        };
    }

    /**
     * Obter status de uma ordem
     */
    public function getPaymentStatus(string $orderId): array
    {
        try {
            $response = Http::withToken($this->accessToken)
                ->get("{$this->getBaseUrl()}/v1/orders/$orderId");

            if ($response->failed()) {
                return [
                    'status' => 'error',
                    'message' => 'Ordem não encontrada',
                ];
            }

            $data = $response->json();
            $payment = $data['transactions']['payments'][0] ?? [];

            return [
                'status' => 'success',
                'order_id' => $data['id'],
                'order_status' => $data['status'],
                'order_status_detail' => $data['status_detail'] ?? null,
                'payment_id' => $payment['id'] ?? null,
                'payment_status' => $payment['status'] ?? null,
                'amount' => $data['total_amount'],
                'payment_method' => $payment['payment_method']['id'] ?? null,
                'payment_method_type' => $payment['payment_method']['type'] ?? null,
            ];
        } catch (Exception $e) {
            Log::error('MercadoPago Get Order Error', [
                'id' => $orderId,
                'message' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Erro ao buscar status da ordem',
            ];
        }
    }

    /**
     * Processar webhook do Mercado Pago
     */
    public function handleWebhook(array $webhookData): void
    {
        $type = $webhookData['type'] ?? null;
        $dataId = $webhookData['data']['id'] ?? null;

        Log::info('MercadoPago Webhook Received', [
            'type' => $type,
            'data_id' => $dataId,
        ]);

        if ($type !== 'payment') {
            Log::info('Webhook ignored - not a payment event');
            return;
        }

        $paymentStatus = $this->getPaymentStatus($dataId);

        if ($paymentStatus['status'] !== 'success') {
            Log::warning('Could not fetch order details', ['order_id' => $dataId]);
            return;
        }

        // Apenas processar pagamentos aprovados
        if (!in_array($paymentStatus['payment_status'], ['processed', 'approved'])) {
            Log::info('Payment not approved', [
                'order_id' => $dataId,
                'status' => $paymentStatus['payment_status'],
            ]);
            return;
        }

        // Encontrar subscription pelo order_id
        $subscription = Subscription::where('external_payment_id', $paymentStatus['order_id'])
            ->orWhere('external_payment_id', $dataId)
            ->first();

        if (!$subscription) {
            Log::warning('Webhook: subscription not found', ['order_id' => $dataId]);
            return;
        }

        // Atualizar subscription
        $subscription->update([
            'status' => 'active',
            'period_start' => now(),
            'period_end' => now()->addMonth(),
            'next_billing_date' => now()->addMonth(),
        ]);

        // Registrar evento de billing
        BillingEvent::create([
            'tenant_id' => $subscription->tenant_id,
            'type' => 'payment_received',
            'amount_cents' => (int)($paymentStatus['amount'] * 100),
            'external_id' => $paymentStatus['order_id'],
            'metadata' => [
                'payment_id' => $paymentStatus['payment_id'],
                'payment_method' => $paymentStatus['payment_method'],
                'webhook_received_at' => now()->toIso8601String(),
            ],
        ]);

        Log::info('Subscription activated via webhook', [
            'tenant_id' => $subscription->tenant_id,
            'order_id' => $dataId,
            'amount' => $paymentStatus['amount'],
        ]);
    }

    /**
     * DEBUG: simular webhook (apenas em APP_DEBUG=true)
     */
    public function debugTestWebhook(string $orderId): array
    {
        $payment = $this->getPaymentStatus($orderId);

        if ($payment['status'] !== 'success') {
            return ['error' => 'Order not found'];
        }

        $this->handleWebhook([
            'type' => 'payment',
            'action' => 'payment.updated',
            'data' => [
                'id' => $orderId,
            ],
        ]);

        return [
            'message' => 'Webhook simulated',
            'order' => $payment,
        ];
    }
}
