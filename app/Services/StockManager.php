<?php

namespace App\Services;

use App\Models\Consumable;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Mutasi stok barang habis pakai; dikunci per barang agar stok tidak minus saat bersamaan. */
class StockManager
{
    /**
     * @param  string  $type  masuk | keluar | penyesuaian (qty = stok sebenarnya hasil hitung fisik)
     */
    public function adjust(Consumable $item, User $actor, string $type, int $qty, ?string $note = null, ?string $recipient = null): StockMovement
    {
        return DB::transaction(function () use ($item, $actor, $type, $qty, $note, $recipient) {
            $item = Consumable::lockForUpdate()->findOrFail($item->id);

            $delta = match ($type) {
                'masuk' => $qty,
                'keluar' => -$qty,
                'penyesuaian' => $qty - $item->stock,
                default => throw ValidationException::withMessages(['type' => 'Jenis mutasi stok tidak dikenal.']),
            };
            if ($type !== 'penyesuaian' && $qty <= 0) {
                throw ValidationException::withMessages(['qty' => 'Jumlah harus lebih dari 0.']);
            }
            if ($item->stock + $delta < 0) {
                throw ValidationException::withMessages(['qty' => "Stok {$item->name} tidak cukup (tersisa {$item->stock} {$item->unit_label})."]);
            }

            $item->update(['stock' => $item->stock + $delta]);
            $movement = $item->movements()->create([
                'type' => $type, 'qty' => $delta, 'stock_after' => $item->stock,
                // Kolom note varchar(500): penerima (120) + catatan (500) bisa melebihinya -> dipotong.
                'note' => ($n = $type === 'keluar' && filled($recipient) ? trim('Diterima '.trim($recipient).'. '.($note ?? '')) : $note) === null ? null : mb_substr($n, 0, 500),
                'user_id' => $actor->id,
            ]);

            Audit::log('stock.'.$type, (['masuk' => 'Stok masuk', 'keluar' => 'Stok keluar'][$type] ?? 'Penyesuaian stok')." {$item->code} ({$item->name}): ".($delta >= 0 ? '+' : '')."{$delta} {$item->unit_label}, sisa {$item->stock} {$item->unit_label}.", $item);

            return $movement;
        });
    }
}
