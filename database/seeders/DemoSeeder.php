<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\Category;
use App\Models\Consumable;
use App\Models\Location;
use App\Models\Unit;
use App\Models\User;
use App\Services\AssetManager;
use App\Services\StockManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Data contoh lengkap (pengembangan lokal / demo). Password akun pengelola = NIK.
 *
 * Orang, lokasi, dan urutan aset SAMA dengan DemoSeeder HaloDek, sehingga laporan contoh di HaloDek
 * menunjuk aset yang benar (ID lokasi 1-10, ID aset sesuai urutan ASSETS di bawah).
 * Jalankan Inventaris lebih dulu, lalu HaloDek.
 */
class DemoSeeder extends Seeder
{
    /** [nik, nama, peran, divisi, bidang, jabatan, unit, aktif] - sama dengan HaloDek. */
    public const PEOPLE = [
        ['00001', 'Superadmin Inventaris', 'superadmin', 'SDM, Umum & K3', 'Umum & IT', 'Superadmin', null, true],
        ['00011', 'Bambang Hartono', 'manajer', 'SDM, Umum & K3', 'Umum & IT', 'Manajer Umum & IT', null, true],
        ['00002', 'Dimas Pratama', 'kepala_regu', 'SDM, Umum & K3', 'Umum & IT', 'Kepala Regu IT', 'it', true],
        ['00003', 'Sari Wulandari', 'kepala_regu', 'SDM, Umum & K3', 'Umum & IT', 'Kepala Regu Sarana dan Prasarana', 'sarpras', true],
        ['00006', 'Maya Anggraini', 'kepala_regu', 'SDM, Umum & K3', 'Umum & IT', 'Kepala Regu Kesekretariatan', 'kesekretariatan', true],
        ['00015', 'Hendra Kusuma', 'kepala_regu', 'SDM, Umum & K3', 'K3 & Keamanan', 'Kepala Regu Keamanan', 'keamanan', true],
        ['00007', 'Rafi Maulana', 'staf', 'SDM, Umum & K3', 'Umum & IT', 'Network Engineer', 'it', true],
        ['00018', 'Nadia Putri', 'staf', 'SDM, Umum & K3', 'Umum & IT', 'IT Support', 'it', true],
        ['00012', 'Bayu Hidayat', 'staf', 'SDM, Umum & K3', 'Umum & IT', 'Teknisi Gedung', 'sarpras', true],
        ['00013', 'Yoga Saputra', 'staf', 'SDM, Umum & K3', 'Umum & IT', 'Driver & Kendaraan', 'sarpras', true],
        ['00014', 'Tari Wulan', 'staf', 'SDM, Umum & K3', 'Umum & IT', 'Sekretaris', 'kesekretariatan', true],
        ['00016', 'Agus Salim', 'staf', 'SDM, Umum & K3', 'K3 & Keamanan', 'Petugas Keamanan', 'keamanan', true],
        ['00017', 'Bima Perkasa', 'staf', 'SDM, Umum & K3', 'K3 & Keamanan', 'Petugas Keamanan', 'keamanan', true],
        ['00004', 'Budi Santoso', 'karyawan', 'Keuangan', 'Akuntansi & Keuangan', 'Staf Keuangan', null, true],
        ['00005', 'Siti Aisyah', 'karyawan', 'SDM, Umum & K3', 'SDM', 'Staf HRD', null, true],
        ['00008', 'Andi Wijaya', 'karyawan', 'Pemasaran', 'Marketing & Penjualan', 'Staf Marketing', null, true],
        ['00009', 'Dewi Lestari', 'karyawan', 'Operasional', 'Produksi', 'Supervisor Produksi', null, true],
        ['00010', 'Rizky Pratama', 'karyawan', 'Operasional', 'Operasional Lapangan', 'Staf Operasional', null, true],
        ['00019', 'Fajar Nugroho', 'karyawan', 'Keuangan', 'Akuntansi & Keuangan', 'Staf Keuangan', null, true],
        ['00020', 'Lina Marlina', 'karyawan', 'Pemasaran', 'Marketing & Penjualan', 'Staf Marketing', null, true],
        ['00021', 'Teguh Wibowo', 'karyawan', 'Operasional', 'Produksi', 'Operator Produksi', null, true],
        ['00022', 'Wulan Sari', 'karyawan', 'SDM, Umum & K3', 'SDM', 'Staf HRD', null, true],
        ['00023', 'Eko Prasetyo', 'karyawan', 'Operasional', 'Operasional Lapangan', 'Staf Operasional', null, false],
    ];

