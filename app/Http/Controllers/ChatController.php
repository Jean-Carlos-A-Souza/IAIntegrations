<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChatAskRequest;
use App\Http\Requests\ChatMessageRequest;
use App\Models\AiSetting;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\FaqCache;
use App\Services\OpenAIService;
use App\Services\RAGService;
use App\Services\TokenCounter;

class ChatController extends Controller
{
    public function __construct(
        private readonly OpenAIService $openAI,
        private readonly RAGService $rag,
        private readonly TokenCounter $tokens,
    ) {
    }

    public function ask(ChatAskRequest $request)
    {
        $question = $request->input('question');
        $normalized = $this->rag->normalizeQuestion($question);

        $cached = FaqCache::query()->where('question_normalized', $normalized)->first();
        if ($cached) {
            $cached->increment('hits');

            return response()->json([
                'answer' => $cached->answer,
                'cached' => true,
            ]);
        }

        $embeddingResponse = $this->openAI->embed([$question]);
        $embedding = $embeddingResponse['data'][0]['embedding'] ?? [];
        $contextChunks = $this->rag->searchSimilar($embedding);

        $context = collect($contextChunks)
            ->pluck('content')
            ->implode("\n\n");

        $settings = AiSetting::query()->first();

        $systemPrompt = $this->buildSystemPrompt($settings);

        $chatResponse = $this->openAI->chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Contexto:\n{$context}\n\nPergunta: {$question}"],
        ]);

        $answer = $chatResponse['choices'][0]['message']['content'] ?? '';
        $usageTokens = $chatResponse['usage']['total_tokens'] ?? $this->tokens->estimateTokens($answer);

        FaqCache::query()->create([
            'question_normalized' => $normalized,
            'answer' => $answer,
            'hits' => 1,
            'tokens_saved' => $usageTokens,
        ]);

        $this->tokens->recordUsage($usageTokens);

        return response()->json([
            'answer' => $answer,
            'cached' => false,
            'tokens' => $usageTokens,
            'sources' => $contextChunks,
        ]);
    }

    public function message(Chat $chat, ChatMessageRequest $request)
    {
        $message = $request->input('message');
        $chat->messages()->create([
            'role' => 'user',
            'content' => $message,
            'tokens' => $this->tokens->estimateTokens($message),
            'sources' => [],
        ]);

        $history = $chat->messages()->latest()->take(10)->get()->reverse()->values();
        $payload = $history->map(fn (ChatMessage $msg) => [
            'role' => $msg->role,
            'content' => $msg->content,
        ])->all();

        $response = $this->openAI->chat($payload);
        $answer = $response['choices'][0]['message']['content'] ?? '';
        $usageTokens = $response['usage']['total_tokens'] ?? $this->tokens->estimateTokens($answer);

        $assistantMessage = $chat->messages()->create([
            'role' => 'assistant',
            'content' => $answer,
            'tokens' => $usageTokens,
            'sources' => [],
        ]);

        $this->tokens->recordUsage($usageTokens);

        return response()->json([
            'message' => $assistantMessage,
            'tokens' => $usageTokens,
        ]);
    }

    private function buildSystemPrompt(?AiSetting $settings): string
    {
        $tone = $settings?->tone ?? 'direto';
        $language = $settings?->language ?? 'pt-BR';
        $detail = $settings?->detail_level ?? 'medio';

        return "Você é a IA da IAFuture. Tom: {$tone}. Idioma: {$language}. Detalhe: {$detail}. Siga regras de segurança do tenant.";
    }
}
