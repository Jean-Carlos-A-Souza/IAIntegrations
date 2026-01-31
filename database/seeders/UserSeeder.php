<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->firstOrCreate(
            ['email' => 'teste2@gmail.com'],
            [
                'name' => 'Teste User',
                'password' => Hash::make('12345678'),
                'role' => 'member',
                'tenant_id' => null,
            ]
        );

        User::query()->firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('password123'),
                'role' => 'owner',
                'tenant_id' => null,
            ]
        );
    }
}
