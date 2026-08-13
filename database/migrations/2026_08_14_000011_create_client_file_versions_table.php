<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_file_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_synced_file_id')->constrained()->cascadeOnDelete();
            $table->string('storage_path', 700);
            $table->unsignedBigInteger('size')->default(0);
            $table->string('sha256', 64);
            $table->timestamps();
            $table->unique(['client_synced_file_id', 'sha256']);
        });

        DB::table('client_synced_files')->orderBy('id')->each(function (object $file): void {
            DB::table('client_file_versions')->insert([
                'client_synced_file_id' => $file->id,
                'storage_path' => $file->storage_path,
                'size' => $file->size,
                'sha256' => $file->sha256,
                'created_at' => $file->synced_at ?? now(),
                'updated_at' => $file->synced_at ?? now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_file_versions');
    }
};
