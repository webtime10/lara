<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Учётка для входа в админку (поле «Логин»: имя или email).
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::where('slug', 'admin')->first();

        User::query()->updateOrCreate(
            ['email' => 'akvamarin01@admin.local'],
            [
                'name' => 'akvamarin01',
                'password' => Hash::make('sandra2201'),
                'role_id' => $adminRole?->id,
            ]
        );
    }
}
