<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use Illuminate\Support\Facades\Storage;

class KnowledgeController extends Controller
{
    public function index()
    {
        return response()->json(Document::query()->latest()->get());
    }

    public function store(StoreDocumentRequest $request)
    {
        $file = $request->file('file');
        $path = $file->store('documents');

        $document = Document::query()->create([
            'title' => $request->input('title') ?? $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'status' => 'queued',
            'tokens' => 0,
        ]);

        ProcessDocumentJob::dispatch($document->id);

        return response()->json($document, 201);
    }

    public function destroy(Document $document)
    {
        Storage::delete($document->path);
        $document->delete();

        return response()->json(['message' => 'Deleted.']);
    }
}
