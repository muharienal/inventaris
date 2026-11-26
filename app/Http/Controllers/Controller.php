<?php

namespace App\Http\Controllers;

use App\Models\Unit;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Collection;

abstract class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    /** Pastikan pengguna mengelola unit tersebut (Superadmin selalu lolos). */
    protected function authorizeUnit(?int $unitId): void
    {
        abort_unless($unitId && auth()->user()->managesUnit($unitId), 403, 'Anda tidak mengelola regu ini.');
    }

    /** Unit yang boleh dikelola pengguna yang sedang login. */
    protected function managedUnits(): Collection
    {
        return Unit::whereIn('id', auth()->user()->unitIds() ?: [0])->ordered()->get();
    }

    /** Unit aktif dari ?unit=, atau unit kelolaan pertama. 403 bila tidak punya unit. */
    protected function currentUnit(?int $unitId): Unit
    {
        $units = $this->managedUnits();
        $unit = ($unitId ? $units->firstWhere('id', $unitId) : null) ?? $units->first();
        abort_unless($unit, 403, 'Akun Anda belum terhubung ke regu mana pun. Hubungi Superadmin.');

        return $unit;
    }
}
