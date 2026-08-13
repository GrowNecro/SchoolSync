<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::query()->firstOrCreate([
            'username' => 'admin',
        ], [
            'name' => 'Administrator',
            'password' => 'password',
        ]);

        Setting::query()->firstOrCreate([], Setting::defaults());
    }
}
