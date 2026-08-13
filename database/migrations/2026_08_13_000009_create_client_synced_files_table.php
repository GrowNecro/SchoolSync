<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_synced_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_computer_id')->constrained()->cascadeOnDelete();
            $table->string('relative_path', 500);
            $table->string('storage_path', 700);
            $table->unsignedBigInteger('size')->default(0);
            $table->string('sha256', 64);
            $table->timestamp('synced_at');
            $table->timestamps();
            $table->unique(['client_computer_id', 'relative_path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_synced_files');
    }
};
