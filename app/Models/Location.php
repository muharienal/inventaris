<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    protected $fillable = ['name', 'building', 'floor', 'pic_name', 'note'];

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function consumables(): HasMany
    {
        return $this->hasMany(Consumable::class);
    }

    /** "Ruang Rapat (Gedung A, Lt. 2)" */
    public function fullName(): string
    {
        $extra = collect([$this->building, $this->floor ? 'Lt. '.preg_replace('/^(lt\.?|lantai)\s*/i', '', $this->floor) : null])->filter()->implode(', ');

        return $this->name.($extra ? " ({$extra})" : '');
    }
}
