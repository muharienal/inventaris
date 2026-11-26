<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AssetMovement;
use App\Models\AssetReport;
use App\Models\Consumable;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return $this->admin($user);
    }

    private function admin(User $user): View
    {
        $unitIds = $user->unitIds() ?: [0];
        $assets = fn () => Asset::query()->whereIn('unit_id', $unitIds);
        $days = config('inventaris.due_soon_days');

        $byStatus = $assets()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $stats = [
            'total' => $byStatus->except('nonaktif')->sum(),
            'dipinjam' => (int) ($byStatus['dipinjam'] ?? 0),
            'servis' => $assets()->serviceDue($days)->count(),
            'menipis' => Consumable::whereIn('unit_id', $unitIds)->low()->count(),
        ];

        // "Perlu perhatian": daftar ringkas yang harus ditindaklanjuti.
        $attention = [
            'overdueLoans' => AssetMovement::with(['asset', 'toHolder'])->where('type', 'pinjam')->whereNull('returned_at')
                ->whereDate('due_back_at', '<', today())->whereHas('asset', fn ($q) => $q->whereIn('unit_id', $unitIds))->orderBy('due_back_at')->take(8)->get(),
            // Pegawai sudah nonaktif (keluar/pindah) tetapi masih tercatat memegang aset.
            'inactiveHolders' => $assets()->whereHas('holder', fn ($q) => $q->where('is_active', false))->with('holder')->orderBy('code')->take(8)->get(),
            'service' => $assets()->serviceDue($days)->with('location')->orderBy('next_service_at')->take(8)->get(),
            'expiring' => $assets()->dueSoon($days)->with('location')->orderBy('due_date')->take(8)->get(),
            // "+ 0.0": stock & min_stock unsigned; selisih negatif membuat MySQL error tanpa ini.
            'lowStock' => Consumable::whereIn('unit_id', $unitIds)->low()->orderByRaw('(stock + 0.0) - min_stock')->take(8)->get(),
            // Satu baris per aset, walau laporannya lebih dari satu.
            'reports' => AssetReport::with('asset')->whereIn('status', ['menunggu', 'diproses'])->whereHas('asset', fn ($q) => $q->whereIn('unit_id', $unitIds))
                ->latest('reported_at')->take(200)->get()->groupBy('asset_id')->take(8),
        ];

        // Mutasi stok keluar terbaru (siapa menerima apa).
        $recentOut = \App\Models\StockMovement::with('consumable')->where('type', 'keluar')
            ->whereHas('consumable', fn ($q) => $q->whereIn('unit_id', $unitIds))->latest()->take(6)->get();

        $units = $this->managedUnits()->map(fn ($u) => [
            'unit' => $u,
            'assets' => Asset::where('unit_id', $u->id)->active()->count(),
            'stock' => Consumable::where('unit_id', $u->id)->where('is_active', true)->count(),
        ]);

        return view('dashboard.admin', compact('stats', 'byStatus', 'attention', 'recentOut', 'units'));
    }
}
