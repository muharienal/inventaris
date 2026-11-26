<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Divisi atau Bidang (struktur organisasi dari HaloDek). Bisa menjadi pemegang aset,
 * mis. printer milik "Bidang Umum & IT" atau proyektor milik "Divisi Keuangan".
 */
class OrgUnit extends Model
{
    public const DIVISI = 'divisi';
    public const BIDANG = 'bidang';

    protected $fillable = ['type', 'parent_id', 'halodek_id', 'name', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'halodek_id' => 'integer', 'parent_id' => 'integer'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'holder_org_id');
    }

    public function isDivision(): bool
    {
        return $this->type === self::DIVISI;
    }

    public function typeLabel(): string
    {
        return $this->isDivision() ? 'Divisi' : 'Bidang';
    }

    /** "Bidang Umum & IT" / "Divisi Keuangan". */
    public function label(): string
    {
        return $this->typeLabel().' '.$this->name;
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Pilihan untuk form: divisi & bidang aktif, plus karyawan aktif.
     *
     * @return array{divisions: \Illuminate\Support\Collection, departments: \Illuminate\Support\Collection, people: \Illuminate\Support\Collection}
     */
    public static function choices(): array
    {
        $all = self::active()->ordered()->get();

        return [
            'divisions' => $all->where('type', self::DIVISI)->values(),
            'departments' => $all->where('type', self::BIDANG)->values(),
            // Karyawan aktif untuk dropdown pemegang, dikelompokkan per bidang.
            'people' => User::where('is_active', true)->orderBy('department')->orderBy('name')->get(['id', 'nik', 'name', 'department']),
        ];
    }

    /** Bidang dan divisi milik seorang pegawai (dicocokkan dari nama yang disinkron dari HaloDek). */
    public static function idsForPerson(?string $department, ?string $division): array
    {
        $ids = [];
        foreach ([self::BIDANG => $department, self::DIVISI => $division] as $type => $name) {
            $name = trim((string) $name);
            if ($name !== '') {
                $ids = array_merge($ids, self::where('type', $type)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->pluck('id')->all());
            }
        }

        return $ids;
    }

    /**
     * Lengkapi struktur dari isian divisi/bidang pegawai (dipakai bila HaloDek belum punya API struktur,
     * atau Inventaris berjalan tanpa HaloDek). Hanya menambah, tidak menonaktifkan.
     */
    public static function syncFromUsers(): int
    {
        $created = 0;
        $find = fn (string $type, string $name) => self::where('type', $type)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
        $rows = User::query()->where(fn ($q) => $q->whereNotNull('division')->orWhereNotNull('department'))
            ->get(['division', 'department'])->unique(fn ($u) => mb_strtolower((string) $u->division).'|'.mb_strtolower((string) $u->department));

        foreach ($rows as $u) {
            $div = trim((string) $u->division);
            $divUnit = null;
            if ($div !== '') {
                $divUnit = $find(self::DIVISI, $div);
                if (! $divUnit) {
                    $divUnit = self::create(['type' => self::DIVISI, 'name' => mb_substr($div, 0, 100)]);
                    $created++;
                }
            }
            $dept = trim((string) $u->department);
            if ($dept === '') {
                continue;
            }
            $bidang = $find(self::BIDANG, $dept);
            if (! $bidang) {
                self::create(['type' => self::BIDANG, 'name' => mb_substr($dept, 0, 100), 'parent_id' => $divUnit?->id]);
                $created++;
            } elseif (! $bidang->parent_id && $divUnit && ! $bidang->halodek_id) {
                $bidang->update(['parent_id' => $divUnit->id]); // divisinya baru diketahui
            }
        }

        return $created;
    }
}
