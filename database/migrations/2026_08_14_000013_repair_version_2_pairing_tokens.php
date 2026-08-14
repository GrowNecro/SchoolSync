<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('client_computers')
            ->where('version', '2.0.0')
            ->update(['client_token_hash' => null]);
    }

    public function down(): void
    {
        // Hash token lama tidak dapat dipulihkan dan klien akan melakukan pairing ulang otomatis.
    }
};
