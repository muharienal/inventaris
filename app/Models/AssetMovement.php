<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetMovement extends Model
{
    protected $fillable = [
        'asset_id', 'type', 'from_holder_id', 'to_holder_id', 'from_holder_org_id', 'to_holder_org_id', 'from_holder_name', 'to_holder_name',
        'from_location_id', 'to_location_id', 'due_back_at', 'returned_at', 'note', 'user_id',
    ];

    protected $casts = ['due_back_at' => 'date', 'returned_at' => 'datetime'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toHolder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_holder_id');
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function typeLabel(): string
    {
        return config("inventaris.movements.{$this->type}", ucfirst($this->type));
    }

    public function isOverdue(): bool
    {
        return $this->type === 'pinjam' && ! $this->returned_at && $this->due_back_at && $this->due_back_at->lt(today());
    }
}
