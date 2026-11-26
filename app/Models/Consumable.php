<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Barang habis pakai. */
class Consumable extends Model
{
    protected $fillable = ['unit_id', 'category_id', 'code', 'name', 'unit_label', 'stock', 'min_stock', 'location_id', 'notes', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'stock' => 'integer', 'min_stock' => 'integer'];

    /** Sama dengan nilai bawaan kolom, agar model yang baru dibuat langsung benar. */
    protected $attributes = ['is_active' => true, 'stock' => 0, 'min_stock' => 0, 'unit_label' => 'pcs'];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->latest()->latest('id');
    }


    public function isLow(): bool
    {
        return $this->stock <= $this->min_stock;
    }

    public function stockTone(): string
    {
        return $this->stock <= 0 ? 'red' : ($this->isLow() ? 'amber' : 'green');
    }

    public function scopeLow(Builder $q): Builder
    {
        return $q->where('is_active', true)->whereColumn('stock', '<=', 'min_stock');
    }

    public function scopeManagedBy(Builder $q, User $user): Builder
    {
        return $q->whereIn('unit_id', $user->unitIds() ?: [0]);
    }
}
