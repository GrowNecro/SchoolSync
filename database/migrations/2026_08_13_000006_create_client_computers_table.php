<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_computers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('installation_id')->unique();
            $table->string('computer_name', 100);
            $table->string('version', 20)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('last_seen_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_computers');
    }
};
