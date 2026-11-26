<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Token stiker QR disimpan per aset agar stiker yang sudah ditempel tetap berlaku walau APP_KEY diganti.
 * Aset lama diisi dengan token yang SAMA dengan yang sudah tercetak (turunan APP_KEY saat ini).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('verify_token', 32)->nullable()->after('code');
        });

        $key = (string) config('app.key');
        DB::table('assets')->whereNull('verify_token')->orderBy('id')->select('id')->chunkById(500, function ($rows) use ($key) {
            foreach ($rows as $r) {
                DB::table('assets')->where('id', $r->id)->update(['verify_token' => substr(hash_hmac('sha256', 'cek-aset:'.$r->id, $key), 0, 20)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('verify_token');
        });
    }
};
