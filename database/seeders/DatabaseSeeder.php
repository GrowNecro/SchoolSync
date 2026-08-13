<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Models\ClassSchedule;
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

        $setting = Setting::query()->firstOrCreate([], Setting::defaults());
        ClassSchedule::query()->firstOrCreate(['name' => 'Jadwal utama'], [
            'schedule_day' => $setting->schedule_day,
            'start_time' => $setting->start_time,
            'end_time' => $setting->end_time,
            'project_id' => $setting->project_id,
            'browser' => $setting->browser,
            'launcher' => $setting->launcher,
            'shutdown_enabled' => $setting->shutdown_enabled,
            'shutdown_warning' => $setting->shutdown_warning,
            'target_type' => 'all',
            'target_value' => null,
            'exam_enabled' => false,
            'blocked_processes' => [],
            'enabled' => true,
        ]);
    }
}
