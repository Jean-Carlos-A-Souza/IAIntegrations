<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class FaqCache extends TenantModel
{
    use HasFactory;

    protected $table = 'faq_cache';

    protected $fillable = [
        'tenant_id',
        'question_normalized',
        'answer',
        'hits',
        'tokens_saved',
    ];
}
