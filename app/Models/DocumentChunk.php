<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentChunk extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'content',
        'embedding',
        'tokens',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
