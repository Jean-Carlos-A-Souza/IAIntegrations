<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class OpenAIService
{
    private Client $client;

    public function __construct()
    {
        $baseUrl = config('services.openai.base_url', env('OPENAI_BASE_URL', 'https://api.openai.com/v1'));
        $baseUrl = rtrim($baseUrl, '/');
        if (!str_ends_with($baseUrl, '/v1')) {
            $baseUrl .= '/v1';
        }
        if (!str_ends_with($baseUrl, '/')) {
            $baseUrl .= '/';
        }

        if (config('app.debug')) {
            Log::info('OpenAI client configured', ['base_uri' => $baseUrl]);
        }

        $this->client = new Client([
            'base_uri' => $baseUrl,
            'headers' => [
                'Authorization' => 'Bearer '.env('OPENAI_API_KEY'),
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
        ]);
    }

    public function chat(array $messages, array $options = []): array
    {
        $payload = array_merge([
            'model' => env('OPENAI_CHAT_MODEL', 'gpt-4.1'),
            'messages' => $messages,
            'temperature' => 0.2,
        ], $options);

        try {
            $response = $this->client->post('chat/completions', [
                'json' => $payload,
            ]);

            return json_decode((string) $response->getBody(), true);
        } catch (RequestException $e) {
            $response = $e->getResponse();
            Log::error('OpenAI chat request failed', [
                'status' => $response?->getStatusCode(),
                'body' => $response ? (string) $response->getBody() : null,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function embed(array $inputs): array
    {
        $payload = [
            'model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-large'),
            'input' => $inputs,
        ];

        try {
            $response = $this->client->post('embeddings', [
                'json' => $payload,
            ]);

            return json_decode((string) $response->getBody(), true);
        } catch (RequestException $e) {
            $response = $e->getResponse();
            Log::error('OpenAI embeddings request failed', [
                'status' => $response?->getStatusCode(),
                'body' => $response ? (string) $response->getBody() : null,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
