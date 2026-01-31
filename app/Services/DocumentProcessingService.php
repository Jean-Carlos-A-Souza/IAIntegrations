<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class DocumentProcessingService
{
    public function storeUploadedFile(Document $document, UploadedFile $file, int $userId, ?int $tenantId): string
    {
        $this->validateUploadedFile($file);

        $directory = $this->buildDocumentDirectory($document->id, $userId, $tenantId);
        $path = $directory.'/original.txt';

        Storage::disk($this->disk())->put($path, file_get_contents($file->getRealPath()));

        return $path;
    }

    public function processDocument(Document $document): void
    {
        if (!TenantContext::hasTenant() && $document->tenant_id) {
            $tenant = Tenant::query()->find($document->tenant_id);
            if ($tenant) {
                TenantContext::setTenant($tenant);
            }
        }

        $document->update([
            'status' => 'processing',
            'error_message' => null,
        ]);

        try {
            $content = Storage::disk($this->disk())->get($document->path);
            $normalized = $this->normalizeText($content);
            $checksum = hash('sha256', $normalized);

            $chunks = $this->chunkText($normalized);

            DB::transaction(function () use ($document, $normalized, $checksum, $chunks) {
                $document->chunks()->delete();

                $tokensEstimated = 0;

                foreach ($chunks as $index => $chunk) {
                    $chunkTokens = $this->estimateTokens($chunk);
                    $tokensEstimated += $chunkTokens;

                    DocumentChunk::query()->create([
                        'document_id' => $document->id,
                        'chunk_index' => $index,
                        'content' => $chunk,
                        'tokens_estimated' => $chunkTokens,
                        'content_hash' => hash('sha256', $chunk),
                        'tokens' => $chunkTokens,
                    ]);
                }

                $document->update([
                    'content_text' => $normalized,
                    'checksum' => $checksum,
                    'status' => 'processed',
                    'tokens' => $tokensEstimated,
                ]);
            });
        } catch (Throwable $exception) {
            $document->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function normalizeText(string $content): string
    {
        $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true) ?: 'UTF-8';
        $normalized = mb_convert_encoding($content, 'UTF-8', $encoding);
        $normalized = str_replace("\r", '', $normalized);
        $normalized = trim($normalized);

        return $normalized;
    }

    public function chunkText(string $text): array
    {
        $chunkSize = max(100, config('knowledge.chunk_size'));
        $overlap = max(0, config('knowledge.chunk_overlap'));
        $overlap = min($overlap, $chunkSize - 1);

        $length = mb_strlen($text);
        if ($length === 0) {
            return [];
        }

        $chunks = [];
        $position = 0;

        while ($position < $length) {
            $chunk = mb_substr($text, $position, $chunkSize);
            if ($chunk === '') {
                break;
            }

            $chunks[] = $chunk;
            $position += $chunkSize - $overlap;
            if ($position <= 0) {
                break;
            }
        }

        return $chunks;
    }

    public function validateUploadedFile(UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mimeType = $file->getClientMimeType();

        if (!in_array($extension, config('knowledge.allowed_extensions'), true)) {
            throw ValidationException::withMessages([
                'file' => 'Tipo de arquivo não permitido.',
            ]);
        }

        if (!in_array($mimeType, config('knowledge.allowed_mime_types'), true)) {
            throw ValidationException::withMessages([
                'file' => 'Tipo MIME não permitido.',
            ]);
        }
    }

    public function buildDocumentDirectory(int $documentId, int $userId, ?int $tenantId): string
    {
        $scope = $tenantId ? 'tenant_'.$tenantId : 'user_'.$userId;

        return 'knowledge/'.$scope.'/'.$documentId;
    }

    private function estimateTokens(string $text): int
    {
        $length = mb_strlen(trim($text));

        return $length > 0 ? (int) max(1, ceil($length / 4)) : 0;
    }

    private function disk(): string
    {
        return config('knowledge.disk', 'local');
    }
}
