<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Struktur organisasi (Divisi -> Bidang) mengikuti HaloDek, agar aset bisa dipegang
 * oleh satu karyawan, satu bidang, atau satu divisi.
 *
 *  org_units.type      : divisi | bidang
 *  org_units.parent_id : divisi induk sebuah bidang
 *  org_units.halodek_id: id di HaloDek (kosong bila dibentuk dari data pegawai)
 *
 * assets.holder_org_id terisi bila pemegangnya bidang/divisi (holder_id = orang). Paling banyak satu yang terisi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_units', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10);
            $table->foreignId('parent_id')->nullable()->constrained('org_units')->nullOnDelete();
            $table->unsignedBigInteger('halodek_id')->nullable();
            $table->string('name', 100);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['type', 'halodek_id']);
            $table->index(['type', 'name']);
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('holder_org_id')->nullable()->after('holder_id')->constrained('org_units')->nullOnDelete();
        });

        Schema::table('asset_movements', function (Blueprint $table) {
            $table->foreignId('from_holder_org_id')->nullable()->after('to_holder_id')->constrained('org_units')->nullOnDelete();
            $table->foreignId('to_holder_org_id')->nullable()->after('from_holder_org_id')->constrained('org_units')->nullOnDelete();
        });

        // Struktur awal dari data pegawai yang sudah ada (diperbarui dari HaloDek saat sinkron berikutnya).
        $now = now();
        $divisions = [];
        foreach (DB::table('users')->whereNotNull('division')->where('division', '!=', '')->distinct()->orderBy('division')->pluck('division') as $name) {
            $divisions[mb_strtolower($name)] = DB::table('org_units')->insertGetId(['type' => 'divisi', 'name' => $name, 'created_at' => $now, 'updated_at' => $now]);
        }
        $seen = [];
        foreach (DB::table('users')->whereNotNull('department')->where('department', '!=', '')->orderBy('department')->get(['department', 'division']) as $u) {
            $key = mb_strtolower($u->department);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = DB::table('org_units')->insertGetId([
                'type' => 'bidang', 'name' => $u->department,
                'parent_id' => $u->division ? ($divisions[mb_strtolower($u->division)] ?? null) : null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('asset_movements', function (Blueprint $t) {
            $t->dropConstrainedForeignId('to_holder_org_id');
            $t->dropConstrainedForeignId('from_holder_org_id');
        });
        Schema::table('assets', fn (Blueprint $t) => $t->dropConstrainedForeignId('holder_org_id'));
        Schema::dropIfExists('org_units');
    }
};