    /** [nama, gedung, lantai] - ID 1..10 sesuai urutan. */
    public const LOCATIONS = [
        ['Ruang Rapat Utama', 'Gedung A', '2'], ['Ruang Finance', 'Gedung A', '1'], ['Ruang HRD', 'Gedung A', '1'],
        ['Ruang Server', 'Gedung A', '1'], ['Workshop Produksi', 'Gedung B', '1'], ['Lobby & Resepsionis', 'Gedung A', '1'],
        ['Gudang', 'Gedung C', '1'], ['Ruang Marketing', 'Gedung A', '2'], ['Pos Keamanan', 'Gerbang Utama', null], ['Area Parkir', 'Gedung B', null],
    ];

    private array $users = [];
    private array $heads = [];
    private AssetManager $manager;

    public function run(): void
    {
        if (! app()->environment('local', 'testing') && ! app()->bound('demo.allowed')) {
            $this->command?->error('Data contoh memakai password = NIK. Jalankan lewat: php artisan inventaris:data-contoh');

            return;
        }

        $this->call([UnitSeeder::class, UserSeeder::class]);
        $this->manager = app(AssetManager::class);
        $realNow = Carbon::now();

        try {
            $this->people();
            $locs = collect(self::LOCATIONS)->map(fn ($l) => Location::firstOrCreate(['name' => $l[0]], ['building' => $l[1], 'floor' => $l[2]]))->values();
            if (Asset::exists()) {
                return;
            }
            $this->assets($locs, $realNow);
            $this->stock($locs, $realNow);
            // Pegawai yang sudah keluar: nonaktif, tetapi laptopnya masih tercatat atas namanya (muncul di Perlu Perhatian).
            foreach (self::PEOPLE as $p) {
                if (! $p[7]) {
                    $this->users[$p[0]]->update(['is_active' => false]);
                }
            }
        } finally {
            Carbon::setTestNow(); // kembali ke waktu sebenarnya
        }
    }

    private function people(): void
    {
        foreach (self::PEOPLE as [$nik, $name, $role, $div, $dept, $pos, $unit, $active]) {
            $u = UserSeeder::make($nik, $name, $role, $dept, $pos, $unit, false);
            // Semua aktif dulu; yang sudah keluar dinonaktifkan setelah asetnya diserahkan (lihat run()).
            $u->forceFill(['name' => $name, 'division' => $div, 'department' => $dept, 'position' => $pos, 'is_active' => true])->save();
            $this->users[$nik] = $u;
            if ($role === 'kepala_regu') {
                $this->heads[$unit] = $u;
            }
        }
    }

    /** Pindahkan "sekarang" ke masa lalu agar riwayat terlihat wajar (dibatasi tidak melewati waktu sebenarnya). */
    private function at(Carbon $realNow, int $daysAgo, int $hour = 9): void
    {
        Carbon::setTestNow($realNow->copy()->subDays($daysAgo)->setTime($hour, mt_rand(0, 59)));
    }

