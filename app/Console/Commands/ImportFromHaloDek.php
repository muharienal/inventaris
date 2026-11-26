<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Memindahkan data inventaris lama dari database HaloDek ke aplikasi Inventaris.
 * ID lokasi & barang DIPERTAHANKAN, sehingga laporan lama di HaloDek tetap terhubung ke barangnya.
 * Aman dijalankan ulang: data yang sudah ada (berdasarkan ID / NIK) dilewati.
 *
 *   php artisan inventaris:impor-halodek            (pindahkan lokasi, kategori, barang, + akun pemegang barang)
 *   php artisan inventaris:impor-halodek --semua-pengguna   (+ semua akun HaloDek)
 *   php artisan inventaris:impor-halodek --uji      (hanya hitung, tidak menyimpan)
 */
class ImportFromHaloDek extends Command
{
    protected $signature = 'inventaris:impor-halodek {--semua-pengguna : Salin semua akun HaloDek} {--uji : Hitung saja tanpa menyimpan}';

    protected $description = 'Pindahkan lokasi, kategori, dan barang inventaris dari database HaloDek';

    private const STATUS = ['tersedia' => 'tersedia', 'digunakan' => 'digunakan', 'perbaikan' => 'perbaikan', 'rusak' => 'rusak', 'non-aktif' => 'nonaktif'];

