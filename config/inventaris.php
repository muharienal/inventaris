<?php

// Batas unggah per berkas mengikuti php.ini bila lebih kecil (Laragon bawaan: 2 MB),
// supaya pesan di form sesuai dan foto tidak "hilang" diam-diam oleh PHP.
$phpUploadKb = (static function (): int {
    $v = trim((string) ini_get('upload_max_filesize'));
    $n = (float) $v;

    return (int) match (strtolower(substr($v, -1))) {
        'g' => $n * 1048576, 'm' => $n * 1024, 'k' => $n, default => $n / 1024,
    };
})();

return [

    'name' => env('APP_NAME', 'Inventaris'),
    'full_name' => 'Inventaris Umum & IT',
    'company' => env('COMPANY_NAME', 'PT Petrokopindo Cipta Selaras'),
    'tagline' => 'Pengelolaan Aset & Stok Terpadu',

    'roles' => [
        'superadmin' => 'Superadmin',
        'manajer' => 'Manajer',
        'kepala_regu' => 'Kepala Regu',
        'staf' => 'Staf',
        'karyawan' => 'Karyawan',
    ],

    // Status aset.
    'statuses' => [
        'tersedia' => ['label' => 'Tersedia', 'tone' => 'green'],
        'digunakan' => ['label' => 'Digunakan', 'tone' => 'blue'],
        'dipinjam' => ['label' => 'Dipinjam', 'tone' => 'violet'],
        'perbaikan' => ['label' => 'Perbaikan', 'tone' => 'amber'],
        'rusak' => ['label' => 'Rusak', 'tone' => 'red'],
        'nonaktif' => ['label' => 'Nonaktif', 'tone' => 'gray'],
    ],

    'conditions' => [
        'baik' => ['label' => 'Baik', 'tone' => 'green'],
        'rusak_ringan' => ['label' => 'Rusak ringan', 'tone' => 'amber'],
        'rusak_berat' => ['label' => 'Rusak berat', 'tone' => 'red'],
    ],

    // Jenis mutasi aset.
    'movements' => [
        'serah_terima' => 'Serah terima',
        'pindah' => 'Pindah lokasi',
        'pinjam' => 'Dipinjamkan',
        'kembali' => 'Dikembalikan',
        'tarik' => 'Ditarik ke gudang',
    ],

    'service_types' => [
        'servis_rutin' => 'Servis rutin',
        'perbaikan' => 'Perbaikan',
        'kalibrasi' => 'Kalibrasi',
        'ganti_part' => 'Ganti suku cadang',
        'lainnya' => 'Lainnya',
    ],

    'request_statuses' => [
        'menunggu' => ['label' => 'Menunggu', 'tone' => 'amber'],
        'disetujui' => ['label' => 'Disetujui', 'tone' => 'green'],
        'ditolak' => ['label' => 'Ditolak', 'tone' => 'red'],
        'dibatalkan' => ['label' => 'Dibatalkan', 'tone' => 'gray'],
    ],

    // Peringatan: servis, garansi/lisensi jatuh tempo dalam sekian hari.
    'due_soon_days' => 30,

    'photos' => [
        'max_files' => 6,
        'max_kb' => $phpUploadKb > 0 ? min(3072, $phpUploadKb) : 3072,
        'mimes' => ['jpg', 'jpeg', 'png', 'webp'],
    ],

    'per_page' => 15,

    // Integrasi HaloDek (aplikasi tiket aduan).
    // Alamat yang dicetak di stiker QR. Isi dengan alamat TETAP (IP tetap / nama domain kantor) yang tidak
    // berubah walau Inventaris pindah komputer. Kosong = memakai APP_URL.
    'qr_url' => env('QR_URL'),

    'halodek_url' => env('HALODEK_URL'),             // mis. http://192.168.1.10:8081 (untuk tautan & QR)
    'api_token' => env('INVENTARIS_API_TOKEN'),       // token rahasia yang sama dipasang di .env HaloDek
];
