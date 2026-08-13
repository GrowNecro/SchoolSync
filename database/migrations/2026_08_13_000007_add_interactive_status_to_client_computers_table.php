<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_computers', function (Blueprint $table): void {
            $table->timestamp('last_interactive_at')->nullable()->index()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('client_computers', function (Blueprint $table): void {
            $table->dropColumn('last_interactive_at');
        });
    }
};
