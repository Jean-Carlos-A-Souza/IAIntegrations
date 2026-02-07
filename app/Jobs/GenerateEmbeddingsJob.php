<?php

namespace App\Jobs;

use App\Services\OpenAIService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class GenerateEmbeddingsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $chunkId, public readonly string $chunk)
    {
    }

    public function handle(OpenAIService $openAI): void
    {
        $embeddingResponse = $openAI->embed([$this->chunk]);
        $embedding = $embeddingResponse['data'][0]['embedding'] ?? [];

        if (empty($embedding)) {
            return;
        }

        $embeddingLiteral = '['.implode(',', $embedding).']';

        DB::table('document_chunks')
            ->where('id', $this->chunkId)
            ->update([
                'embedding' => DB::raw(\"'{$embeddingLiteral}'::vector\"),
                'tokens' => count($embedding),
                'updated_at' => now(),
            ]);
    }
}
