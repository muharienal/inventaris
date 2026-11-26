<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Peran diseragamkan dengan HaloDek: "pengelola" dipecah menjadi Kepala Regu dan Staf.
 * Akun pengelola lama menjadi Staf (hak paling sempit); Superadmin bisa menaikkan ke Kepala Regu.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('role', 'pengelola')->update(['role' => 'staf']);
    }

    public function down(): void
    {
        DB::table('users')->whereIn('role', ['kepala_regu', 'staf'])->update(['role' => 'pengelola']);
    }
};
