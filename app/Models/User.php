<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasFactory;

    public const ROLE_SUPERADMIN = 'superadmin';
    public const ROLE_MANAJER = 'manajer';
    public const ROLE_KEPALA_REGU = 'kepala_regu';
    public const ROLE_STAF = 'staf';
    public const ROLE_KARYAWAN = 'karyawan';

    /**
     * Peran yang bisa masuk ke aplikasi (semua memakai NIK + password), sama dengan HaloDek:
     *  - Superadmin  : semua unit + pengguna.
     *  - Manajer     : supervisi semua unit, hanya melihat (tidak mengubah data).
     *  - Kepala Regu : mengelola penuh unitnya, termasuk kategori, lokasi, hapus, dan impor.
     *  - Staf        : pekerjaan harian unitnya (tambah/ubah aset, mutasi, servis, stok).
     * Karyawan = data pemegang aset yang diambil dari HaloDek (tanpa akun, tidak bisa masuk).
     */
    public const ADMIN_ROLES = [self::ROLE_SUPERADMIN, self::ROLE_MANAJER, self::ROLE_KEPALA_REGU, self::ROLE_STAF];

    /** Peran yang boleh mengubah data inventaris. */
    public const MANAGE_ROLES = [self::ROLE_SUPERADMIN, self::ROLE_KEPALA_REGU, self::ROLE_STAF];

    /** Peran yang boleh mengelola data induk, menghapus, dan mengimpor. */
    public const LEAD_ROLES = [self::ROLE_SUPERADMIN, self::ROLE_KEPALA_REGU];

    /** Peran yang wajib terikat ke satu unit. */
    public const UNIT_ROLES = [self::ROLE_KEPALA_REGU, self::ROLE_STAF];

    protected $fillable = [
        'nik', 'name', 'phone', 'division', 'department', 'position',
        'role', 'unit_id', 'password', 'must_change_password', 'is_active', 'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'password' => 'hashed',
        'must_change_password' => 'boolean',
        'is_active' => 'boolean',
        'last_login_at' => 'datetime',
    ];

    private ?array $unitIdsCache = null;

    // ---------------------------------------------------------------- relasi

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** Aset yang sedang dipegang / dipinjam. */
    public function heldAssets(): HasMany
    {
        return $this->hasMany(Asset::class, 'holder_id');
    }

    public function stockRequests(): HasMany
    {
        return $this->hasMany(ConsumableRequest::class);
    }

    // ------------------------------------------------------------- password

    /** Password sementara acak (12 karakter, huruf + angka, tanpa karakter yang mirip). */
    public static function temporaryPassword(int $length = 12): string
    {
        $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz';
        $digits = '23456789';
        $all = $letters.$digits;

        $chars = [$letters[random_int(0, strlen($letters) - 1)], $digits[random_int(0, strlen($digits) - 1)]];
        while (count($chars) < $length) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        shuffle($chars);

        return implode('', $chars);
    }

    /** Password acak yang tidak diketahui siapa pun (karyawan tidak punya akun). */
    public static function unusablePassword(): string
    {
        return bin2hex(random_bytes(24));
    }

    // ----------------------------------------------------------------- peran

    public function isSuperadmin(): bool
    {
        return $this->role === self::ROLE_SUPERADMIN;
    }

    /** Kepala Regu atau Staf (terikat ke satu unit). */
    public function isUnitMember(): bool
    {
        return in_array($this->role, self::UNIT_ROLES, true);
    }

    /** Boleh mengelola kategori, lokasi, menghapus data, dan impor (Superadmin & Kepala Regu). */
    public function canLead(): bool
    {
        return in_array($this->role, self::LEAD_ROLES, true);
    }

    /** Pengguna yang punya akses aplikasi (Superadmin, Manajer, Kepala Regu, Staf). */
    public function isAdmin(): bool
    {
        return in_array($this->role, self::ADMIN_ROLES, true);
    }

    public function isManajer(): bool
    {
        return $this->role === self::ROLE_MANAJER;
    }

    /** Boleh menambah/mengubah/menghapus data inventaris (bukan manajer supervisi). */
    public function canManage(): bool
    {
        return in_array($this->role, self::MANAGE_ROLES, true);
    }

    public function isKaryawan(): bool
    {
        return $this->role === self::ROLE_KARYAWAN;
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function roleLabel(): string
    {
        $label = config("inventaris.roles.{$this->role}", ucfirst((string) $this->role));

        return $this->isUnitMember() && $this->unit ? $label.' '.$this->unit->name : $label;
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $first = mb_substr($parts[0] ?? '?', 0, 1);
        $second = isset($parts[1]) ? mb_substr($parts[1], 0, 1) : '';

        return mb_strtoupper($first.$second);
    }

    // ------------------------------------------------------------------ unit

    /** Unit yang boleh dikelola: Superadmin = semua, Pengelola = unitnya, Karyawan = tidak ada. */
    public function unitIds(): array
    {
        if ($this->unitIdsCache !== null) {
            return $this->unitIdsCache;
        }
        if ($this->isSuperadmin() || $this->isManajer()) {
            return $this->unitIdsCache = Unit::pluck('id')->all();
        }

        return $this->unitIdsCache = ($this->isUnitMember() && $this->unit_id) ? [(int) $this->unit_id] : [];
    }

    public function managesUnit(int|Unit|null $unit): bool
    {
        if ($unit === null) {
            return false;
        }

        return in_array($unit instanceof Unit ? $unit->id : $unit, $this->unitIds(), true);
    }

    // --------------------------------------------------------------- scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
