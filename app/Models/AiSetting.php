<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class AiSetting extends TenantModel
{
    use HasFactory;

    protected $table = 'settings_ai';

    protected $fillable = [
        'tone',
        'language',
        'detail_level',
        'security_rules',
    ];

    protected $casts = [
        'security_rules' => 'array',
    ];
}
