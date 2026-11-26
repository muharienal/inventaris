<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    protected $fillable = ['consumable_id', 'type', 'qty', 'stock_after', 'note', 'user_id'];

    public function consumable(): BelongsTo
    {
        return $this->belongsTo(Consumable::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function typeLabel(): string
    {
        return ['masuk' => 'Masuk', 'keluar' => 'Keluar', 'penyesuaian' => 'Penyesuaian'][$this->type] ?? $this->type;
    }
}
