<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Catatan perbaikan yang disalin otomatis dari judul laporan HaloDek lama: samakan dash panjang. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('asset_services') || ! Schema::hasColumn('asset_services', 'asset_report_id')) {
            return;
        }
        foreach (["\u{2014}", "\u{2013}"] as $dash) {
            DB::table('asset_services')->whereNotNull('asset_report_id')->where('description', 'like', '%'.$dash.'%')
                ->update(['description' => DB::raw('REPLACE(description, '.DB::getPdo()->quote($dash).", '-')")]);
        }
    }

    public function down(): void
    {
    }
};
