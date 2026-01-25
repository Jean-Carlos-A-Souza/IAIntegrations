<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;

class HealthController extends Controller
{
    public function openai()
    {
        $key = config('services.openai.key');
        $base = rtrim(config('services.openai.base_url'), '/');
        $model = config('services.openai.model');

        if (!$key) {
            return response()->json([
                'status' => 'error',
                'message' => 'OPENAI_API_KEY não configurada',
            ], 500);
        }

        $response = Http::timeout(30)
            ->withHeaders([
                'Authorization' => "Bearer {$key}",
                'Content-Type' => 'application/json',
            ])
            ->post("{$base}/responses", [
                'model' => $model,
                'input' => 'write a haiku about ai',
                'store' => true,
            ]);

        if (!$response->successful()) {
            return response()->json([
                'status' => 'error',
                'openai_status' => $response->status(),
                'openai_body' => $response->json(),
            ], 500);
        }

        return response()->json([
            'status' => 'ok',
            'openai_response' => $response->json()['output_text'][0] ?? 'OK',
        ]);
    }
}