    public function handle(): int
    {
        try {
            $src = DB::connection('halodek');
            $src->getPdo();
        } catch (Throwable $e) {
            $this->error('Tidak bisa membuka database HaloDek: '.$e->getMessage());
            $this->line('Atur HALODEK_DB_DATABASE (dan HALODEK_DB_USERNAME/PASSWORD bila berbeda) di .env Inventaris.');

            return self::FAILURE;
        }
        foreach (['locations', 'teams', 'inventory_categories', 'inventory_items', 'users'] as $t) {
            if (! $src->getSchemaBuilder()->hasTable($t)) {
                $this->error("Tabel {$t} tidak ditemukan di database HaloDek. Data lama mungkin sudah dihapus.");

                return self::FAILURE;
            }
        }

        $dry = (bool) $this->option('uji');
        $stats = ['lokasi' => 0, 'kategori' => 0, 'barang' => 0, 'pengguna' => 0, 'dilewati' => 0];

        $run = function () use ($src, $dry, &$stats) {
            // ---- lokasi (ID dipertahankan)
            foreach ($src->table('locations')->orderBy('id')->get() as $l) {
                if (DB::table('locations')->where('id', $l->id)->orWhere('name', $l->name)->exists()) {
                    $stats['dilewati']++;

                    continue;
                }
                DB::table('locations')->insert([
                    'id' => $l->id, 'name' => $l->name, 'building' => $l->building ?? null, 'floor' => $l->floor ?? null,
                    'pic_name' => $l->pic_name ?? null, 'note' => $l->note ?? null, 'created_at' => $l->created_at, 'updated_at' => $l->updated_at,
                ]);
                $stats['lokasi']++;
            }

            // ---- regu -> unit (berdasarkan slug/kode)
            $units = [];
            foreach ($src->table('teams')->get() as $t) {
                $units[$t->id] = Unit::firstOrCreate(['code' => $t->slug], ['name' => $t->name, 'group' => $t->slug === 'it' ? 'it' : 'umum', 'icon' => $t->icon ?? 'box', 'sort_order' => $t->sort_order ?? 0]);
            }

            // ---- pengguna (pemegang barang, atau semua bila --semua-pengguna)
            $holderIds = $src->table('inventory_items')->whereNotNull('holder_id')->distinct()->pluck('holder_id')->all();
            $userQuery = $src->table('users')->orderBy('id');
            if (! $this->option('semua-pengguna')) {
                $userQuery->whereIn('id', $holderIds ?: [0]);
            }
            $userMap = [];
            foreach ($userQuery->get() as $u) {
                $existing = User::where('nik', $u->nik)->first();
                if (! $existing) {
                    $role = match ($u->role) {
                        'superadmin' => User::ROLE_SUPERADMIN,
                        'manajer' => User::ROLE_MANAJER,
                        'kepala_regu' => User::ROLE_KEPALA_REGU,
                        'staf' => User::ROLE_STAF,
                        default => User::ROLE_KARYAWAN,
                    };
                    $unitId = in_array($role, User::UNIT_ROLES, true) ? ($units[$u->team_id]->id ?? null) : null;
                    if (in_array($role, User::UNIT_ROLES, true) && ! $unitId) {
                        $role = User::ROLE_KARYAWAN;
                    }
                    // Hash password disalin apa adanya: akun bisa masuk dengan password HaloDek-nya.
                    $id = DB::table('users')->insertGetId([
                        'nik' => $u->nik, 'name' => $u->name,
                        'phone' => $u->phone, 'department' => $u->department, 'position' => $u->position, 'role' => $role, 'unit_id' => $unitId,
                        'password' => $role === User::ROLE_KARYAWAN ? bcrypt(User::unusablePassword()) : $u->password,
                        'must_change_password' => $role === User::ROLE_KARYAWAN ? false : (bool) $u->must_change_password,
                        'is_active' => (bool) $u->is_active, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $userMap[$u->id] = $id;
                    $stats['pengguna']++;
                } else {
                    $userMap[$u->id] = $existing->id;
                }
            }

            // ---- kategori
            $catMap = [];
            foreach ($src->table('inventory_categories')->get() as $c) {
                $unit = $units[$c->team_id] ?? null;
                if (! $unit) {
                    continue;
                }
                $cat = Category::firstOrCreate(['unit_id' => $unit->id, 'type' => 'aset', 'name' => $c->name], ['sort_order' => $c->sort_order ?? 0]);
                $stats['kategori'] += $cat->wasRecentlyCreated ? 1 : 0;
                $catMap[$c->id] = $cat->id;
            }

            // ---- barang (ID dipertahankan)
            foreach ($src->table('inventory_items')->orderBy('id')->get() as $i) {
                $unit = $units[$i->team_id] ?? null;
                if (! $unit || ! isset($catMap[$i->inventory_category_id]) || DB::table('assets')->where('id', $i->id)->exists()
                    || DB::table('assets')->where('unit_id', $unit->id)->where('code', $i->code)->exists()) {
                    $stats['dilewati']++;

                    continue;
                }
                $holder = $i->holder_id ? ($userMap[$i->holder_id] ?? null) : null;
                $locationId = $i->location_id && DB::table('locations')->where('id', $i->location_id)->exists() ? $i->location_id : null;
                DB::table('assets')->insert([
                    'id' => $i->id, 'unit_id' => $unit->id, 'category_id' => $catMap[$i->inventory_category_id],
                    'code' => $i->code, 'name' => $i->name, 'status' => ($st = self::STATUS[$i->status] ?? 'tersedia') === 'tersedia' && $holder ? 'digunakan' : $st, 'condition' => $i->status === 'rusak' ? 'rusak_berat' : 'baik',
                    'holder_id' => $holder, 'location_id' => $locationId, 'purchase_date' => $i->acquired_at ?? null,
                    'due_date' => $i->due_date ?? null, 'notes' => $i->note ?? null, 'created_at' => $i->created_at, 'updated_at' => $i->updated_at,
                ]);
                DB::table('asset_movements')->insert([
                    'asset_id' => $i->id, 'type' => $holder ? 'serah_terima' : 'pindah', 'to_holder_id' => $holder,
                    'to_holder_name' => $holder ? User::find($holder)?->name : null, 'to_location_id' => $locationId,
                    'note' => 'Dipindahkan dari HaloDek.', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $stats['barang']++;
            }

            if ($dry) {
                throw new \RuntimeException('__uji__');
            }
        };

        try {
            DB::transaction($run);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== '__uji__') {
                throw $e;
            }
        }

        $this->table(['Data', 'Jumlah'], collect($stats)->map(fn ($v, $k) => [ucfirst($k), $v])->values()->all());
        $this->info($dry ? 'Mode uji: tidak ada yang disimpan.' : 'Selesai. Data lama di HaloDek tidak diubah.');

        return self::SUCCESS;
    }
}
