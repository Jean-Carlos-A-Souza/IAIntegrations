<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTenantUserRequest;
use App\Models\User;
use App\Services\TenantContext;
use Illuminate\Support\Facades\Hash;

class TenantController extends Controller
{
    public function show()
    {
        return response()->json(TenantContext::getTenant());
    }

    public function indexUsers()
    {
        $tenant = TenantContext::getTenant();

        $users = User::query()->where('tenant_id', $tenant->id)->get();

        return response()->json($users);
    }

    public function storeUser(StoreTenantUserRequest $request)
    {
        $tenant = TenantContext::getTenant();

        $user = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'password' => Hash::make($request->input('password')),
            'role' => $request->input('role'),
        ]);

        return response()->json($user, 201);
    }
}
