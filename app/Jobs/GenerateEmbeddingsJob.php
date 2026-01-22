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

    public function __construct(public readonly int $documentId, public readonly string $chunk)
    {
    }

    public function handle(OpenAIService $openAI): void
    {
        $embeddingResponse = $openAI->embed([$this->chunk]);
        $embedding = $embeddingResponse['data'][0]['embedding'] ?? [];

        $embeddingLiteral = '['.implode(',', $embedding).']';

        DB::table('document_chunks')->insert([
            'document_id' => $this->documentId,
            'content' => $this->chunk,
            'embedding' => DB::raw(\"'{$embeddingLiteral}'::vector\"),
            'tokens' => count($embedding),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
