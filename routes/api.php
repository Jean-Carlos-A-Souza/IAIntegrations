<?php

use App\Http\Controllers\AiSettingsController;
use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\PasswordRecoveryController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\UsageController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;
use App\\Http\\Controllers\\HealthController;
use App\\Http\\Controllers\\LogController;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('login', [AuthController::class, 'login'])->middleware(['tenant.resolve', 'throttle:5,1']);
    Route::post('logout', [AuthController::class, 'logout'])->middleware(['tenant.resolve', 'auth:sanctum']);
    Route::get('me', [AuthController::class, 'me'])->middleware(['tenant.resolve', 'auth:sanctum']);
    Route::post('password/forgot', [PasswordRecoveryController::class, 'forgot'])->middleware('throttle:3,10');
    Route::post('password/reset', [PasswordRecoveryController::class, 'reset']);
});

Route::get('/health/openai', [HealthController::class, 'openai']);

// TIER 1: Webhooks (sem autenticação - Mercado Pago chama isso)
Route::post('/webhooks/mercadopago', [WebhookController::class, 'mercadopago'])->withoutMiddleware(['auth', 'throttle']);

// Rotas de configuração (sem autenticação necessária)
Route::get('config/payment', [PaymentController::class, 'getConfig']);

// Rotas de planos (sem autenticação necessária ou apenas leitura)
Route::get('plans', [SubscriptionController::class, 'listPlans']);

Route::middleware(['tenant.resolve', 'auth:sanctum', 'tenant.ensure'])->group(function () {
    // Rotas de subscription (antes de verificar subscription ativa)
    Route::get('subscription', [SubscriptionController::class, 'getCurrent']);
    Route::post('subscription/subscribe', [SubscriptionController::class, 'subscribe']);
    Route::post('subscription/cancel', [SubscriptionController::class, 'cancel']);
    Route::post('subscription/renew', [SubscriptionController::class, 'renew']);

    // NOVO: Rotas de pagamento (Mercado Pago)
    Route::post('payments/create', [PaymentController::class, 'createPayment']);
    Route::get('payments/{payment_id}/status', [PaymentController::class, 'getPaymentStatus']);
    Route::get('payments/{payment_id}/debug', [PaymentController::class, 'debugWebhook']); // Remove em produção!
    Route::get('logs/{date?}', [LogController::class, 'show']);
});

// Rotas protegidas com validações de subscription e limit
Route::middleware(['tenant.resolve', 'auth:sanctum', 'tenant.ensure', 'verify.active.subscription', 'validate.token.limit', 'enforce.request.rate.limit'])->group(function () {
    Route::get('tenant', [TenantController::class, 'show']);
    Route::get('tenant/users', [TenantController::class, 'indexUsers']);
    Route::post('tenant/users', [TenantController::class, 'storeUser']);

    Route::post('knowledge/documents', [KnowledgeController::class, 'store']);
    Route::get('knowledge/documents', [KnowledgeController::class, 'index']);
    Route::get('knowledge/documents/{document}', [KnowledgeController::class, 'show']);
    Route::patch('knowledge/documents/{document}', [KnowledgeController::class, 'update']);
    Route::put('knowledge/documents/{document}', [KnowledgeController::class, 'update']);
    Route::delete('knowledge/documents/{document}', [KnowledgeController::class, 'destroy']);

    Route::post('chat/ask', [ChatController::class, 'ask']);
    Route::post('chat/{chat}/message', [ChatController::class, 'message']);

    Route::get('ai/settings', [AiSettingsController::class, 'show']);
    Route::put('ai/settings', [AiSettingsController::class, 'update']);

    Route::get('usage/monthly', [UsageController::class, 'monthly']);
    Route::get('analytics/top-questions', [UsageController::class, 'topQuestions']);

    Route::post('api-keys', [ApiKeyController::class, 'store']);
    Route::post('api-keys/{apiKey}/rotate', [ApiKeyController::class, 'rotate']);
});
