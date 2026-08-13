<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_computers', function (Blueprint $table): void {
            $table->string('group_name', 100)->nullable()->index()->after('computer_name');
            $table->string('client_token_hash', 64)->nullable()->unique()->after('group_name');
            $table->boolean('approved')->default(false)->index()->after('client_token_hash');
            $table->timestamp('approved_at')->nullable()->after('approved');
            $table->json('inventory')->nullable()->after('version');
        });

        DB::table('client_computers')->update(['approved' => true, 'approved_at' => now()]);

        Schema::table('remote_commands', function (Blueprint $table): void {
            $table->string('target_type', 20)->default('all')->index()->after('payload');
            $table->json('target_value')->nullable()->after('target_type');
        });

        Schema::create('command_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('remote_command_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_computer_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->string('message', 1000)->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
            $table->unique(['remote_command_id', 'client_computer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('command_executions');
        Schema::table('remote_commands', function (Blueprint $table): void {
            $table->dropColumn(['target_type', 'target_value']);
        });
        Schema::table('client_computers', function (Blueprint $table): void {
            $table->dropUnique(['client_token_hash']);
            $table->dropIndex(['group_name']);
            $table->dropIndex(['approved']);
            $table->dropColumn(['group_name', 'client_token_hash', 'approved', 'approved_at', 'inventory']);
        });
    }
};
