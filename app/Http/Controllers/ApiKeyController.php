<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ApiKeyController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $tenant = TenantContext::getTenant();
        $plain = 'iaf_'.Str::random(40);

        $apiKey = ApiKey::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $request->user()->id,
            'name' => $request->input('name'),
            'hashed_key' => Hash::make($plain),
        ]);

        return response()->json([
            'api_key' => $plain,
            'record' => $apiKey,
        ], 201);
    }

    public function rotate(ApiKey $apiKey)
    {
        $plain = 'iaf_'.Str::random(40);
        $apiKey->update([
            'hashed_key' => Hash::make($plain),
            'last_used_at' => null,
        ]);

        return response()->json([
            'api_key' => $plain,
            'record' => $apiKey,
        ]);
    }
}
