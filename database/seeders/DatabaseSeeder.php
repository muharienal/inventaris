<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            UnitSeeder::class,
            UserSeeder::class,
        ]);

        // Data contoh (akun demo, password = NIK) TIDAK dibuat otomatis.
        // Hanya untuk pengembangan lokal: php artisan db:seed --class=DemoSeeder
        // Memindahkan data inventaris lama dari HaloDek: php artisan inventaris:impor-halodek
    }
}
