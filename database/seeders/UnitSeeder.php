<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Unit;
use Illuminate\Database\Seeder;

/** Unit pengelola + kategori bawaan. Kode unit = slug regu di HaloDek. */
class UnitSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            ['code' => 'it', 'name' => 'IT', 'group' => 'it', 'color' => 'blue', 'icon' => 'server', 'description' => 'Komputer, jaringan, perangkat & lisensi software',
                'aset' => [['Laptop & PC', 4], ['Printer & Scanner', 4], ['Jaringan', 5], ['Server & Storage', 5], ['Software', 1], ['Akses & Lisensi', 1], ['Monitor & Aksesoris', 4]],
                'stok' => ['Tinta & Toner', 'Kabel & Konektor', 'Suku Cadang IT']],
            ['code' => 'sarpras', 'name' => 'Sarana dan Prasarana', 'group' => 'umum', 'color' => 'amber', 'icon' => 'building', 'description' => 'Gedung, kendaraan, AC, kelistrikan, furniture',
                'aset' => [['AC', 5], ['Kendaraan', 8], ['Furniture', 8], ['Kelistrikan & Genset', 10], ['Peralatan Gedung', 8], ['Ruangan', null]],
                'stok' => ['Kebersihan', 'Lampu & Listrik', 'Suku Cadang Gedung']],
            ['code' => 'kesekretariatan', 'name' => 'Kesekretariatan', 'group' => 'umum', 'color' => 'violet', 'icon' => 'document', 'description' => 'Peralatan kantor, ruang rapat, arsip',
                'aset' => [['Peralatan Kantor', 5], ['Peralatan Rapat', 5], ['Lemari Arsip', 8]],
                'stok' => ['Alat Tulis Kantor (ATK)', 'Kertas', 'Konsumsi Rapat']],
            ['code' => 'keamanan', 'name' => 'Keamanan', 'group' => 'umum', 'color' => 'rose', 'icon' => 'shield', 'description' => 'CCTV, APAR, perlengkapan K3 & keamanan',
                'aset' => [['CCTV', 5], ['APAR & Alat Pemadam', 5], ['Perlengkapan Keamanan', 4], ['Alat Komunikasi (HT)', 4]],
                'stok' => ['APD (Alat Pelindung Diri)', 'Perlengkapan K3']],
        ];

        foreach ($units as $i => $u) {
            $unit = Unit::firstOrCreate(['code' => $u['code']], [
                'name' => $u['name'], 'group' => $u['group'], 'color' => $u['color'], 'icon' => $u['icon'],
                'description' => $u['description'], 'sort_order' => $i + 1,
            ]);
            foreach ($u['aset'] as $j => [$name]) {
                Category::firstOrCreate(['unit_id' => $unit->id, 'type' => 'aset', 'name' => $name], ['sort_order' => $j + 1]);
            }
            foreach ($u['stok'] as $j => $name) {
                Category::firstOrCreate(['unit_id' => $unit->id, 'type' => 'stok', 'name' => $name], ['sort_order' => $j + 1]);
            }
        }
    }
}
