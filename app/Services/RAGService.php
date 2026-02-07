<?php

namespace App\Services;

use App\Models\DocumentChunk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class RAGService
{
    public function hasDocumentsForTenant(int $tenantId): bool
    {
        return DB::table('documents')
            ->where('tenant_id', $tenantId)
            ->exists();
    }

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

    public function searchSimilar(array $embedding, ?int $tenantId = null, int $limit = 5): array
    {
        if (!Schema::hasColumn('document_chunks', 'embedding')) {
            Log::warning('RAG search skipped: missing embedding column');
            return [];
        }

        $embeddingLiteral = '['.implode(',', $embedding).']';

        $query = DB::table('document_chunks')
            ->select([
                'document_chunks.id',
                'document_chunks.document_id',
                'document_chunks.content',
            ])
            ->whereNotNull('document_chunks.embedding')
            ->orderByRaw('embedding <-> ?::vector', [$embeddingLiteral]);

        if ($tenantId !== null) {
            $query = $query
                ->join('documents', 'documents.id', '=', 'document_chunks.document_id')
                ->where('documents.tenant_id', $tenantId);
        }

        $results = $query->limit($limit)->get();

        return $results->toArray();
    }

    public function normalizeQuestion(string $question): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $question)));
    }
}