    private function assets($locs, Carbon $realNow): void
    {
        mt_srand(2026);
        $unit = fn (string $c) => Unit::where('code', $c)->first();
        $cat = fn (string $u, string $name) => Category::where('unit_id', $unit($u)->id)->where('type', 'aset')->where('name', $name)->firstOrFail();
        $loc = fn (int $id) => $locs[$id - 1]->id;
        $person = fn (string $nik) => $this->users[$nik];

        // Urutan = ID aset (dipakai laporan contoh HaloDek). Jangan diubah tanpa menyamakan HaloDek.
        $laptopOwners = [
            ['00004', 2], ['00005', 3], ['00008', 8], ['00009', 5], ['00010', 7], ['00019', 2], ['00020', 8], ['00021', 5], ['00022', 3], ['00023', 7],
            ['00002', 4], ['00007', 4], ['00018', 4], ['00011', 1], ['00003', 7], ['00006', 6], ['00014', 6],
        ];
        $laptopModels = [
            ['Lenovo', 'ThinkPad E14 Gen 5', 14500000], ['ASUS', 'Vivobook 14', 9800000], ['HP', 'ProBook 440 G10', 13200000],
            ['Dell', 'Latitude 3420', 12100000], ['Acer', 'Aspire 5', 8900000],
        ];
        foreach ($laptopOwners as $i => [$nik, $locId]) {
            [$brand, $model] = $laptopModels[$i % 5];
            $age = 5 + ($i * 3) % 30; // bulan
            $a = $this->make('it', $cat('it', 'Laptop & PC'), sprintf('AST-%04d', 100 + $i), "{$brand} {$model}", [
                'brand' => $brand, 'model' => $model, 'serial_number' => strtoupper(substr(md5($nik.$model), 0, 10)),
                'specs' => "Intel Core i5, RAM 16 GB, SSD 512 GB\nWindows 11 Pro", 'location_id' => $loc($locId),
                'purchase_date' => $realNow->copy()->subMonths($age)->toDateString(), 'vendor' => 'PT Sumber Komputer',
                'due_date' => $realNow->copy()->subMonths($age)->addMonths(24)->toDateString(),
                'service_interval_months' => 6, 'next_service_at' => $realNow->copy()->addDays(($i * 11) % 90 - 10)->toDateString(),
            ], $realNow, 60);
            $this->at($realNow, 58 - $i);
            $this->manager->move($a, $this->heads['it'], 'serah_terima', ['holder_id' => $person($nik)->id, 'note' => 'Laptop kerja.']);
        }

        $it = 'it';
        $this->make($it, $cat($it, 'Printer & Scanner'), 'AST-0200', 'Epson L3250', ['brand' => 'Epson', 'model' => 'L3250', 'location_id' => $loc(2),
            'purchase_date' => $realNow->copy()->subYears(2)->toDateString(), 'service_interval_months' => 3, 'next_service_at' => $realNow->copy()->subDays(4)->toDateString()], $realNow, 60);
        $this->make($it, $cat($it, 'Printer & Scanner'), 'AST-0201', 'HP LaserJet Pro M404dn', ['brand' => 'HP', 'model' => 'M404dn', 'location_id' => $loc(3),
            'purchase_date' => $realNow->copy()->subYears(3)->toDateString(), 'condition' => 'rusak_ringan'], $realNow, 60);
        $this->make($it, $cat($it, 'Printer & Scanner'), 'AST-0202', 'Scanner Fujitsu fi-7160', ['brand' => 'Fujitsu', 'model' => 'fi-7160', 'location_id' => $loc(2),
            'purchase_date' => $realNow->copy()->subMonths(14)->toDateString()], $realNow, 60);
        $this->make($it, $cat($it, 'Jaringan'), 'NET-001', 'Switch Cisco SG350-28', ['brand' => 'Cisco', 'model' => 'SG350-28', 'location_id' => $loc(4),
            'purchase_date' => $realNow->copy()->subYears(3)->toDateString(), 'status' => 'digunakan'], $realNow, 60);
        $this->make($it, $cat($it, 'Jaringan'), 'NET-002', 'Access Point Ubiquiti U6 Lite (Lt. 2)', ['brand' => 'Ubiquiti', 'model' => 'U6 Lite', 'location_id' => $loc(1),
            'purchase_date' => $realNow->copy()->subMonths(20)->toDateString(), 'status' => 'digunakan'], $realNow, 60);
        $this->make($it, $cat($it, 'Jaringan'), 'NET-003', 'Router MikroTik CCR2004', ['brand' => 'MikroTik', 'model' => 'CCR2004', 'location_id' => $loc(4),
            'purchase_date' => $realNow->copy()->subYears(2)->toDateString(), 'status' => 'digunakan'], $realNow, 60);
        $srv = $this->make($it, $cat($it, 'Server & Storage'), 'SRV-001', 'Server Dell PowerEdge T150', ['brand' => 'Dell', 'model' => 'PowerEdge T150', 'location_id' => $loc(4),
            'purchase_date' => $realNow->copy()->subMonths(30)->toDateString(), 'status' => 'digunakan', 'service_interval_months' => 6,
            'next_service_at' => $realNow->copy()->addDays(25)->toDateString(), 'due_date' => $realNow->copy()->addMonths(6)->toDateString()], $realNow, 60);
        $this->make($it, $cat($it, 'Software'), 'SW-001', 'Microsoft 365 Business (50 lisensi)', ['status' => 'digunakan',
            'due_date' => $realNow->copy()->addMonths(8)->toDateString(), 'notes' => '42 dari 50 lisensi terpakai', 'purchase_date' => $realNow->copy()->subMonths(4)->toDateString()], $realNow, 60);
        $this->make($it, $cat($it, 'Software'), 'SW-002', 'Antivirus Endpoint (60 lisensi)', ['status' => 'digunakan',
            'due_date' => $realNow->copy()->addDays(18)->toDateString(), 'notes' => '58 dari 60 lisensi terpakai'], $realNow, 60);
        $this->make($it, $cat($it, 'Akses & Lisensi'), 'SW-003', 'Lisensi ERP Accurate (10 pengguna)', ['status' => 'digunakan',
            'due_date' => $realNow->copy()->subDays(6)->toDateString(), 'notes' => 'Perpanjangan sedang diproses pengadaan'], $realNow, 60);
        $proj = $this->make($it, $cat($it, 'Monitor & Aksesoris'), 'AST-0300', 'Proyektor Epson EB-X51', ['brand' => 'Epson', 'model' => 'EB-X51', 'location_id' => $loc(7),
            'purchase_date' => $realNow->copy()->subYear()->toDateString()], $realNow, 60);
        $mon = $this->make($it, $cat($it, 'Monitor & Aksesoris'), 'AST-0301', 'Monitor LG 24MK430H', ['brand' => 'LG', 'model' => '24MK430H', 'location_id' => $loc(2),
            'purchase_date' => $realNow->copy()->subMonths(18)->toDateString(), 'status' => 'digunakan'], $realNow, 60);

        $sp = 'sarpras';
        $ac1 = $this->make($sp, $cat($sp, 'AC'), 'SP-AC-001', 'AC Daikin 2 PK', ['brand' => 'Daikin', 'model' => 'FTKC50', 'location_id' => $loc(1), 'status' => 'digunakan',
            'purchase_date' => $realNow->copy()->subYears(3)->toDateString(), 'vendor' => 'CV Sejuk Mandiri', 'service_interval_months' => 3,
            'next_service_at' => $realNow->copy()->addDays(9)->toDateString()], $realNow, 60);
        $this->make($sp, $cat($sp, 'AC'), 'SP-AC-002', 'AC Panasonic 1 PK', ['brand' => 'Panasonic', 'location_id' => $loc(3), 'status' => 'digunakan',
            'purchase_date' => $realNow->copy()->subYears(2)->toDateString(), 'service_interval_months' => 3, 'next_service_at' => $realNow->copy()->subDays(2)->toDateString()], $realNow, 60);
        $this->make($sp, $cat($sp, 'AC'), 'SP-AC-003', 'AC Sharp 1 PK', ['brand' => 'Sharp', 'location_id' => $loc(2), 'status' => 'digunakan',
            'purchase_date' => $realNow->copy()->subYears(4)->toDateString()], $realNow, 60);
        $this->make($sp, $cat($sp, 'AC'), 'SP-AC-004', 'AC Daikin 1,5 PK Ruang Server', ['brand' => 'Daikin', 'location_id' => $loc(4), 'status' => 'digunakan',
            'purchase_date' => $realNow->copy()->subYears(2)->toDateString(), 'service_interval_months' => 3, 'next_service_at' => $realNow->copy()->addDays(30)->toDateString()], $realNow, 60);
        $car = $this->make($sp, $cat($sp, 'Kendaraan'), 'SP-KND-001', 'Toyota Hiace B 1234 XY', ['brand' => 'Toyota', 'model' => 'Hiace Commuter', 'serial_number' => 'MHFJW8EM-2022',
            'location_id' => $loc(10), 'purchase_date' => $realNow->copy()->subYears(4)->toDateString(), 'due_date' => $realNow->copy()->addDays(20)->toDateString(),
            'notes' => 'Pajak tahunan', 'service_interval_months' => 6, 'next_service_at' => $realNow->copy()->addDays(40)->toDateString()], $realNow, 60);
        $moto = $this->make($sp, $cat($sp, 'Kendaraan'), 'SP-KND-002', 'Honda Vario B 5678 ZZ', ['brand' => 'Honda', 'model' => 'Vario 125', 'location_id' => $loc(10),
            'purchase_date' => $realNow->copy()->subYears(2)->toDateString(), 'service_interval_months' => 4, 'next_service_at' => $realNow->copy()->addDays(15)->toDateString()], $realNow, 60);
        $this->make($sp, $cat($sp, 'Kelistrikan & Genset'), 'SP-LST-001', 'Genset Perkins 100 kVA', ['brand' => 'Perkins', 'location_id' => $loc(5), 'status' => 'digunakan',
            'purchase_date' => $realNow->copy()->subYears(5)->toDateString(), 'service_interval_months' => 6, 'next_service_at' => $realNow->copy()->addDays(3)->toDateString()], $realNow, 60);
        $this->make($sp, $cat($sp, 'Furniture'), 'SP-FRN-001', 'Kursi Kerja Ergonomis', ['location_id' => $loc(2), 'status' => 'digunakan', 'condition' => 'rusak_ringan',
            'purchase_date' => $realNow->copy()->subYears(3)->toDateString()], $realNow, 60);
        $this->make($sp, $cat($sp, 'Ruangan'), 'RG-001', 'Ruang Rapat Utama', ['location_id' => $loc(1), 'status' => 'digunakan', 'notes' => 'Kapasitas 20 orang'], $realNow, 60);

        $ks = 'kesekretariatan';
        $this->make($ks, $cat($ks, 'Peralatan Rapat'), 'KS-RR-01', 'Smart TV Samsung 55"', ['brand' => 'Samsung', 'location_id' => $loc(1), 'status' => 'digunakan',
            'purchase_date' => $realNow->copy()->subYear()->toDateString()], $realNow, 60);
        $this->make($ks, $cat($ks, 'Peralatan Kantor'), 'KS-KTR-01', 'Mesin Fotokopi Canon iR 2625', ['brand' => 'Canon', 'model' => 'iR 2625', 'location_id' => $loc(6), 'status' => 'digunakan',
            'purchase_date' => $realNow->copy()->subYears(2)->toDateString(), 'service_interval_months' => 2, 'next_service_at' => $realNow->copy()->subDays(8)->toDateString()], $realNow, 60);
        $this->make($ks, $cat($ks, 'Lemari Arsip'), 'KS-ARS-01', 'Lemari Arsip Besi 4 Laci', ['location_id' => $loc(7), 'status' => 'digunakan',
            'purchase_date' => $realNow->copy()->subYears(6)->toDateString()], $realNow, 60);

        $km = 'keamanan';
        $this->make($km, $cat($km, 'CCTV'), 'KM-CCTV-01', 'CCTV Lobby', ['brand' => 'Hikvision', 'location_id' => $loc(6), 'status' => 'digunakan',
            'purchase_date' => $realNow->copy()->subYears(2)->toDateString()], $realNow, 60);
        $this->make($km, $cat($km, 'CCTV'), 'KM-CCTV-02', 'CCTV Area Parkir', ['brand' => 'Hikvision', 'location_id' => $loc(10), 'status' => 'digunakan', 'condition' => 'rusak_ringan',
            'purchase_date' => $realNow->copy()->subYears(2)->toDateString()], $realNow, 60);
        $this->make($km, $cat($km, 'CCTV'), 'KM-CCTV-03', 'NVR Hikvision 16 Channel', ['brand' => 'Hikvision', 'location_id' => $loc(9), 'status' => 'digunakan',
            'purchase_date' => $realNow->copy()->subYears(2)->toDateString()], $realNow, 60);
        $this->make($km, $cat($km, 'APAR & Alat Pemadam'), 'KM-APAR-01', 'APAR Powder 6 kg Workshop', ['location_id' => $loc(5), 'status' => 'digunakan',
            'due_date' => $realNow->copy()->addDays(5)->toDateString(), 'notes' => 'Isi ulang tahunan'], $realNow, 60);
        $this->make($km, $cat($km, 'APAR & Alat Pemadam'), 'KM-APAR-02', 'APAR CO2 5 kg Ruang Server', ['location_id' => $loc(4), 'status' => 'digunakan',
            'due_date' => $realNow->copy()->addMonths(7)->toDateString(), 'notes' => 'Isi ulang tahunan'], $realNow, 60);
        $ht1 = $this->make($km, $cat($km, 'Alat Komunikasi (HT)'), 'KM-HT-01', 'HT Motorola XiR P3688', ['brand' => 'Motorola', 'model' => 'XiR P3688', 'location_id' => $loc(9),
            'purchase_date' => $realNow->copy()->subYear()->toDateString()], $realNow, 60);
        $ht2 = $this->make($km, $cat($km, 'Alat Komunikasi (HT)'), 'KM-HT-02', 'HT Motorola XiR P3688', ['brand' => 'Motorola', 'model' => 'XiR P3688', 'location_id' => $loc(9),
            'purchase_date' => $realNow->copy()->subYear()->toDateString()], $realNow, 60);
        $old = $this->make($it, $cat($it, 'Laptop & PC'), 'AST-0099', 'PC Rakitan Lama (Core i3)', ['location_id' => $loc(5), 'condition' => 'rusak_berat',
            'purchase_date' => $realNow->copy()->subYears(7)->toDateString(), 'notes' => 'Menunggu penghapusan aset'], $realNow, 60);

        // ---- riwayat mutasi & servis
        $this->at($realNow, 50);
        $this->manager->move($ht1, $this->heads['keamanan'], 'serah_terima', ['holder_id' => $person('00016')->id, 'note' => 'HT jaga shift pagi.']);
        $this->manager->move($ht2, $this->heads['keamanan'], 'serah_terima', ['holder_id' => $person('00017')->id, 'note' => 'HT jaga shift malam.']);
        $this->manager->move($moto, $this->heads['sarpras'], 'serah_terima', ['holder_id' => $person('00013')->id, 'note' => 'Kendaraan operasional kurir.']);

        $this->at($realNow, 12, 10);
        $this->manager->move($proj, $this->heads['it'], 'pinjam', ['holder_id' => $person('00008')->id, 'location_id' => $loc(1),
            'due_back_at' => $realNow->copy()->subDays(2)->toDateString(), 'note' => 'Presentasi klien di kantor cabang.']);
        $this->at($realNow, 3, 14);
        $this->manager->move($mon, $this->heads['it'], 'pindah', ['location_id' => $loc(3), 'note' => 'Dipindah untuk staf HRD baru.']);

        $this->at($realNow, 40, 9);
        $this->manager->move($old, $this->heads['it'], 'pindah', ['location_id' => $loc(7), 'note' => 'Ditarik dari Workshop, rusak berat.']);
        $old->update(['status' => 'nonaktif']);

        $this->at($realNow, 92, 10);
        $this->manager->addService($ac1, $this->heads['sarpras'], ['type' => 'servis_rutin', 'status' => 'selesai', 'completed_at' => Carbon::now()->toDateString(),
            'vendor' => 'CV Sejuk Mandiri', 'cost' => 350000, 'description' => 'Cuci AC dan cek freon.', 'result' => 'Normal, freon cukup.']);
        $ac1->update(['next_service_at' => $realNow->copy()->addDays(9)->toDateString()]);
        $this->at($realNow, 35, 13);
        $this->manager->addService($srv, $this->heads['it'], ['type' => 'servis_rutin', 'status' => 'selesai', 'completed_at' => Carbon::now()->toDateString(),
            'handler_name' => 'Rafi Maulana', 'description' => 'Bersihkan debu, cek RAID dan update firmware.', 'result' => 'Semua disk sehat.']);
        $srv->update(['next_service_at' => $realNow->copy()->addDays(25)->toDateString()]);
        $this->at($realNow, 1, 9);
        $this->manager->addService($car, $this->heads['sarpras'], ['type' => 'perbaikan', 'status' => 'dijadwalkan', 'scheduled_at' => $realNow->copy()->addDays(2)->toDateString(),
            'vendor' => 'Auto2000', 'description' => 'Rem berdecit, ganti kampas rem depan.']);
    }

