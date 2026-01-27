<?php

namespace Tests\Feature;

use App\Mail\PasswordRecoveryCodeMail;
use App\Models\PasswordResetCode;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PasswordRecoveryTest extends TestCase
{
    public function test_forgot_password_sends_code_when_email_exists(): void
    {
        Mail::fake();

        $user = User::query()->create([
            'tenant_id' => null,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => Hash::make('password123'),
            'role' => 'member',
        ]);

        $response = $this->postJson('/api/auth/password/forgot', [
            'email' => 'jane@example.com',
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'message' => 'If the email exists, a recovery code was sent.',
            ]);

        $this->assertDatabaseHas('password_reset_codes', [
            'user_id' => $user->id,
        ]);

        Mail::assertSent(PasswordRecoveryCodeMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    }

    public function test_forgot_password_returns_generic_response_when_email_missing(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/auth/password/forgot', [
            'email' => 'missing@example.com',
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'message' => 'If the email exists, a recovery code was sent.',
            ]);

        Mail::assertNothingSent();
    }

    public function test_reset_rejects_invalid_code(): void
    {
        $user = User::query()->create([
            'tenant_id' => null,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => Hash::make('password123'),
            'role' => 'member',
        ]);

        PasswordResetCode::query()->create([
            'user_id' => $user->id,
            'code_hash' => Hash::make('111111'),
            'expires_at' => Carbon::now()->addMinutes(10),
        ]);

        $response = $this->postJson('/api/auth/password/reset', [
            'email' => 'jane@example.com',
            'code' => '222222',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Invalid recovery code.',
            ]);
    }

    public function test_reset_rejects_expired_code(): void
    {
        $user = User::query()->create([
            'tenant_id' => null,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => Hash::make('password123'),
            'role' => 'member',
        ]);

        $code = PasswordResetCode::query()->create([
            'user_id' => $user->id,
            'code_hash' => Hash::make('123456'),
            'expires_at' => Carbon::now()->subMinute(),
        ]);

        $response = $this->postJson('/api/auth/password/reset', [
            'email' => 'jane@example.com',
            'code' => '123456',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'message' => 'Invalid recovery code.',
            ]);

        $this->assertDatabaseMissing('password_reset_codes', [
            'id' => $code->id,
        ]);
    }

    public function test_reset_succeeds_with_valid_code(): void
    {
        $user = User::query()->create([
            'tenant_id' => null,
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => Hash::make('password123'),
            'role' => 'member',
        ]);

        $code = PasswordResetCode::query()->create([
            'user_id' => $user->id,
            'code_hash' => Hash::make('123456'),
            'expires_at' => Carbon::now()->addMinutes(10),
        ]);

        $user->createToken('api');

        $response = $this->postJson('/api/auth/password/reset', [
            'email' => 'jane@example.com',
            'code' => '123456',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'success',
                'message' => 'Password updated successfully',
            ]);

        $user->refresh();

        $this->assertTrue(Hash::check('newpassword123', $user->password));
        $this->assertSame(0, $user->tokens()->count());

        $this->assertDatabaseMissing('password_reset_codes', [
            'id' => $code->id,
        ]);
    }
}
