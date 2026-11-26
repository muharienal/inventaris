<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Barang habis pakai (ATK, tinta, suku cadang): stok, mutasi stok, dan permintaan karyawan. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consumables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->string('unit_label', 20)->default('pcs');   // satuan: pcs, rim, botol, box, ...
            $table->integer('stock')->default(0);
            $table->unsignedInteger('min_stock')->default(0);
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['unit_id', 'code']);
        });

        Schema::create('consumable_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumable_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();  // peminta
            $table->string('requester_name');
            $table->unsignedInteger('qty');
            $table->string('note', 500)->nullable();
            $table->string('status', 15)->default('menunggu')->index();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consumable_id')->constrained()->cascadeOnDelete();
            $table->string('type', 15);                 // masuk | keluar | penyesuaian
            $table->integer('qty');                     // + masuk, - keluar
            $table->integer('stock_after');
            $table->string('note', 500)->nullable();
            $table->foreignId('request_id')->nullable()->constrained('consumable_requests')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['consumable_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('consumable_requests');
        Schema::dropIfExists('consumables');
    }
};
