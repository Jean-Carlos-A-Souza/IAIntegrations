<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    public function test_register_and_login_successfully(): void
    {
        $registerResponse = $this->postJson('/api/auth/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $registerResponse->assertStatus(201);

        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'password123',
        ]);

        $loginResponse->assertStatus(200)
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email', 'tenant_id', 'role'],
            ])
            ->assertJsonPath('user.email', 'jane@example.com');
    }

    public function test_login_fails_with_invalid_password(): void
    {
        User::query()->create([
            'tenant_id' => null,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => Hash::make('password123'),
            'role' => 'member',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'jane@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Invalid credentials.',
            ]);
    }

    public function test_login_rejects_invalid_email(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'not-an-email',
            'password' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }
}
