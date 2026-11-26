<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Permintaan barang habis pakai oleh karyawan; stok berkurang saat disetujui. */
class ConsumableRequest extends Model
{
    protected $fillable = ['consumable_id', 'user_id', 'requester_name', 'qty', 'note', 'status', 'decided_by', 'decided_at', 'decision_note'];

    protected $casts = ['decided_at' => 'datetime', 'qty' => 'integer'];

    protected $attributes = ['status' => 'menunggu'];

    public function consumable(): BelongsTo
    {
        return $this->belongsTo(Consumable::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function statusLabel(): string
    {
        return config("inventaris.request_statuses.{$this->status}.label", $this->status);
    }

    public function statusTone(): string
    {
        return config("inventaris.request_statuses.{$this->status}.tone", 'gray');
    }
}
