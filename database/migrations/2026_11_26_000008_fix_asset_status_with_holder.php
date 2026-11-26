<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Perbaikan data: aset yang punya pemegang tetapi berstatus "Tersedia" (hasil impor dari HaloDek
 * versi lama) menjadi "Digunakan", agar status, filter, dan statistik beranda benar.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('assets')->whereNotNull('holder_id')->where('status', 'tersedia')->update(['status' => 'digunakan', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Tidak dibalik: status lama memang keliru.
    }
};
