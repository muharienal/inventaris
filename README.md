# Inventaris Umum & IT

Aplikasi pengelolaan aset dan barang habis pakai PT Petrokopindo Cipta Selaras untuk unit **IT**, **Sarana dan Prasarana**, **Kesekretariatan**, dan **Keamanan**. Aplikasi ini berdiri sendiri, terpisah dari **HaloDek** (aplikasi tiket aduan). Keduanya bertukar data lewat API. Dibangun dengan Laravel 10 (PHP + Blade), tanpa npm / Node / CDN.

## Fitur

- **Aset:** kode, merek/model, nomor seri, spesifikasi, foto (maks. 6), tanggal beli, vendor, garansi/lisensi, status, dan kondisi.
- **Riwayat & mutasi:** serah terima, pindah lokasi, pinjam dengan tanggal kembali (keterlambatan ditandai), kembalikan, dan tarik ke gudang. Semua tercatat di riwayat aset.
- **Servis & perawatan:** jadwal servis, biaya, vendor, dan hasil. Servis berikutnya dihitung otomatis dari interval. Aset yang sedang diperbaiki (servis manual) berstatus *Perbaikan*.
- **Riwayat perbaikan otomatis dari HaloDek:** laporan kendala di HaloDek yang terkait aset langsung tercatat sebagai *Perbaikan*. Saat petugas mulai menangani: "Sedang ditangani" (dengan nama petugas). Saat selesai: tanggal selesai dan catatan penyelesaian menjadi hasil perbaikan. Bila laporan dibatalkan/ditolak setelah ditangani: "Batal". Catatan ini tidak diubah manual di Inventaris; tidak perlu input ulang.
- **Stok habis pakai:** stok masuk, keluar, dan penyesuaian, dengan peringatan stok minimum. Stok keluar mencatat penerimanya (mis. "Andi (Keuangan)"), dan beranda menampilkan stok keluar terbaru.
- **Pemegang nonaktif:** aset yang masih tercatat dipegang pegawai yang sudah nonaktif muncul di Beranda (Perlu Perhatian), filter Aset "Pemegang sudah nonaktif", dan peringatan di detail aset.
- **Lokasi** dipakai bersama semua unit: Kepala Regu boleh menambah; mengubah dan menghapus hanya Superadmin.
- **QR stiker:** cetak dari daftar atau detail aset. Memindai QR membuka halaman **cek aset** di Inventaris tanpa login: tanda "Aset terdaftar" (atau "sudah tidak aktif"), kode, merek/model, nomor seri, kategori, lokasi, pemegang, status, kondisi, dan servis terakhir, untuk dicocokkan dengan barang fisiknya. Ada tombol **Laporkan kendala** ke HaloDek. Alamatnya memakai `QR_URL` (atau `APP_URL` bila kosong) dan token acak per aset yang **tersimpan di database**, jadi stiker tetap berlaku walau `APP_KEY` diganti. **Sebelum mencetak massal, isi `QR_URL` dengan alamat tetap** (IP tetap atau nama domain kantor, bukan nama laptop/komputer): bila alamat di stiker berubah, stiker yang sudah ditempel tidak bisa dibuka. Halaman cetak memberi peringatan bila alamatnya `localhost` atau nama komputer. Stiker lama (mengarah ke HaloDek) tetap berfungsi; cetak ulang agar memakai halaman cek aset.
- **Laporan kendala dari HaloDek** muncul di riwayat aset, lengkap dengan statusnya.
- **Lainnya:** impor CSV, ekspor Excel, master kategori dan lokasi, log aktivitas, serta tampilan HP (320 px) sampai layar lebar.

## Peran

