# Inventaris Umum & IT

## Instalasi di Windows

1.    ```bash
   cd C:/laragon/www/inventaris
   composer install
   cp .env.example .env
   php artisan key:generate
   ```
2. Buat database kosong `inventaris`.
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
