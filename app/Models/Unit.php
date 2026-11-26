<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Unit pengelola inventaris. Kode sama dengan slug regu di HaloDek. */
class Unit extends Model
{
    public const COLORS = [
        'blue' => '#1D5FD6', 'amber' => '#B45309', 'violet' => '#7C3AED', 'rose' => '#E11D48',
        'teal' => '#0E7C66', 'green' => '#15803D', 'orange' => '#C2410C', 'gray' => '#475569',
    ];

    protected $fillable = ['code', 'name', 'group', 'description', 'color', 'icon', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    protected $attributes = ['is_active' => true, 'group' => 'umum', 'color' => 'blue', 'icon' => 'box', 'sort_order' => 0];

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class)->orderBy('sort_order')->orderBy('name');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function consumables(): HasMany
    {
        return $this->hasMany(Consumable::class);
    }

    public function hex(): string
    {
        return self::COLORS[$this->color] ?? self::COLORS['blue'];
    }

    public function groupLabel(): string
    {
        return $this->group === 'it' ? 'IT' : 'Umum';
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('sort_order')->orderBy('name');
    }
}
