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
use App\Services\TenantContext;
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
        $tenant = TenantContext::getTenant();
        $question = $request->input('question');
        $normalized = $this->rag->normalizeQuestion($question);

        $quickReply = $this->quickReply($normalized);
        if ($quickReply !== null) {
            FaqCache::query()->create([
                'tenant_id' => $tenant->id,
                'question_normalized' => $normalized,
                'answer' => $quickReply,
                'hits' => 1,
                'tokens_saved' => 0,
            ]);

            $this->tokens->recordUsage(0);

            return response()->json([
                'answer' => $quickReply,
                'cached' => false,
                'tokens' => 0,
                'sources' => [],
                'reason' => 'quick_reply',
            ]);
        }

        $cached = FaqCache::query()
            ->where('tenant_id', $tenant->id)
            ->where('question_normalized', $normalized)
            ->first();
        if ($cached) {
            $cached->increment('hits');
            $this->tokens->recordUsage(0);

            return response()->json([
                'answer' => $cached->answer,
                'cached' => true,
                'tokens' => 0,
            ]);
        }

        if (!$this->rag->hasDocumentsForTenant($tenant->id)) {
            $answer = 'Nao encontrei base de conhecimento suficiente para responder a sua pergunta.';
            $usageTokens = $this->tokens->estimateTokens($answer);

            FaqCache::query()->create([
                'tenant_id' => $tenant->id,
                'question_normalized' => $normalized,
                'answer' => $answer,
                'hits' => 1,
                'tokens_saved' => 0,
            ]);

            $this->tokens->recordUsage(0);

            return response()->json([
                'answer' => $answer,
                'cached' => false,
                'tokens' => $usageTokens,
                'sources' => [],
                'reason' => 'no_knowledge_base',
            ]);
        }

        $embeddingResponse = $this->openAI->embed([$question]);
        $embedding = $embeddingResponse['data'][0]['embedding'] ?? [];
        $contextChunks = $this->rag->searchSimilar($embedding, $tenant->id);

        if (empty($contextChunks)) {
            $answer = 'Nao encontrei base de conhecimento suficiente para responder a sua pergunta.';
            $usageTokens = $this->tokens->estimateTokens($answer);

            FaqCache::query()->create([
                'tenant_id' => $tenant->id,
                'question_normalized' => $normalized,
                'answer' => $answer,
                'hits' => 1,
                'tokens_saved' => 0,
            ]);

            $this->tokens->recordUsage(0);

            return response()->json([
                'answer' => $answer,
                'cached' => false,
                'tokens' => $usageTokens,
                'sources' => [],
                'reason' => 'no_knowledge_base',
            ]);
        }

        $context = collect($contextChunks)
            ->pluck('content')
            ->implode("\n\n");

        $settings = AiSetting::query()
            ->where('tenant_id', $tenant->id)
            ->first();

        $systemPrompt = $this->buildSystemPrompt($settings);

        $chatResponse = $this->openAI->chat([
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Contexto:\n{$context}\n\nPergunta: {$question}"],
        ]);

        $answer = $chatResponse['choices'][0]['message']['content'] ?? '';
        $usageTokens = $chatResponse['usage']['total_tokens'] ?? $this->tokens->estimateTokens($answer);

        FaqCache::query()->create([
            'tenant_id' => $tenant->id,
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
        $securityRules = $settings?->security_rules ?? [];
        $rulesText = '';
        if (is_array($securityRules) && !empty($securityRules)) {
            $rulesText = ' Regras de seguranca: '.implode(' | ', $securityRules).'.';
        }

        return "Voce e a IA da IAFuture. Tom: {$tone}. Idioma: {$language}. Detalhe: {$detail}. Responda somente com base no contexto fornecido. Se o contexto nao tiver a resposta, diga: 'Nao encontrei base de conhecimento suficiente para responder a sua pergunta.'.{$rulesText}";
    }

    private function quickReply(string $normalizedQuestion): ?string
    {
        $greetings = [
            'oi',
            'ola',
            'olá',
            'bom dia',
            'boa tarde',
            'boa noite',
        ];

        foreach ($greetings as $greeting) {
            if ($normalizedQuestion === $greeting || str_starts_with($normalizedQuestion, $greeting.' ')) {
                if ($greeting === 'bom dia') {
                    return 'Bom dia! Como posso ajudar?';
                }
                if ($greeting === 'boa tarde') {
                    return 'Boa tarde! Como posso ajudar?';
                }
                if ($greeting === 'boa noite') {
                    return 'Boa noite! Como posso ajudar?';
                }
                return 'Ola! Como posso ajudar?';
            }
        }

        if (preg_match('/\b(como vai|tudo bem|como esta|como está)\b/', $normalizedQuestion)) {
            return 'Tudo bem! Em que posso ajudar?';
        }

        if (preg_match('/\b(quem e voce|quem é voce|quem e vc|quem é vc|o que voce faz|o que vc faz)\b/', $normalizedQuestion)) {
            return 'Sou a assistente da IAFuture e respondo com base na sua base de conhecimento.';
        }

        return null;
    }
}
