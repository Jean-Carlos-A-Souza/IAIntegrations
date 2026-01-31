<?php

namespace App\Http\Controllers;

use App\Services\MercadoPagoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(private MercadoPagoService $mercadopago)
    {}

    /**
     * POST /webhooks/mercadopago
     * 
     * Recebe webhooks do Mercado Pago
     * 
     * Headers importantes:
     * - X-Signature: assinatura para validar origem (implementar em produção!)
     * 
     * Body exemplo:
     * {
     *   "type": "payment",
     *   "action": "payment.updated",
     *   "data": {
     *     "id": "1234567890"
     *   }
     * }
     * 
     * Documentação MP: https://www.mercadopago.com.br/developers/pt/docs/checkout-pro/notifications/webhooks
     */
    public function mercadopago(Request $request)
    {
        $data = $request->all();

        Log::info('Webhook received', [
            'ip' => $request->ip(),
            'type' => $data['type'] ?? null,
            'action' => $data['action'] ?? null,
        ]);

        // Em produção, validar X-Signature aqui
        // $this->validateMercadoPagoSignature($request);

        try {
            $this->mercadopago->handleWebhook($data);

            return response()->json([
                'status' => 'received',
                'timestamp' => now()->toIso8601String(),
            ], 200);
        } catch (\Exception $e) {
            Log::error('Webhook processing error', [
                'message' => $e->getMessage(),
                'data' => $data,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Validar assinatura do Mercado Pago (para produção)
     * 
     * Documentação: https://www.mercadopago.com.br/developers/pt/docs/checkout-pro/notifications/webhooks
     */
    private function validateMercadoPagoSignature(Request $request): void
    {
        $signature = $request->header('X-Signature');
        $requestId = $request->header('X-Request-Id');

        if (!$signature || !$requestId) {
            Log::warning('Webhook signature validation failed - missing headers');
            throw new \Exception('Invalid signature headers');
        }

        // TODO: Implementar validação real com secret do Mercado Pago
        // Referência: https://www.mercadopago.com.br/developers/pt/docs/checkout-pro/notifications/webhooks#verificar-origem-da-notificacao
    }
}
