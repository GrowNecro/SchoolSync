<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('filename')->unique();
            $table->string('path');
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('schedule_day', 16);
            $table->time('start_time');
            $table->time('end_time');
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->json('browser');
            $table->json('launcher');
            $table->boolean('shutdown_enabled')->default(false);
            $table->unsignedSmallInteger('shutdown_warning')->default(10);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('projects');
    }
};
