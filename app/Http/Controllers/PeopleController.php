<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Saran nama/NIK untuk kolom pemegang dan "Beri akses": dicari saat mengetik (maks. 20),
 * agar halaman tidak memuat ribuan nama sekaligus.
 */
class PeopleController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q'));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }
        // "NIK - Nama" yang sudah dipilih: cari dari NIK-nya saja.
        $q = trim(explode(' - ', $q)[0]);

        $rows = User::active()
            ->when($request->query('scope') === 'tanpa-akses', fn ($w) => $w->where('role', User::ROLE_KARYAWAN))
            ->where(fn ($w) => $w->where('nik', 'like', $q.'%')->orWhere('name', 'like', '%'.$q.'%'))
            ->orderBy('name')->limit(20)->get(['nik', 'name', 'department']);

        return response()->json($rows->map(fn (User $u) => [
            'value' => $u->nik.' - '.$u->name,
            'label' => (string) $u->department,
        ])->values());
    }
}
