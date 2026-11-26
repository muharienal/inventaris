<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetService extends Model
{
    protected $fillable = [
        'asset_id', 'type', 'status', 'scheduled_at', 'completed_at', 'vendor', 'cost',
        'description', 'result', 'report_number', 'user_id', 'asset_report_id', 'handler_name',
    ];

    protected $attributes = ['status' => 'dijadwalkan', 'type' => 'servis_rutin'];

    protected $casts = ['scheduled_at' => 'date', 'completed_at' => 'date', 'cost' => 'decimal:2'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assetReport(): BelongsTo
    {
        return $this->belongsTo(AssetReport::class);
    }

    /** Dibuat & diperbarui otomatis dari laporan HaloDek (tidak diubah manual di Inventaris). */
    public function isFromHaloDek(): bool
    {
        return $this->asset_report_id !== null;
    }

    public function typeLabel(): string
    {
        return config("inventaris.service_types.{$this->type}", ucfirst($this->type));
    }

    public function statusLabel(): string
    {
        if ($this->status === 'dijadwalkan' && $this->isFromHaloDek()) {
            return 'Sedang ditangani';
        }

        return ['dijadwalkan' => 'Dijadwalkan', 'selesai' => 'Selesai', 'batal' => 'Batal'][$this->status] ?? $this->status;
    }

    public function statusTone(): string
    {
        if ($this->status === 'dijadwalkan' && $this->isFromHaloDek()) {
            return 'blue';
        }

        return ['dijadwalkan' => 'amber', 'selesai' => 'green', 'batal' => 'gray'][$this->status] ?? 'gray';
    }
}
