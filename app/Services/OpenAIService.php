<?php

namespace App\Services;

use GuzzleHttp\Client;

class OpenAIService
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => config('services.openai.base_url', env('OPENAI_BASE_URL')),
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

        $response = $this->client->post('/chat/completions', [
            'json' => $payload,
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    public function embed(array $inputs): array
    {
        $payload = [
            'model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-large'),
            'input' => $inputs,
        ];

        $response = $this->client->post('/embeddings', [
            'json' => $payload,
        ]);

        return json_decode((string) $response->getBody(), true);
    }
}