    private function make(string $unitCode, Category $category, string $code, string $name, array $extra, Carbon $realNow, int $daysAgo): Asset
    {
        $this->at($realNow, $daysAgo, 8);
        $asset = Asset::create($extra + [ // isian $extra (status, kondisi, dst.) diutamakan
            'unit_id' => $category->unit_id, 'category_id' => $category->id, 'code' => $code, 'name' => $name,
            'status' => 'tersedia', 'condition' => 'baik',
        ]);
        $asset->movements()->create(['type' => 'pindah', 'to_location_id' => $asset->location_id, 'note' => 'Pencatatan awal aset.', 'user_id' => $this->heads[$unitCode]->id]);

        return $asset;
    }

    private function stock($locs, Carbon $realNow): void
    {
        $stock = app(StockManager::class);
        $unit = fn (string $c) => Unit::where('code', $c)->first();
        $cat = fn (string $u, string $name) => Category::where('unit_id', $unit($u)->id)->where('type', 'stok')->where('name', $name)->firstOrFail();

        // [unit, kategori, kode, nama, satuan, stok awal, minimum, [ [hari lalu, jumlah keluar, penerima], ... ]]
        $items = [
            ['it', 'Tinta & Toner', 'TNT-EPS-BK', 'Tinta Epson 003 Hitam', 'botol', 10, 4, [[40, 2, 'Budi Santoso (Keuangan)'], [21, 3, 'Fajar Nugroho (Keuangan)'], [6, 2, 'Siti Aisyah (HRD)']]],
            ['it', 'Tinta & Toner', 'TNR-HP-59A', 'Toner HP 59A', 'pcs', 4, 2, [[15, 1, 'Wulan Sari (HRD)']]],
            ['it', 'Kabel & Konektor', 'KBL-LAN-5M', 'Kabel LAN Cat6 5 m', 'pcs', 20, 5, [[33, 4, 'Ruang Marketing'], [9, 2, 'Ruang Rapat Utama']]],
            ['it', 'Suku Cadang IT', 'SPR-MOUSE', 'Mouse USB Logitech', 'pcs', 8, 3, [[28, 2, 'Andi Wijaya (Marketing)'], [11, 3, 'Teguh Wibowo (Produksi)']]],
            ['sarpras', 'Lampu & Listrik', 'LMP-LED-18', 'Lampu LED 18 W', 'pcs', 24, 10, [[45, 6, 'Koridor Gedung B'], [20, 8, 'Workshop Produksi'], [4, 4, 'Gudang']]],
            ['sarpras', 'Kebersihan', 'KBR-PEL', 'Cairan Pel Lantai 5 L', 'jeriken', 10, 4, [[30, 3, 'Cleaning Service'], [10, 3, 'Cleaning Service']]],
            ['sarpras', 'Suku Cadang Gedung', 'SPR-KERAN', 'Keran Wastafel Stainless', 'pcs', 5, 2, [[18, 1, 'Toilet Lt. 1']]],
            ['kesekretariatan', 'Kertas', 'KRT-A4', 'Kertas A4 80 gsm', 'rim', 60, 20, [[50, 10, 'Ruang Finance'], [35, 8, 'Ruang HRD'], [14, 12, 'Ruang Marketing'], [2, 6, 'Lobby & Resepsionis']]],
            ['kesekretariatan', 'Alat Tulis Kantor (ATK)', 'ATK-PLP', 'Pulpen Hitam', 'box', 12, 3, [[25, 3, 'Ruang Finance'], [8, 2, 'Ruang HRD']]],
            ['kesekretariatan', 'Alat Tulis Kantor (ATK)', 'ATK-MAP', 'Map Plastik Snelhecter', 'pak', 6, 5, [[12, 3, 'Ruang HRD']]],
            ['kesekretariatan', 'Konsumsi Rapat', 'KNS-AIR', 'Air Mineral 600 ml', 'dus', 15, 5, [[16, 4, 'Rapat Direksi'], [5, 6, 'Training Karyawan Baru']]],
            ['keamanan', 'APD (Alat Pelindung Diri)', 'APD-MSK', 'Masker Medis', 'box', 20, 5, [[22, 5, 'Workshop Produksi']]],
            ['keamanan', 'APD (Alat Pelindung Diri)', 'APD-HELM', 'Helm Safety Kuning', 'pcs', 10, 4, [[19, 4, 'Dewi Lestari (Produksi)'], [3, 3, 'Teguh Wibowo (Produksi)']]],
            ['keamanan', 'Perlengkapan K3', 'K3-P3K', 'Isi Kotak P3K', 'paket', 6, 2, [[27, 2, 'Pos Keamanan']]],
        ];

        foreach ($items as [$u, $catName, $code, $name, $label, $initial, $min, $outs]) {
            $this->at($realNow, 55, 8);
            $item = Consumable::create(['unit_id' => $unit($u)->id, 'category_id' => $cat($u, $catName)->id, 'code' => $code, 'name' => $name,
                'unit_label' => $label, 'min_stock' => $min, 'location_id' => $locs[6]->id]);
            $stock->adjust($item, $this->heads[$u], 'masuk', $initial, 'Stok awal.');
            foreach ($outs as [$daysAgo, $qty, $who]) {
                $this->at($realNow, $daysAgo, mt_rand(9, 15));
                $stock->adjust($item->fresh(), $this->heads[$u], 'keluar', $qty, null, $who);
            }
        }
        // Satu penghitungan ulang (stok opname).
        $this->at($realNow, 7, 15);
        $paper = Consumable::where('code', 'ATK-PLP')->first();
        $stock->adjust($paper, $this->heads['kesekretariatan'], 'penyesuaian', 6, 'Stok opname bulanan: selisih 1 box.');
    }
}
