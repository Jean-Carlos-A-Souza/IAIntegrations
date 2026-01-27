<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    public function test_register_success_with_tenant(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'tenant_name' => 'Acme Inc',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email'],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'jane@example.com',
            'role' => 'owner',
        ]);

        $this->assertDatabaseHas('tenants', [
            'name' => 'Acme Inc',
        ]);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        User::query()->create([
            'tenant_id' => null,
            'name' => 'Existing User',
            'email' => 'jane@example.com',
            'password' => Hash::make('password123'),
            'role' => 'member',
        ]);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }
}
