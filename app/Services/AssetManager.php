<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\AssetMovement;
use App\Models\AssetService;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Semua perubahan pemegang/lokasi aset lewat sini agar riwayatnya selalu tercatat.
 *
 *  serah_terima : aset diserahkan ke pemegang (status Digunakan), lokasi boleh ikut berubah
 *  pindah       : hanya lokasi berubah
 *  pinjam       : dipinjamkan sampai tanggal tertentu (status Dipinjam)
 *  kembali      : pinjaman selesai, aset kembali ke pemegang sebelumnya (atau gudang)
 *  tarik        : pemegang dilepas, aset kembali Tersedia
 */
class AssetManager
{
    public function move(Asset $asset, User $actor, string $type, array $data): AssetMovement
    {
        return DB::transaction(function () use ($asset, $actor, $type, $data) {
            $asset = Asset::lockForUpdate()->findOrFail($asset->id);
            $from = ['holder' => $asset->holder, 'location_id' => $asset->location_id];
            $loan = $asset->openLoan();

            if ($type !== 'kembali' && $loan) {
                throw ValidationException::withMessages(['type' => 'Aset sedang dipinjam. Catat pengembaliannya dulu.']);
            }
            if (! $asset->isActive()) {
                throw ValidationException::withMessages(['type' => 'Aset nonaktif tidak bisa dimutasi.']);
            }

            $toHolder = ! empty($data['holder_id']) ? User::active()->find($data['holder_id']) : null;
            $explicitLocation = isset($data['location_id']) && $data['location_id'] !== '' ? (int) $data['location_id'] : null;
            $toLocation = $explicitLocation ?? $asset->location_id;

            switch ($type) {
                case 'serah_terima':
                    if (! $toHolder) {
                        throw ValidationException::withMessages(['holder_id' => 'Pilih penerima aset.']);
                    }
                    $asset->fill(['holder_id' => $toHolder->id, 'location_id' => $toLocation, 'status' => 'digunakan']);
                    break;
                case 'pindah':
                    if (! $toLocation || $toLocation === $asset->location_id) {
                        throw ValidationException::withMessages(['location_id' => 'Pilih lokasi tujuan yang berbeda.']);
                    }
                    $asset->location_id = $toLocation;
                    break;
                case 'pinjam':
                    if (! $toHolder) {
                        throw ValidationException::withMessages(['holder_id' => 'Pilih peminjam.']);
                    }
                    if (empty($data['due_back_at'])) {
                        throw ValidationException::withMessages(['due_back_at' => 'Isi tanggal batas pengembalian.']);
                    }
                    $asset->fill(['holder_id' => $toHolder->id, 'location_id' => $toLocation, 'status' => 'dipinjam']);
                    break;
                case 'kembali':
                    if (! $loan) {
                        throw ValidationException::withMessages(['type' => 'Aset ini tidak sedang dipinjam.']);
                    }
                    $loan->forceFill(['returned_at' => now()])->save();
                    // Kembali ke pemegang sebelum dipinjam (bila ada), lokasi bisa diisi ulang.
                    $toHolder = $loan->from_holder_id ? User::find($loan->from_holder_id) : null;
                    $asset->fill([
                        'holder_id' => $toHolder?->id,
                        'location_id' => $explicitLocation ?? $loan->from_location_id ?? $asset->location_id,
                        'status' => $toHolder ? 'digunakan' : 'tersedia',
                    ]);
                    break;
                case 'tarik':
                    $toHolder = null;
                    $asset->fill(['holder_id' => null, 'location_id' => $toLocation, 'status' => 'tersedia']);
                    break;
                default:
                    throw ValidationException::withMessages(['type' => 'Jenis mutasi tidak dikenal.']);
            }
            $asset->save();

            $movement = $asset->movements()->create([
                'type' => $type,
                'from_holder_id' => $from['holder']?->id,
                'from_holder_name' => $from['holder']?->name,
                'to_holder_id' => $asset->holder_id,
                'to_holder_name' => $asset->holder_id ? ($toHolder?->name ?? $asset->holder?->name) : null,
                'from_location_id' => $from['location_id'],
                'to_location_id' => $asset->location_id,
                'due_back_at' => $type === 'pinjam' ? $data['due_back_at'] : null,
                'note' => $data['note'] ?? null,
                'user_id' => $actor->id,
            ]);

            Audit::log('asset.move', "{$movement->typeLabel()}: {$asset->code}".($asset->holder_id ? ' → '.$movement->to_holder_name : ''), $asset);

            return $movement;
        });
    }

    /** Jadwalkan atau catat servis. Servis perbaikan yang belum selesai menandai aset "Perbaikan". */
    public function addService(Asset $asset, User $actor, array $data): AssetService
    {
        return DB::transaction(function () use ($asset, $actor, $data) {
            $done = ($data['status'] ?? 'dijadwalkan') === 'selesai';
            $service = $asset->services()->create($data + ['user_id' => $actor->id, 'completed_at' => $done ? ($data['completed_at'] ?? today()) : null]);

            if (! $done && $service->type === 'perbaikan' && $asset->isActive()) {
                $asset->update(['status' => 'perbaikan']);
            }
            if ($done) {
                $this->afterServiceDone($asset, $service);
            } elseif ($service->scheduled_at && (! $asset->next_service_at || $service->scheduled_at->lt($asset->next_service_at))) {
                $asset->update(['next_service_at' => $service->scheduled_at]);
            }

            Audit::log('asset.service', "{$service->typeLabel()} {$asset->code}: {$service->statusLabel()}.", $asset);

            return $service;
        });
    }

    /** Selesaikan / batalkan servis yang dijadwalkan. */
    public function updateService(AssetService $service, User $actor, array $data): AssetService
    {
        return DB::transaction(function () use ($service, $data) {
            $wasDone = $service->status === 'selesai';
            $service->fill($data);
            if ($service->status === 'selesai' && ! $service->completed_at) {
                $service->completed_at = today();
            }
            $service->save();

            $asset = $service->asset;
            if ($service->status === 'selesai' && ! $wasDone) {
                $this->afterServiceDone($asset, $service);
            } elseif ($service->status === 'batal' && $asset->status === 'perbaikan' && ! $asset->services()->where('status', 'dijadwalkan')->where('type', 'perbaikan')->whereNull('asset_report_id')->exists()) {
                $asset->update(['status' => $asset->holder_id ? 'digunakan' : 'tersedia']);
            }

            Audit::log('asset.service', "Servis {$asset->code} diubah menjadi {$service->statusLabel()}.", $asset);

            return $service;
        });
    }

    /** Setelah servis selesai: aset kembali dipakai, jadwal servis berikutnya dihitung dari interval. */
    private function afterServiceDone(Asset $asset, AssetService $service): void
    {
        $updates = [];
        if ($asset->status === 'perbaikan' && ! $asset->services()->where('status', 'dijadwalkan')->where('type', 'perbaikan')->whereNull('asset_report_id')->whereKeyNot($service->id)->exists()) {
            $updates['status'] = $asset->holder_id ? 'digunakan' : 'tersedia';
        }
        if ($asset->service_interval_months) {
            $updates['next_service_at'] = Carbon::parse($service->completed_at ?? today())->addMonthsNoOverflow($asset->service_interval_months);
        } elseif ($asset->next_service_at && $asset->next_service_at->lte(Carbon::parse($service->completed_at ?? today()))) {
            $updates['next_service_at'] = $asset->services()->where('status', 'dijadwalkan')->whereNull('asset_report_id')->whereNotNull('scheduled_at')->min('scheduled_at');
        }
        if ($updates) {
            $asset->update($updates);
        }
    }
}
