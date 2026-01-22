<?php

namespace App\Services;

use App\Models\DocumentChunk;
use Illuminate\Support\Facades\DB;

class RAGService
{
    public function chunkText(string $text, int $maxTokens = 800): array
    {
        $words = preg_split('/\s+/', trim($text));
        $chunks = [];
        $current = [];
        $count = 0;

        foreach ($words as $word) {
            $current[] = $word;
            $count++;

            if ($count >= $maxTokens) {
                $chunks[] = implode(' ', $current);
                $current = [];
                $count = 0;
            }
        }

        if ($current) {
            $chunks[] = implode(' ', $current);
        }

        return $chunks;
    }

    public function searchSimilar(array $embedding, int $limit = 5): array
    {
        $embeddingLiteral = '['.implode(',', $embedding).']';

        $results = DB::table('document_chunks')
            ->select(['id', 'document_id', 'content'])
            ->orderByRaw('embedding <-> ?::vector', [$embeddingLiteral])
            ->limit($limit)
            ->get();

        return $results->toArray();
    }

    public function normalizeQuestion(string $question): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $question)));
    }
}