| Peran | Untuk | Kemampuan |
|---|---|---|
| Superadmin | Admin aplikasi | Semua unit, akun, log aktivitas |
| Manajer | Manajer Umum & IT | Melihat semua unit, ekspor, cetak QR, log aktivitas. **Tidak bisa mengubah data** |
| Kepala Regu | Kepala regu unit | Semua pekerjaan unitnya, termasuk kategori, lokasi, hapus, dan impor CSV |
| Staf | Staf unit | Pekerjaan harian: tambah/ubah aset, foto, mutasi, servis, stok masuk/keluar |
| Karyawan | Pemegang aset | **Tidak punya akun.** Datanya diambil dari HaloDek |

Peran ini sama dengan HaloDek. Semua yang punya akun masuk dengan **NIK + password**. Akun di Inventaris terpisah dari HaloDek dan tidak memakai email.

### Data pegawai terpusat di HaloDek

Data diri **semua orang** (NIK, nama, divisi, bidang, jabatan, No. WA) hanya diisi dan diubah di HaloDek. Inventaris mengambilnya lewat `GET {HALODEK_URL}/api/v1/employees`:
- otomatis tiap 5 menit selama Inventaris sedang dibuka orang (tanpa Task Scheduler);
- saat NIK pemegang yang diketik belum dikenal;
- lewat tombol **Ambil sekarang** di menu Pengguna, atau `php artisan inventaris:sinkron-karyawan`.

Di menu **Pengguna**, Inventaris hanya mengatur **siapa yang bisa masuk** dan perannya:
- **Beri akses**: pilih orang dari daftar HaloDek (ketik NIK atau nama), pilih peran dan unit. Password sementara ditampilkan sekali. Orang yang belum ada di daftar ditambahkan dulu di HaloDek.
- **Ubah akses**: hanya peran, unit, dan status aktif.
- **Cabut akses**: orangnya kembali menjadi karyawan biasa; data diri, aset yang dipegang, dan riwayatnya tetap.
- **Impor akses** (CSV `nik, role, unit`) untuk memberi akses banyak orang sekaligus.

Orang yang nonaktif atau dihapus di HaloDek ikut dinonaktifkan di sini (kecuali Superadmin). Bila `HALODEK_URL` belum diisi, Inventaris berjalan mandiri dan akun boleh dibuat manual.

## Instalasi di Windows (Laragon, sebagai situs kedua)

1. Salin folder proyek ke `C:/laragon/www/inventaris`, lalu buka Terminal Laragon:
   ```bash
   cd C:/laragon/www/inventaris
   composer install
   cp .env.example .env
   php artisan key:generate
   ```
2. Buat database kosong `inventaris` (HeidiSQL / phpMyAdmin).
3. Buat token rahasia yang sama untuk kedua aplikasi:
   ```bash
   php -r "echo bin2hex(random_bytes(24)), PHP_EOL;"
   ```
   - `.env` Inventaris: `INVENTARIS_API_TOKEN=<token>`, `HALODEK_URL=http://IP-SERVER:8081`
   - `.env` HaloDek: `INVENTARIS_API_TOKEN=<token yang sama>`, `INVENTARIS_URL=http://IP-SERVER:8082`
4. Buat tabel dan akun awal:
   ```bash
   php artisan migrate --seed
   ```
5. Jalankan di port **8082**, terpisah dari HaloDek di 8081. Bisa lewat `php artisan serve --host=0.0.0.0 --port=8082`, atau di Apache Laragon: tambahkan `Listen 8082` dan sebuah VirtualHost `*:8082` dengan `DocumentRoot` ke folder `public`.

**Login awal (mode lokal):** NIK `00001`, password `00001`. Segera ganti password. Data contoh (`DemoSeeder`) juga membuat akun manajer supervisi NIK `00011` (password = NIK).

Ingin data contoh untuk mencoba? Jalankan `php artisan db:seed --class=DemoSeeder` (hanya mode lokal).

> Kedua aplikasi boleh berada di host yang sama karena cookie sesinya sudah dibedakan (`SESSION_COOKIE=inventaris_session`).

## Memindahkan data inventaris dari HaloDek lama

