<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Unit pengelola, lokasi, pengguna, dan log aktivitas. */
return new class extends Migration
{
    public function up(): void
    {
        // Unit pengelola. "code" sama dengan slug regu di HaloDek (it, sarpras, kesekretariatan, keamanan).
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 100);
            $table->string('group', 10)->default('umum');   // umum | it
            $table->string('description', 255)->nullable();
            $table->string('color', 20)->default('blue');
            $table->string('icon', 40)->default('box');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Lokasi / ruangan: data utama, juga dipakai HaloDek lewat API.
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('building', 100)->nullable();
            $table->string('floor', 30)->nullable();
            $table->string('pic_name', 150)->nullable();       // penanggung jawab ruangan
            $table->string('note', 1000)->nullable();
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('nik', 50)->unique();             // dipakai untuk login & penghubung ke HaloDek
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('phone', 30)->nullable();
            $table->string('department', 100)->nullable();
            $table->string('position', 100)->nullable();
            $table->string('role', 30)->default('karyawan')->index(); // superadmin | pengelola | karyawan
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete(); // unit pengelola
            $table->string('password');
            $table->boolean('must_change_password')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 50)->index();
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('description', 500);
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('users');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('units');
    }
};
