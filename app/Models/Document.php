<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'owner_user_id',
        'tenant_id',
        'title',
        'original_name',
        'path',
        'mime_type',
        'size_bytes',
        'checksum',
        'content_text',
        'tags',
        'status',
        'error_message',
        'tokens',
    ];

    protected $casts = [
        'tags' => 'array',
    ];

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }
}
