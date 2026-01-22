<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\RAGService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ProcessDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $documentId)
    {
    }

    public function handle(RAGService $rag): void
    {
        $document = Document::query()->findOrFail($this->documentId);

        $content = $this->extractText($document->path);
        $chunks = $rag->chunkText($content);

        $document->update(['status' => 'chunked', 'tokens' => count($chunks)]);

        foreach ($chunks as $chunk) {
            GenerateEmbeddingsJob::dispatch($document->id, $chunk);
        }
    }

    private function extractText(string $path): string
    {
        // Placeholder extraction. Replace with PDF/DOCX/TXT parsers.
        return (string) Storage::get($path);
    }
}
