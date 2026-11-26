<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seragamkan tanda baca: em dash dan en dash di data yang tampil ke pengguna diganti dash biasa "-".
 * Hanya karakter itu yang diganti; isi lain tidak berubah. Kolom yang tidak ada dilewati.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'assets' => ['name', 'brand', 'model', 'serial_number', 'vendor', 'specs', 'notes'],
        'asset_movements' => ['note', 'from_holder_name', 'to_holder_name'],
        'asset_reports' => ['title', 'description', 'team', 'category', 'status_label', 'reporter_name', 'handler_name', 'resolution'],
        'asset_services' => ['description', 'result', 'vendor', 'handler_name'],
        'categories' => ['name'],
        'consumables' => ['name', 'notes'],
        'consumable_requests' => ['note', 'decision_note', 'requester_name'],
        'locations' => ['name', 'building', 'floor', 'pic_name', 'note'],
        'stock_movements' => ['note'],
        'units' => ['name', 'description', 'group'],
        'users' => ['name', 'division', 'department', 'position'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $col) {
                if (! Schema::hasColumn($table, $col)) {
                    continue;
                }
                foreach (["\u{2014}", "\u{2013}"] as $dash) {
                    DB::table($table)->where($col, 'like', '%'.$dash.'%')
                        ->update([$col => DB::raw('REPLACE('.DB::getQueryGrammar()->wrap($col).', '.DB::getPdo()->quote($dash).", '-')")]);
                }
            }
        }
    }

    public function down(): void
    {
    }
};
