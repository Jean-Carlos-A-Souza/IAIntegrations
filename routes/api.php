<?php

use App\Http\Controllers\AiSettingsController;
use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\PasswordRecoveryController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\UsageController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HealthController;

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('login', [AuthController::class, 'login']);
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('me', [AuthController::class, 'me'])->middleware('auth:sanctum');
    Route::post('password/forgot', [PasswordRecoveryController::class, 'forgot'])->middleware('throttle:3,10');
    Route::post('password/reset', [PasswordRecoveryController::class, 'reset']);
});

Route::get('/health/openai', [HealthController::class, 'openai']);

Route::middleware(['auth:sanctum', 'tenant.resolve', 'tenant.ensure'])->group(function () {
    Route::get('tenant', [TenantController::class, 'show']);
    Route::get('tenant/users', [TenantController::class, 'indexUsers']);
    Route::post('tenant/users', [TenantController::class, 'storeUser']);

    Route::post('knowledge/documents', [KnowledgeController::class, 'store']);
    Route::get('knowledge/documents', [KnowledgeController::class, 'index']);
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
