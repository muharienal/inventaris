<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat perbaikan otomatis dari HaloDek: laporan kendala yang ditangani menjadi catatan servis
 * "Perbaikan" di aset (tanpa input ulang). Detail penanganan ikut disimpan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_reports', function (Blueprint $table) {
            $table->string('team', 100)->nullable()->after('title');
            $table->string('category', 150)->nullable()->after('team');
            $table->text('description')->nullable()->after('category');
            $table->string('handler_name', 150)->nullable()->after('reporter_name');
            $table->text('resolution')->nullable()->after('handler_name');
            $table->timestamp('resolved_at')->nullable()->after('reported_at');
        });

        Schema::table('asset_services', function (Blueprint $table) {
            $table->foreignId('asset_report_id')->nullable()->unique()->after('report_number')->constrained('asset_reports')->nullOnDelete();
            $table->string('handler_name', 150)->nullable()->after('vendor');
        });
    }

    public function down(): void
    {
        Schema::table('asset_services', function (Blueprint $table) {
            $table->dropConstrainedForeignId('asset_report_id');
            $table->dropColumn('handler_name');
        });
        Schema::table('asset_reports', function (Blueprint $table) {
            $table->dropColumn(['team', 'category', 'description', 'handler_name', 'resolution', 'resolved_at']);
        });
    }
};
