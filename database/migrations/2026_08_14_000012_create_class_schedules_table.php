<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_schedules', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('schedule_day', 16)->index();
            $table->time('start_time');
            $table->time('end_time');
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->json('browser');
            $table->json('launcher');
            $table->boolean('shutdown_enabled')->default(false);
            $table->unsignedSmallInteger('shutdown_warning')->default(10);
            $table->string('target_type', 20)->default('all')->index();
            $table->json('target_value')->nullable();
            $table->boolean('exam_enabled')->default(false);
            $table->json('blocked_processes')->nullable();
            $table->boolean('enabled')->default(true)->index();
            $table->timestamps();
        });

        $setting = DB::table('settings')->orderBy('id')->first();
        if ($setting) {
            DB::table('class_schedules')->insert([
                'name' => 'Jadwal utama',
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
                'blocked_processes' => json_encode([]),
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('class_schedules');
    }
};
