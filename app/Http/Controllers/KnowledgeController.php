<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Services\DocumentProcessingService;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class KnowledgeController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $documents = Document::query()
            ->where('owner_user_id', $user->id)
            ->when($user->tenant_id, fn ($query) => $query->where('tenant_id', $user->tenant_id))
            ->latest()
            ->paginate(15)
            ->through(function (Document $document) {
                return [
                    'id' => $document->id,
                    'title' => $document->title,
                    'original_name' => $document->original_name,
                    'size_bytes' => $document->size_bytes,
                    'status' => $document->status,
                    'created_at' => $document->created_at,
                ];
            });

        return response()->json($documents);
    }

    public function store(StoreDocumentRequest $request, DocumentProcessingService $processing)
    {
        $user = $request->user();
        $file = $request->file('file');
        $title = $request->input('title') ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $tenantId = TenantContext::getTenant()?->id;

        $document = Document::query()->create([
            'owner_user_id' => $user->id,
            'tenant_id' => $tenantId,
            'title' => $title,
            'original_name' => $file->getClientOriginalName(),
            'path' => '',
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'status' => 'uploaded',
            'tokens' => 0,
            'tags' => $request->input('tags'),
        ]);

        $path = $processing->storeUploadedFile($document, $file, $user->id, $tenantId);
        $document->update(['path' => $path]);

        if (config('knowledge.process_async')) {
            $document->update(['status' => 'processing']);
            ProcessDocumentJob::dispatch($document->id);
        } else {
            $processing->processDocument($document);
        }

        return response()->json([
            'document_id' => $document->id,
            'status' => $document->status,
            'title' => $document->title,
            'original_name' => $document->original_name,
        ], 201);
    }

    public function show(Document $document)
    {
        $this->authorize('view', $document);

        $previewLength = config('knowledge.preview_length', 1500);
        $preview = $document->content_text
            ? mb_substr($document->content_text, 0, $previewLength)
            : null;

        return response()->json([
            'id' => $document->id,
            'title' => $document->title,
            'original_name' => $document->original_name,
            'mime_type' => $document->mime_type,
            'size_bytes' => $document->size_bytes,
            'status' => $document->status,
            'error_message' => $document->status === 'failed' ? $document->error_message : null,
            'created_at' => $document->created_at,
            'preview' => $preview,
            'chunks_count' => $document->chunks()->count(),
            'tokens' => $document->tokens,
        ]);
    }

    public function update(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'tags' => 'sometimes|array',
            'tags.*' => 'string|max:50',
        ]);

        $document->update($validated);

        return response()->json([
            'id' => $document->id,
            'title' => $document->title,
            'tags' => $document->tags,
            'updated_at' => $document->updated_at,
        ]);
    }

    public function destroy(Document $document)
    {
        $this->authorize('delete', $document);

        DB::transaction(function () use ($document) {
            if ($document->path) {
                Storage::disk(config('knowledge.disk'))->deleteDirectory(dirname($document->path));
            }
            $document->delete();
        });

        return response()->json(['message' => 'Deleted.']);
    }
}
