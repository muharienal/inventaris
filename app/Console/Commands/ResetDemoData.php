<?php

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * Kosongkan SEMUA data Inventaris lalu isi dengan data contoh (password pengelola = NIK).
 * Jalankan sebelum HaloDek: php artisan halodek:data-contoh di folder HaloDek.
 */
class ResetDemoData extends Command
{
    protected $signature = 'inventaris:data-contoh {--paksa : Lewati konfirmasi}';

    protected $description = 'HAPUS semua data Inventaris lalu isi data contoh (untuk uji coba)';

    public function handle(): int
    {
        $this->warn('Semua data Inventaris (aset, stok, riwayat, akun, foto) akan DIHAPUS dan diganti data contoh.');
        $this->warn('Akun contoh memakai password = NIK. Jangan dipakai sebagai data sungguhan.');
        if (! $this->option('paksa') && $this->ask('Ketik HAPUS untuk melanjutkan') !== 'HAPUS') {
            $this->line('Dibatalkan.');

            return self::FAILURE;
        }

        $this->info('Mengosongkan database...');
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->output->write(Artisan::output());

        // Foto aset lama ikut dibersihkan.
        Storage::disk('local')->deleteDirectory('aset');
        Artisan::call('cache:clear');

        $this->info('Mengisi data contoh...');
        app()->instance('demo.allowed', true);
        Artisan::call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
        $this->output->write(Artisan::output());

        $this->newLine();
        $this->info('Selesai. Masuk dengan NIK = password:');
        $this->table(['NIK', 'Peran'], [
            ['00001', 'Superadmin'], ['00011', 'Manajer'], ['00002', 'Kepala Regu IT'], ['00003', 'Kepala Regu Sarana dan Prasarana'],
            ['00006', 'Kepala Regu Kesekretariatan'], ['00015', 'Kepala Regu Keamanan'], ['00007', 'Staf IT'],
        ]);
        $this->line('Berikutnya jalankan di folder HaloDek: php artisan halodek:data-contoh');

        return self::SUCCESS;
    }
}
