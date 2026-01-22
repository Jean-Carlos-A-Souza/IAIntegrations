<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAiSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tone' => ['required', 'in:direto,amigavel,tecnico'],
            'language' => ['required', 'string', 'max:10'],
            'detail_level' => ['required', 'in:curto,medio,detalhado'],
            'security_rules' => ['nullable', 'array'],
        ];
    }
}
