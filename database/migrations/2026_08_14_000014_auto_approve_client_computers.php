<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('client_computers')
            ->where('approved', false)
            ->update([
                'approved' => true,
                'approved_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Persetujuan yang sudah diberikan tidak dicabut saat rollback.
    }
};
