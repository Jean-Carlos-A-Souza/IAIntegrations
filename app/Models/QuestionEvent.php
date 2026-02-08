<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class QuestionEvent extends TenantModel
{
    use HasFactory;

    protected $table = 'question_events';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'question_normalized',
        'question_text',
        'source',
    ];
}
