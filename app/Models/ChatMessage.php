<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'chat_id',
        'role',
        'content',
        'tokens',
        'sources',
    ];

    protected $casts = [
        'sources' => 'array',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }
}
