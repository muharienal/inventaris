<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends Model
{
    protected $fillable = [
        'unit_id', 'category_id', 'code', 'name', 'brand', 'model', 'serial_number', 'specs',
        'status', 'condition', 'holder_id', 'holder_org_id', 'location_id', 'purchase_date', 'vendor',
        'due_date', 'next_service_at', 'service_interval_months', 'notes',
    ];

    protected $attributes = ['status' => 'tersedia', 'condition' => 'baik'];

    /** Token stiker QR tidak ikut keluar lewat API / toArray. */
    protected $hidden = ['verify_token'];

    protected static function booted(): void
    {
        // Token acak per aset, disimpan di database: tidak bisa ditebak dan tidak berubah walau APP_KEY diganti.
        static::creating(function (Asset $asset) {
            $asset->verify_token ??= bin2hex(random_bytes(10));
        });
    }

    protected $casts = [
        'purchase_date' => 'date',
        'due_date' => 'date',
        'next_service_at' => 'date',
    ];

    // ---------------------------------------------------------------- relasi

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_id');
    }

    /** Pemegang berupa bidang/divisi (holder_id kosong). */
    public function holderOrg(): BelongsTo
    {
        return $this->belongsTo(OrgUnit::class, 'holder_org_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(AssetPhoto::class)->orderBy('id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(AssetMovement::class)->latest()->latest('id');
    }

    public function services(): HasMany
    {
        return $this->hasMany(AssetService::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(AssetReport::class)->latest('reported_at');
    }

    /** Pinjaman yang masih berjalan (belum dikembalikan). */
    public function openLoan(): ?AssetMovement
    {
        return $this->movements()->where('type', 'pinjam')->whereNull('returned_at')->first();
    }

    // -------------------------------------------------------------- tampilan

    public function label(): string
    {
        return $this->code.' - '.$this->name;
    }

    /** Sedang dipegang seseorang, sebuah bidang, atau divisi. */
    public function hasHolder(): bool
    {
        return (bool) ($this->holder_id || $this->holder_org_id);
    }

    /** "Arienal Haq" / "Bidang Umum & IT" / null. */
    public function holderName(): ?string
    {
        if ($this->holder_id) {
            return $this->holder?->name;
        }

        return $this->holder_org_id ? $this->holderOrg?->label() : null;
    }

    /** Untuk tampilan detail: nama + NIK bila orang. */
    public function holderLabel(): ?string
    {
        if ($this->holder_id && $this->holder) {
            return $this->holder->name.' ('.$this->holder->nik.')';
        }

        return $this->holderName();
    }

    /** Pemegang sudah nonaktif (keluar dari HaloDek / bidang dihapus): aset perlu dialihkan. */
    public function holderInactive(): bool
    {
        return ($this->holder_id && $this->holder && ! $this->holder->is_active)
            || (! $this->holder_id && $this->holder_org_id && $this->holderOrg && ! $this->holderOrg->is_active);
    }

    /** "Lenovo AIO PC", tanpa mengulang merek bila model sudah memuatnya. */
    public function brandModel(): ?string
    {
        $brand = trim((string) $this->brand);
        $model = trim((string) $this->model);
        if ($brand !== '' && $model !== '' && str_starts_with(mb_strtolower($model), mb_strtolower($brand))) {
            return $model;
        }

        return trim($brand.' '.$model) ?: null;
    }

    public function statusLabel(): string
    {
        return config("inventaris.statuses.{$this->status}.label", ucfirst($this->status));
    }

    public function statusTone(): string
    {
        return config("inventaris.statuses.{$this->status}.tone", 'gray');
    }

    public function conditionLabel(): string
    {
        return config("inventaris.conditions.{$this->condition}.label", ucfirst((string) $this->condition));
    }

    public function conditionTone(): string
    {
        return config("inventaris.conditions.{$this->condition}.tone", 'gray');
    }

    public function isActive(): bool
    {
        return $this->status !== 'nonaktif';
    }

    // ------------------------------------------------------------ peringatan

    public function isServiceDue(?int $days = null): bool
    {
        return $this->next_service_at && $this->next_service_at->lte(now()->addDays($days ?? config('inventaris.due_soon_days')));
    }

    public function isDueSoon(?int $days = null): bool
    {
        return $this->due_date && $this->due_date->lte(now()->addDays($days ?? config('inventaris.due_soon_days')));
    }

    /** Token halaman cek aset (QR), tersimpan per aset. Aset lama tanpa token langsung dibuatkan sekali. */
    public function verifyToken(): string
    {
        if (! $this->verify_token && $this->exists) {
            $this->forceFill(['verify_token' => bin2hex(random_bytes(10))])->saveQuietly();
        }

        return (string) $this->verify_token;
    }

    /** Alamat yang dicetak di stiker QR: halaman cek aset publik (QR_URL, atau APP_URL bila kosong). */
    public function verifyUrl(): string
    {
        return self::qrBaseUrl().'/cek/'.$this->id.'/'.$this->verifyToken();
    }

    public static function qrBaseUrl(): string
    {
        return rtrim((string) (config('inventaris.qr_url') ?: config('app.url')), '/');
    }

    /** Tautan "Lapor kendala" ke HaloDek (form lapor terisi otomatis). */
    public function haloDekUrl(): ?string
    {
        $base = rtrim((string) config('inventaris.halodek_url'), '/');

        return $base ? $base.'/q/'.$this->id : null;
    }

    // --------------------------------------------------------------- scopes

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', '!=', 'nonaktif');
    }

    /** Garansi/lisensi berakhir dalam N hari (atau sudah lewat). */
    public function scopeDueSoon(Builder $q, ?int $days = null): Builder
    {
        return $q->active()->whereNotNull('due_date')->whereDate('due_date', '<=', now()->addDays($days ?? config('inventaris.due_soon_days')));
    }

    /** Servis jatuh tempo dalam N hari (atau sudah lewat). */
    public function scopeServiceDue(Builder $q, ?int $days = null): Builder
    {
        return $q->active()->whereNotNull('next_service_at')->whereDate('next_service_at', '<=', now()->addDays($days ?? config('inventaris.due_soon_days')));
    }

    /** Pemegangnya sudah nonaktif (orang, bidang, atau divisi). */
    public function scopeInactiveHolder(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->whereHas('holder', fn ($h) => $h->where('is_active', false))
            ->orWhere(fn ($x) => $x->whereNull('holder_id')->whereHas('holderOrg', fn ($o) => $o->where('is_active', false))));
    }

    public function scopeManagedBy(Builder $q, User $user): Builder
    {
        return $q->whereIn('unit_id', $user->unitIds() ?: [0]);
    }
}
