<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Services\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class VerifyApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        // Extrair API Key do header Authorization
        $authHeader = $request->header('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer iaf_')) {
            // Se não tiver API Key, passar para próximo middleware (Sanctum)
            return $next($request);
        }

        $plainKey = substr($authHeader, 7); // Remove 'Bearer '

        // Buscar API Key no banco
        $apiKey = ApiKey::with(['user', 'tenant'])
            ->whereNotNull('revoked_at')
            ->where('revoked_at', null)
            ->where('last_used_at', '!=', null)
            ->get()
            ->first(function ($key) use ($plainKey) {
                return Hash::check($plainKey, $key->hashed_key);
            });

        if (!$apiKey) {
            return response()->json([
                'message' => 'Invalid or revoked API key',
                'code' => 'INVALID_API_KEY',
            ], 401);
        }

        // Validar que tenant_id no header corresponde com a chave
        $tenantIdHeader = $request->header('X-Tenant-ID');
        if ($tenantIdHeader && (int)$tenantIdHeader !== $apiKey->tenant_id) {
            return response()->json([
                'message' => 'API key does not belong to this tenant',
                'code' => 'TENANT_MISMATCH',
            ], 403);
        }

        // Atualizar last_used_at
        $apiKey->update([
            'last_used_at' => now(),
        ]);

        // Definir user e tenant no contexto do request
        $request->setUserResolver(function () use ($apiKey) {
            return $apiKey->user;
        });

        // Definir tenant no contexto
        TenantContext::setTenant($apiKey->tenant);
        $request->merge(['api_key' => $apiKey]);

        return $next($request);
    }
}
