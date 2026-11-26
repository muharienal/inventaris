<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Kategori, aset, foto, mutasi, servis, dan laporan kendala dari HaloDek. */
return new class extends Migration
{
    public function up(): void
    {
        // Kategori per unit. type: aset | stok (barang habis pakai).
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10)->default('aset');
            $table->string('name', 100);
            $table->unsignedTinyInteger('useful_life_years')->nullable(); // umur ekonomis bawaan (penyusutan)
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['unit_id', 'type', 'name']);
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->string('brand', 80)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('serial_number', 120)->nullable();
            $table->text('specs')->nullable();
            $table->string('status', 20)->default('tersedia')->index();
            $table->string('condition', 20)->default('baik');
            $table->foreignId('holder_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_price', 15, 2)->nullable();
            $table->string('vendor', 120)->nullable();
            $table->unsignedTinyInteger('useful_life_years')->nullable();   // kosong = ikut kategori
            $table->date('due_date')->nullable();                           // garansi / masa berlaku lisensi
            $table->date('next_service_at')->nullable();
            $table->unsignedSmallInteger('service_interval_months')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['unit_id', 'code']);
            $table->index('due_date');
            $table->index('next_service_at');
        });

        Schema::create('asset_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->timestamps();
        });

        // Riwayat pemegang & lokasi: serah terima, pindah, pinjam, kembali, tarik.
        Schema::create('asset_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->foreignId('from_holder_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_holder_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_holder_name')->nullable();   // salinan nama, tetap terbaca walau akun dihapus
            $table->string('to_holder_name')->nullable();
            $table->foreignId('from_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->date('due_back_at')->nullable();          // batas pengembalian (pinjam)
            $table->timestamp('returned_at')->nullable();     // diisi saat pinjaman dikembalikan
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // dicatat oleh
            $table->timestamps();
            $table->index(['asset_id', 'created_at']);
        });

        Schema::create('asset_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('servis_rutin');
            $table->string('status', 15)->default('dijadwalkan'); // dijadwalkan | selesai | batal
            $table->date('scheduled_at')->nullable();
            $table->date('completed_at')->nullable();
            $table->string('vendor', 120)->nullable();
            $table->decimal('cost', 15, 2)->nullable();
            $table->text('description')->nullable();
            $table->text('result')->nullable();
            $table->string('report_number', 40)->nullable();  // nomor laporan HaloDek terkait (bila ada)
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['asset_id', 'status']);
        });

        // Laporan kendala dari HaloDek (dikirim HaloDek lewat API saat laporan dibuat/berubah).
        Schema::create('asset_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('report_id')->unique();  // ID laporan di HaloDek
            $table->string('report_number', 40);
            $table->string('title');
            $table->string('status', 20);
            $table->string('status_label', 40);
            $table->string('reporter_name')->nullable();
            $table->string('url', 500)->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['asset_reports', 'asset_services', 'asset_movements', 'asset_photos', 'assets', 'categories'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
