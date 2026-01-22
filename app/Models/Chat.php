<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chat extends TenantModel
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }
}
