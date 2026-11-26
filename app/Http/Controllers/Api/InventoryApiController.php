<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetReport;
use App\Models\Location;
use App\Models\OrgUnit;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * API v1 untuk HaloDek (aplikasi tiket aduan).
 *
 *  GET  /api/v1/ping
 *  GET  /api/v1/units
 *  GET  /api/v1/locations
 *  GET  /api/v1/assets?unit=it&location_id=3&holder_nik=00004&holder_department=Umum%20%26%20IT&holder_division=...&include_id=12&q=lenovo
 *       -> aset aktif yang boleh dilaporkan: dipegang NIK tsb, dipegang bidang/divisi orang tsb,
 *          tidak dipegang perorangan di lokasi tsb, plus "include_id" (aset yang baru dipindai QR-nya).
 *  GET  /api/v1/assets/{id}
 *  POST /api/v1/report-events   (HaloDek mengabarkan laporan kendala sebuah aset dibuat/berubah)
 */
class InventoryApiController extends Controller
{
    public function ping(): JsonResponse
    {
        return response()->json(['ok' => true, 'app' => config('inventaris.full_name'), 'time' => now()->toIso8601String()]);
    }

    public function units(): JsonResponse
    {
        return response()->json(['data' => Unit::active()->ordered()->get()->map(fn ($u) => [
            'code' => $u->code, 'name' => $u->name, 'group' => $u->group,
        ])]);
    }

    public function locations(): JsonResponse
    {
        return response()->json(['data' => Location::orderBy('name')->get()->map(fn ($l) => [
            'id' => $l->id, 'name' => $l->name, 'building' => $l->building, 'floor' => $l->floor, 'label' => $l->fullName(),
        ])]);
    }

    public function assets(Request $request): JsonResponse
    {
        $unit = Unit::where('code', (string) $request->query('unit'))->first();
        if (! $unit) {
            return response()->json(['data' => []]);
        }

        $locationId = $request->integer('location_id') ?: null;
        $holder = $request->filled('holder_nik') ? User::where('nik', (string) $request->query('holder_nik'))->first() : null;
        // Aset milik bidang / divisi pelapor juga boleh dilaporkan. Nama dari HaloDek (paling baru) diutamakan.
        $orgIds = $request->filled('holder_nik') ? OrgUnit::idsForPerson(
            $request->filled('holder_department') ? (string) $request->query('holder_department') : $holder?->department,
            $request->filled('holder_division') ? (string) $request->query('holder_division') : $holder?->division,
        ) : [];
        $includeId = $request->integer('include_id') ?: null;
        $q = trim((string) $request->query('q'));

        $assets = Asset::with(['location', 'holder', 'holderOrg', 'category', 'unit'])->where('unit_id', $unit->id)->active()
            ->where(function ($w) use ($holder, $orgIds, $locationId, $includeId) {
                $w->whereRaw('1 = 0');
                if ($holder) {
                    $w->orWhere('holder_id', $holder->id);
                }
                if ($orgIds) {
                    $w->orWhere(fn ($x) => $x->whereNull('holder_id')->whereIn('holder_org_id', $orgIds));
                }
                if ($locationId) {
                    $w->orWhere(fn ($x) => $x->whereNull('holder_id')->where('location_id', $locationId));
                }
                if ($includeId) {
                    $w->orWhere('id', $includeId);
                }
            })
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")))
            ->orderBy('code')->limit(200)->get();

        return response()->json(['data' => $assets->map(fn ($a) => $this->present($a))->values()]);
    }

    public function show(int $id): JsonResponse
    {
        $asset = Asset::with(['location', 'holder', 'holderOrg', 'category', 'unit'])->find($id);

        return $asset ? response()->json(['data' => $this->present($asset)]) : response()->json(['message' => 'Aset tidak ditemukan.'], 404);
    }

    /** @return array<string, mixed> */
    private function present(Asset $asset): array
    {
        return [
            'id' => $asset->id,
            'code' => $asset->code,
            'name' => $asset->name,
            'label' => $asset->label(),
            'unit' => $asset->unit->code ?? null,
            'category' => $asset->category->name ?? null,
            'status' => $asset->status,
            'active' => $asset->isActive(),
            'location' => $asset->location ? ['id' => $asset->location->id, 'name' => $asset->location->name, 'label' => $asset->location->fullName()] : null,
            // type: orang | bidang | divisi. nik hanya untuk orang.
            'holder' => $asset->holder_id && $asset->holder
                ? ['type' => 'orang', 'nik' => $asset->holder->nik, 'name' => $asset->holder->name]
                : ($asset->holder_org_id && $asset->holderOrg ? ['type' => $asset->holderOrg->type, 'nik' => null, 'name' => $asset->holderOrg->label()] : null),
            'url' => route('assets.show', $asset),
            // Halaman cek aset tanpa login (sama dengan isi stiker QR): aman dibuka siapa pun di HaloDek.
            'check_url' => $asset->verifyUrl(),
        ];
    }

    /** Idempoten: laporan yang sama dikirim ulang hanya memperbarui datanya. */
    public function reportEvent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'report_id' => ['required', 'integer', 'min:1'],
            'report_number' => ['required', 'string', 'max:40'],
            'asset_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['menunggu', 'diproses', 'selesai', 'ditolak', 'dibatalkan'])],
            'status_label' => ['required', 'string', 'max:40'],
            'reporter_name' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'url', 'max:500'],
            'reported_at' => ['nullable', 'date'],
            // Detail penanganan (HaloDek versi baru) untuk riwayat perbaikan aset.
            'team' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'handler_name' => ['nullable', 'string', 'max:150'],
            'resolution' => ['nullable', 'string', 'max:2000'],
            'resolved_at' => ['nullable', 'date'],
        ]);

        if (! Asset::whereKey($data['asset_id'])->exists()) {
            return response()->json(['message' => 'Aset tidak ditemukan.'], 404);
        }

        $row = \Illuminate\Support\Facades\DB::transaction(function () use ($data) {
            $row = AssetReport::updateOrCreate(['report_id' => $data['report_id']], $data);
            $row->syncService();

            return $row;
        });

        return response()->json(['ok' => true, 'id' => $row->id], $row->wasRecentlyCreated ? 201 : 200);
    }
}
