<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetPhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Halaman cek aset dari stiker QR (tanpa login). Menunjukkan bahwa barang fisik ini terdaftar
 * di Inventaris beserta datanya, sehingga bisa dicocokkan (kode, merek, nomor seri, pemegang).
 * Alamatnya memakai token rahasia per aset, jadi aset lain tidak bisa ditebak dari nomor urut.
 */
class AssetCheckController extends Controller
{
    public function show(Request $request, int $asset, string $token): View
    {
        $item = $this->resolve($asset, $token);
        $item->load(['unit', 'category', 'holder', 'location', 'photos']);

        $user = $request->user();

        return view('assets.check', [
            'asset' => $item,
            'token' => $token,
            'lastService' => $item->services()->where('status', 'selesai')->latest('completed_at')->latest('id')->first(),
            'repairing' => $item->services()->where('status', 'dijadwalkan')->where('type', 'perbaikan')->exists(),
            'openReports' => $item->reports()->whereIn('status', ['menunggu', 'diproses'])->count(),
            'canOpen' => $user && $user->is_active && in_array($user->role, \App\Models\User::ADMIN_ROLES, true) && $user->managesUnit($item->unit_id),
        ]);
    }

    public function photo(int $asset, string $token, AssetPhoto $photo)
    {
        $item = $this->resolve($asset, $token);
        abort_unless($photo->asset_id === $item->id && Storage::disk('local')->exists($photo->path), 404);

        return response()->file(Storage::disk('local')->path($photo->path), [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function resolve(int $id, string $token): Asset
    {
        $item = Asset::find($id);
        // Token salah dan aset tidak ada dibalas sama (404) agar tidak bisa dipakai menebak.
        abort_unless($item && hash_equals($item->verifyToken(), $token), 404);

        return $item;
    }
}
