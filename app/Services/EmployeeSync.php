<?php

namespace App\Services;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Data karyawan (pemegang aset) diambil dari HaloDek: GET {HALODEK_URL}/api/v1/employees.
 *
 * - Karyawan baru dibuat sebagai "Karyawan" (tanpa akun, tidak bisa masuk).
 * - Nama/departemen/jabatan/No. HP/status karyawan yang sudah ada diperbarui mengikuti HaloDek.
 * - Karyawan yang tidak ada lagi di HaloDek dinonaktifkan (tidak dihapus: riwayat asetnya tetap).
 * - Akun Superadmin/Manajer/Kepala Regu/Staf dengan NIK yang sama TIDAK diubah.
 *
 * Berjalan otomatis tiap 5 menit selama aplikasi dipakai (RunBackgroundTasks), lewat tombol di menu Pengguna,
 * lewat `php artisan inventaris:sinkron-karyawan`, dan saat NIK pemegang belum dikenal.
 */
class EmployeeSync
{
    public const LAST_KEY = 'employees:last-sync';

    public function enabled(): bool
    {
        return $this->baseUrl() !== '' && strlen((string) config('inventaris.api_token')) >= 16;
    }

    /** @return array{ok:bool, message:string, created?:int, updated?:int, deactivated?:int, total?:int} */
    public function run(?User $actor = null): array
    {
        if (! $this->enabled()) {
            return $this->remember(['ok' => false, 'message' => 'Belum tersambung ke HaloDek. Isi HALODEK_URL dan INVENTARIS_API_TOKEN di .env.']);
        }

        try {
            $response = Http::baseUrl($this->baseUrl().'/api/v1/')
                ->withToken((string) config('inventaris.api_token'))
                ->acceptJson()->connectTimeout(3)->timeout(15)
                ->get('employees');
        } catch (Throwable $e) {
            Log::warning('HaloDek tidak dapat dihubungi (sinkron karyawan)', ['error' => $e->getMessage()]);

            return $this->remember(['ok' => false, 'message' => 'HaloDek tidak dapat dihubungi. Pastikan HaloDek menyala dan HALODEK_URL benar.']);
        }

        // Pastikan yang menjawab memang HaloDek (bukan aplikasi lain di alamat yang salah).
        if ($response->successful() && $response->json('app') !== 'HaloDek') {
            return $this->remember(['ok' => false, 'message' => 'Alamat '.$this->baseUrl().' bukan HaloDek versi terbaru. Periksa HALODEK_URL dan perbarui HaloDek.']);
        }

        if (! $response->successful() || ! is_array($response->json('data'))) {
            $msg = match ($response->status()) {
                401 => 'Token API ditolak HaloDek. Samakan INVENTARIS_API_TOKEN di .env kedua aplikasi.',
                503 => 'API HaloDek belum aktif. Isi INVENTARIS_API_TOKEN di .env HaloDek.',
                default => 'HaloDek membalas galat ('.$response->status().').',
            };
            Log::warning('Sinkron karyawan gagal', ['status' => $response->status()]);

            return $this->remember(['ok' => false, 'message' => $msg]);
        }

        $rows = collect($response->json('data'))
            ->filter(fn ($r) => is_array($r) && is_scalar($r['nik'] ?? null) && is_scalar($r['name'] ?? null)
                && trim((string) $r['nik']) !== '' && trim((string) $r['name']) !== '')
            // Kunci huruf kecil + awalan "n:" agar NIK angka tidak berubah jadi kunci integer PHP
            // dan perbandingannya sama dengan collation MySQL (tidak peka huruf besar/kecil).
            ->keyBy(fn ($r) => 'n:'.mb_strtolower(mb_substr(trim((string) $r['nik']), 0, 50)));
        $niks = $rows->map(fn ($r) => mb_substr(trim((string) $r['nik']), 0, 50))->values()->all();

        $created = $updated = $deactivated = 0;

        DB::transaction(function () use ($rows, $niks, &$created, &$updated, &$deactivated) {
            $local = User::whereIn('nik', $niks)->get()->keyBy(fn ($u) => 'n:'.mb_strtolower((string) $u->nik));
            $cut = fn ($v, $len) => ($v = trim((string) $v)) === '' ? null : mb_substr($v, 0, $len);

            foreach ($rows as $key => $r) {
                $nik = mb_substr(trim((string) $r['nik']), 0, 50);
                $fields = [
                    'name' => mb_substr(trim((string) $r['name']), 0, 255),
                    'division' => $cut($r['division'] ?? null, 100),
                    'department' => $cut($r['department'] ?? null, 100),
                    'position' => $cut($r['position'] ?? null, 100),
                    'phone' => $cut($r['phone'] ?? null, 30),
                    'is_active' => (bool) ($r['active'] ?? true),
                ];
                $user = $local->get($key);

                if (! $user) {
                    User::create($fields + [
                        'nik' => $nik, 'role' => User::ROLE_KARYAWAN,
                        'password' => User::unusablePassword(), 'must_change_password' => false,
                    ]);
                    $created++;
                } else {
                    // Data diri selalu mengikuti HaloDek (data orang terpusat di sana), termasuk pemilik akun.
                    // Peran, unit, dan password akun Inventaris tidak disentuh. Akun hanya ikut DInonaktifkan
                    // (orangnya keluar/nonaktif di HaloDek), tidak otomatis diaktifkan, dan Superadmin tidak pernah.
                    if (! $user->isKaryawan()) {
                        $fields['is_active'] = $user->is_active && ($fields['is_active'] || $user->isSuperadmin());
                    }
                    $user->fill($fields);
                    if ($user->isDirty()) {
                        $user->save();
                        $updated++;
                    }
                }
            }

            // Karyawan yang sudah tidak ada di HaloDek: nonaktifkan. Dilewati bila HaloDek mengirim daftar kosong
            // (lebih mungkin salah konfigurasi daripada semua karyawan keluar).
            if ($rows->isNotEmpty()) {
                $deactivated = User::where('role', User::ROLE_KARYAWAN)->where('is_active', true)
                    ->whereNotIn('nik', $niks)->update(['is_active' => false]);
            }
        });

        $result = [
            'ok' => true, 'total' => $rows->count(), 'created' => $created, 'updated' => $updated, 'deactivated' => $deactivated,
            'message' => "Data pegawai diambil dari HaloDek: {$rows->count()} orang ({$created} baru, {$updated} diperbarui, {$deactivated} dinonaktifkan).",
        ];
        if ($created || $updated || $deactivated) {
            Audit::log('user.sync', $result['message']);
        }

        return $this->remember($result);
    }

    /** Hasil sinkron terakhir (untuk ditampilkan di menu Pengguna). */
    public function last(): ?array
    {
        return Cache::get(self::LAST_KEY);
    }

    /** Selang sinkron otomatis (menit). */
    public const AUTO_MINUTES = 5;

    /** Dipanggil otomatis setelah respons terkirim: paling sering sekali per 5 menit. */
    public static function tick(): void
    {
        $sync = app(self::class);
        if ($sync->enabled() && Cache::add('employees:auto-sync', 1, now()->addMinutes(self::AUTO_MINUTES))) {
            $sync->run();
        }
    }

    /** Untuk NIK pemegang yang belum dikenal: ambil ulang dari HaloDek (paling sering sekali per menit). */
    public function refreshForUnknownNik(): bool
    {
        return $this->enabled() && Cache::add('employees:lookup-sync', 1, now()->addMinute()) && $this->run()['ok'];
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('inventaris.halodek_url'), '/');
    }

    private function remember(array $result): array
    {
        Cache::put(self::LAST_KEY, $result + ['at' => now()->toIso8601String()], now()->addDays(30));

        return $result;
    }
}
