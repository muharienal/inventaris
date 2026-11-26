<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Salinan ringkas laporan kendala dari HaloDek untuk sebuah aset. */
class AssetReport extends Model
{
    protected $fillable = [
        'asset_id', 'report_id', 'report_number', 'title', 'team', 'category', 'description', 'status', 'status_label',
        'reporter_name', 'handler_name', 'resolution', 'url', 'reported_at', 'resolved_at',
    ];

    protected $casts = ['reported_at' => 'datetime', 'resolved_at' => 'datetime'];

    /** Catatan perbaikan yang dibuat otomatis dari laporan ini (ada setelah laporan mulai ditangani). */
    public function service(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(AssetService::class);
    }

    /**
     * Jadikan laporan HaloDek sebagai riwayat perbaikan aset:
     *  - diproses  -> Perbaikan "Sedang ditangani"
     *  - selesai   -> Perbaikan "Selesai" (hasil = catatan penyelesaian dari HaloDek)
     *  - ditolak / dibatalkan setelah sempat ditangani -> "Batal"
     * Laporan yang belum pernah ditangani (menunggu) tidak membuat catatan servis.
     */
    public function syncService(): ?AssetService
    {
        $service = $this->service()->first();
        if (! $service && ! in_array($this->status, ['diproses', 'selesai'], true)) {
            return null;
        }

        $status = match ($this->status) {
            'selesai' => 'selesai',
            'ditolak', 'dibatalkan' => 'batal',
            default => 'dijadwalkan',
        };
        $attrs = [
            'asset_id' => $this->asset_id,
            'type' => 'perbaikan',
            'status' => $status,
            'scheduled_at' => ($this->reported_at ?? $this->created_at ?? now())->toDateString(),
            'completed_at' => $status === 'selesai' ? ($this->resolved_at ?? now())->toDateString() : null,
            'vendor' => $this->team ? mb_substr('Regu '.$this->team, 0, 120) : 'HaloDek',
            'handler_name' => $this->handler_name,
            'description' => trim($this->title.($this->description ? "\n".$this->description : '')),
            'result' => $status === 'dijadwalkan' ? null : $this->resolution,
            'report_number' => $this->report_number,
        ];

        if ($service) {
            $service->update($attrs);

            return $service;
        }

        return $this->service()->create($attrs);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function tone(): string
    {
        return ['menunggu' => 'amber', 'diproses' => 'blue', 'selesai' => 'green', 'ditolak' => 'red', 'dibatalkan' => 'gray'][$this->status] ?? 'gray';
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['menunggu', 'diproses'], true);
    }
}
