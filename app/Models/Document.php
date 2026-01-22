<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'title',
        'path',
        'mime_type',
        'status',
        'tokens',
    ];

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }
}
