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
    private string $sandboxUrl = 'https://api.mercadopago.com';
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
     * Criar pagamento usando /v1/payments
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
            $traceId = $options['trace_id'] ?? (string) Str::uuid();
            $payer = $options['payer'] ?? [];
            $payer['email'] = $payer['email'] ?? $email;
            $payer['first_name'] = $payer['first_name'] ?? ($options['payer_first_name'] ?? 'Cliente');

            // Determinar tipo de pagamento e method_id (cartão só se brand informado)
            $paymentMethodId = null;
            if ($paymentMethod === 'pix') {
                $paymentMethodId = 'pix';
            } elseif (in_array($paymentMethod, ['credit_card', 'debit_card'], true)) {
                $paymentMethodId = $options['card_brand'] ?? null;
            }

            $payload = [
                'transaction_amount' => (float) $amount,
                'description' => $options['description'] ?? 'Subscription payment',
                'external_reference' => $tenantId,
                'payer' => $this->filterNulls($payer),
                'installments' => $options['installments'] ?? 1,
            ];
            if ($paymentMethodId) {
                $payload['payment_method_id'] = $paymentMethodId;
            }

            if (!empty($options['additional_info']) && is_array($options['additional_info'])) {
                $payload['additional_info'] = $options['additional_info'];
            }

            // Adicionar token se cartão
            if (in_array($paymentMethod, ['credit_card', 'debit_card']) && $token) {
                $payload['token'] = $token;
            }

            // Para testes: APRO ativa PIX automático no sandbox
            if ($this->isSandbox && $paymentMethod === 'pix') {
                $payload['payer']['first_name'] = 'APRO';
            }

            Log::info('MercadoPago create payment request', [
                'trace_id' => $traceId,
                'payment_method' => $paymentMethod,
                'payment_method_id' => $paymentMethodId,
                'amount' => $amount,
                'tenant_id' => $tenantId,
                'is_sandbox' => $this->isSandbox,
                'base_url' => $this->getBaseUrl(),
                'idempotency_key' => $idempotencyKey,
                'payer_has_identification' => !empty($payer['identification'] ?? null),
            ]);

            $request = Http::withToken($this->accessToken)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Idempotency-Key' => $idempotencyKey,
                ]);

            $response = $request->post("{$this->getBaseUrl()}/v1/payments", $payload);

            if ($response->failed()) {
                Log::error('MercadoPago Payment Error', [
                    'trace_id' => $traceId,
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
            Log::info('MercadoPago create payment response', [
                'trace_id' => $traceId,
                'status' => $response->status(),
                'payment_id' => $data['id'] ?? null,
                'payment_status' => $data['status'] ?? null,
                'payment_status_detail' => $data['status_detail'] ?? null,
            ]);
            return $this->parsePaymentResponseV1($data, $paymentMethod);

        } catch (Exception $e) {
            Log::error('MercadoPago Exception', [
                'trace_id' => $traceId ?? null,
                'exception' => get_class($e),
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Erro ao conectar com Mercado Pago',
            ];
        }
    }

    /**
     * Remover valores nulos/arrays vazios do payload (recursivo)
     */
    private function filterNulls(array $data): array
    {
        $filtered = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $nested = $this->filterNulls($value);
                if ($nested !== []) {
                    $filtered[$key] = $nested;
                }
                continue;
            }

            if ($value !== null && $value !== '') {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * Parsear resposta do payment conforme tipo de pagamento
     */
    private function parsePaymentResponseV1(array $data, string $paymentMethod): array
    {
        $paymentId = $data['id'] ?? null;
        $status = $data['status'] ?? null;
        $statusDetail = $data['status_detail'] ?? null;
        $amount = $data['transaction_amount'] ?? 0;
        $paymentMethodId = $data['payment_method_id'] ?? null;
        $paymentTypeId = $data['payment_type_id'] ?? null;

        $isApproved = in_array($status, ['approved', 'authorized', 'processed'], true);
        $isPending = in_array($status, ['pending', 'in_process'], true);
        $resultStatus = $isApproved ? 'success' : ($isPending ? 'pending' : 'error');

        $response = [
            'status' => $resultStatus,
            'payment_id' => $paymentId,
            'payment_status' => $status,
            'payment_status_detail' => $statusDetail,
            'amount' => $amount,
            'currency' => $data['currency_id'] ?? 'BRL',
            'payment_method' => $paymentMethodId,
            'payment_type' => $paymentTypeId,
        ];

        // PIX: retornar QR Code
        if ($paymentMethod === 'pix' || $paymentTypeId === 'bank_transfer') {
            $transactionData = $data['point_of_interaction']['transaction_data'] ?? [];
            $response['qr_code'] = $transactionData['qr_code'] ?? null;
            $response['qr_code_base64'] = $transactionData['qr_code_base64'] ?? null;
            $response['ticket_url'] = $transactionData['ticket_url'] ?? null;
            $response['message'] = 'QR Code PIX gerado. Escaneie com seu banco.';
        } else {
            $response['message'] = $this->getCardMessage($status ?? 'unknown');
        }

        return $response;
    }

    /**
     * Parsear resposta da ordem conforme tipo de pagamento
     */
    private function parsePaymentResponse(array $data, string $paymentMethod): array
    {
        return $this->parsePaymentResponseV1($data, $paymentMethod);

        $paymentId = $data['id'] ?? null;
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
     * Obter status de um pagamento
     */
    public function getPaymentStatus(string $paymentId): array
    {
        try {
            Log::info('MercadoPago get payment request', [
                'payment_id' => $paymentId,
                'is_sandbox' => $this->isSandbox,
                'base_url' => $this->getBaseUrl(),
            ]);

            $response = Http::withToken($this->accessToken)
                ->get("{$this->getBaseUrl()}/v1/payments/$paymentId");

            if ($response->failed()) {
                Log::warning('MercadoPago get payment failed', [
                    'payment_id' => $paymentId,
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
                return [
                    'status' => 'error',
                    'message' => 'Pagamento não encontrado',
                ];
            }

            $data = $response->json();
            Log::info('MercadoPago get payment response', [
                'payment_id' => $data['id'] ?? null,
                'payment_status' => $data['status'] ?? null,
                'payment_status_detail' => $data['status_detail'] ?? null,
            ]);

            return [
                'status' => 'success',
                'payment_id' => $data['id'],
                'payment_status' => $data['status'],
                'payment_status_detail' => $data['status_detail'] ?? null,
                'amount' => $data['transaction_amount'],
                'currency' => $data['currency_id'] ?? 'BRL',
                'payment_method' => $data['payment_method_id'] ?? null,
                'payment_type' => $data['payment_type_id'] ?? null,
                'point_of_interaction' => $data['point_of_interaction'] ?? null,
            ];
        } catch (Exception $e) {
            Log::error('MercadoPago Get Payment Error', [
                'id' => $paymentId,
                'exception' => get_class($e),
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Erro ao buscar status do pagamento',
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
            Log::warning('Could not fetch payment details', ['payment_id' => $dataId]);
            return;
        }

        // Apenas processar pagamentos aprovados
        if (!in_array($paymentStatus['payment_status'], ['processed', 'approved', 'authorized'])) {
            Log::info('Payment not approved', [
                'payment_id' => $dataId,
                'status' => $paymentStatus['payment_status'],
            ]);
            return;
        }

        // Encontrar subscription pelo payment_id
        $subscription = Subscription::where('external_payment_id', $paymentStatus['payment_id'] ?? $dataId)
            ->first();

        if (!$subscription) {
            Log::warning('Webhook: subscription not found', ['payment_id' => $dataId]);
            return;
        }

        // Atualizar subscription
        $subscription->update([
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
            'next_billing_date' => now()->addMonth(),
        ]);

        // Registrar evento de billing
        BillingEvent::create([
            'tenant_id' => $subscription->tenant_id,
            'event_type' => 'payment_received',
            'payload' => [
                'amount_cents' => (int)($paymentStatus['amount'] * 100),
                'external_id' => $paymentStatus['payment_id'],
                'payment_id' => $paymentStatus['payment_id'],
                'payment_method' => $paymentStatus['payment_method'],
                'webhook_received_at' => now()->toIso8601String(),
            ],
        ]);

        Log::info('Subscription activated via webhook', [
            'tenant_id' => $subscription->tenant_id,
            'payment_id' => $dataId,
            'amount' => $paymentStatus['amount'],
        ]);
    }

    /**
     * DEBUG: simular webhook (apenas em APP_DEBUG=true)
     */
    public function debugTestWebhook(string $paymentId): array
    {
        $payment = $this->getPaymentStatus($paymentId);

        if ($payment['status'] !== 'success') {
            return ['error' => 'Payment not found'];
        }

        $this->handleWebhook([
            'type' => 'payment',
            'action' => 'payment.updated',
            'data' => [
                'id' => $paymentId,
            ],
        ]);

        return [
            'message' => 'Webhook simulated',
            'payment' => $payment,
        ];
    }
}
