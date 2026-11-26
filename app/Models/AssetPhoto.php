<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class AssetPhoto extends Model
{
    protected $fillable = ['asset_id', 'path', 'original_name', 'size'];

    protected static function booted(): void
    {
        // Berkas foto ikut terhapus bersama datanya (juga saat aset dihapus, lihat Asset hapus).
        static::deleting(fn (self $p) => Storage::disk('local')->delete($p->path));
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
