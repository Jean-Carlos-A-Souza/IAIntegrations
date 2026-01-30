<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Register a new user and optionally create a tenant.
     *
     * @group Authentication
     * @bodyParam name string required Example: Jane Doe
     * @bodyParam email string required Example: jane@example.com
     * @bodyParam password string required Example: password123
     * @bodyParam password_confirmation string required Example: password123
     * @bodyParam tenant_name string optional Example: Acme Inc
     * @response 201 {
     *   "token": "string",
     *   "user": {
     *     "id": 1,
     *     "name": "Jane Doe",
     *     "email": "jane@example.com"
     *   }
     * }
     */
    public function register(RegisterRequest $request)
    {
        $user = DB::transaction(function () use ($request) {
            $tenantName = $request->input('tenant_name');
            $tenantId = null;
            $role = 'member';

            if ($tenantName) {
                $identifiers = $this->uniqueTenantIdentifiers($tenantName);

                $tenant = Tenant::query()->create([
                    'name' => $tenantName,
                    'slug' => $identifiers['slug'],
                    'schema' => $identifiers['schema'],
                    'status' => 'active',
                ]);

                $tenantId = $tenant->id;
                $role = 'owner';
            }

            return User::query()->create([
                'tenant_id' => $tenantId,
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                'password' => Hash::make($request->input('password')),
                'role' => $role,
            ]);
        });

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ], 201);
    }

    public function login(LoginRequest $request)
    {
        $startedAt = microtime(true);
        $email = strtolower(trim((string) $request->input('email')));
        $tenant = TenantContext::getTenant();

        Log::info('Login attempt', [
            'email' => $email,
            'tenant_id' => $tenant?->id,
            'tenant_resolved' => $tenant !== null,
        ]);

        $user = User::query()->where('email', $email)->first();
        $hashCheck = $user ? Hash::check((string) $request->input('password'), $user->password) : false;

        Log::info('Login result', [
            'email' => $email,
            'found_user' => $user !== null,
            'hash_check' => $hashCheck,
            'tenant_id' => $tenant?->id,
            'elapsed_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        if (!$user || !$hashCheck) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'tenant_id' => $user->tenant_id,
                'role' => $user->role,
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }

    private function uniqueTenantIdentifiers(string $name): array
    {
        $base = Str::slug($name);
        $base = $base !== '' ? $base : 'tenant';

        $slug = $base;
        $schema = $base;
        $counter = 1;

        while (Tenant::query()
            ->where('slug', $slug)
            ->orWhere('schema', $schema)
            ->exists()) {
            $slug = "{$base}-{$counter}";
            $schema = "{$base}_{$counter}";
            $counter++;
        }

        return [
            'slug' => $slug,
            'schema' => $schema,
        ];
    }
}
