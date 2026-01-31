<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentChunk extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'chunk_index',
        'content',
        'tokens_estimated',
        'content_hash',
        'embedding',
        'tokens',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
