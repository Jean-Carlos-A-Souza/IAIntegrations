<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\DocumentProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $documentId)
    {
    }

    public function handle(DocumentProcessingService $processing): void
    {
        $document = Document::query()->findOrFail($this->documentId);
        $processing->processDocument($document);
    }
}