Untuk HaloDek versi lama yang masih punya menu Inventaris:

1. Perbarui HaloDek, lalu jalankan `php artisan migrate`. Tabel inventaris lamanya **tidak dihapus**.
2. Di Inventaris, isi koneksi database HaloDek di `.env` (bawaan: database `halodek` dengan user/password yang sama dengan `DB_*`):
   ```
   HALODEK_DB_DATABASE=halodek
   ```
3. Coba tanpa menyimpan, lalu jalankan sungguhan:
   ```bash
   php artisan inventaris:impor-halodek --uji
   php artisan inventaris:impor-halodek
   ```
   Yang dipindah: lokasi, kategori, barang (ID dipertahankan agar laporan lama di HaloDek tetap tersambung), dan pemegang barang. Admin regu ikut pindah dengan hash password yang sama. Tambahkan `--semua-pengguna` untuk menyalin semua pengguna HaloDek. Perintah ini aman diulang: data yang sudah ada dilewati, dan data HaloDek tidak diubah.
4. Setelah itu, atur Kepala Regu dan Staf tiap unit di menu **Pengguna**.

## API untuk HaloDek

Semua rute memakai header `Authorization: Bearer <INVENTARIS_API_TOKEN>`. Server membalas **503** bila token belum diatur (kurang dari 16 karakter) dan **401** bila token salah. Batasnya 240 permintaan per menit.

| Metode | Rute | Kegunaan |
|---|---|---|
| GET | `/api/v1/ping` | Cek koneksi |
| GET | `/api/v1/units` | Daftar unit |
| GET | `/api/v1/locations` | Daftar lokasi |
| GET | `/api/v1/assets?unit=it&holder_nik=..&location_id=..&include_id=..` | Aset aktif yang boleh dilaporkan |
| GET | `/api/v1/assets/{id}` | Detail satu aset, termasuk `check_url` (halaman cek aset publik) |
| POST | `/api/v1/report-events` | HaloDek mengabarkan laporan kendala (idempoten per `report_id`) |

## Bila muncul error 500 atau halaman lambat

1. Lihat penyebabnya di log (Terminal Laragon):
   ```bash
   grep "\.ERROR" storage/logs/laravel.log | tail -n 3 | cut -c1-400
   ```
2. `SQLSTATE[HY000] [2002] ... actively refused` berarti MySQL mati. Klik **Start All** di Laragon (atau start MySQL saja).
3. Foto gagal diunggah: batas PHP Laragon bawaan 2 MB per berkas dan 8 MB per kiriman. Form sudah mengecilkan foto otomatis; bila perlu, naikkan `upload_max_filesize` dan `post_max_size` di *Menu Laragon › PHP › php.ini*, lalu restart Apache.
4. Setelah mengubah `.env`, jalankan `php artisan optimize:clear`.

## Catatan keamanan

- Jangan commit `.env`. Ganti token bila pernah terlihat orang lain.
- Untuk server sungguhan: `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, web root ke folder `public`, dan jangan jalankan `DemoSeeder`.

## Pengujian

```bash
php vendor/bin/phpunit
```

## Data contoh (uji coba)

Kosongkan semua data lalu isi data contoh yang lengkap (pengguna & struktur organisasi, aset, stok, laporan dengan berbagai status, riwayat 2 bulan). Akun contoh memakai **password = NIK** (karyawan HaloDek cukup NIK), jadi **jangan dipakai sebagai data sungguhan**.

Urutannya: Inventaris dulu, lalu HaloDek (agar laporan contoh menunjuk aset yang benar dan riwayatnya terkirim ke Inventaris). Kedua aplikasi harus menyala.

```bash
# di folder Inventaris
php artisan inventaris:data-contoh
# di folder HaloDek
php artisan halodek:data-contoh
```

Perintah meminta Anda mengetik `HAPUS` sebagai konfirmasi. WhatsApp dibiarkan Mati dan akun contoh tanpa No. WhatsApp.
