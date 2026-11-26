<?php

namespace App\Http\Controllers;

use App\Models\Consumable;
use App\Models\ConsumableRequest;
use App\Models\Unit;
use App\Services\StockManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Permintaan barang habis pakai.
 *  - Semua pengguna: ajukan & pantau permintaan sendiri, batalkan selama masih menunggu.
 *  - Pengelola: setujui (stok otomatis berkurang) atau tolak permintaan di unitnya.
 */
class StockRequestController extends Controller
{
    public function __construct(private StockManager $stock) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $status = $request->query('status', 'menunggu');

        $mine = ConsumableRequest::with(['consumable.unit', 'decider'])->where('user_id', $user->id)->latest()->take(30)->get();

        $queue = null;
        if ($user->isAdmin()) {
            $queue = ConsumableRequest::with(['consumable.unit', 'user'])
                ->whereHas('consumable', fn ($q) => $q->whereIn('unit_id', $user->unitIds() ?: [0]))
                ->when($status !== 'semua', fn ($q) => $q->where('status', $status))
                ->orderByRaw("CASE status WHEN 'menunggu' THEN 0 ELSE 1 END")->latest()
                ->paginate(config('inventaris.per_page'))->withQueryString();
        }

        // Katalog untuk form permintaan: semua barang aktif, dikelompokkan per unit.
        $catalog = Consumable::with('unit')->where('is_active', true)->orderBy('name')->get(['id', 'unit_id', 'code', 'name', 'unit_label', 'stock'])
            ->groupBy(fn ($c) => $c->unit->name);

        return view('requests.index', compact('mine', 'queue', 'status', 'catalog'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'consumable_id' => ['required', 'integer', Rule::exists('consumables', 'id')->where('is_active', true)],
            'qty' => ['required', 'integer', 'min:1', 'max:10000'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['consumable_id.required' => 'Pilih barang yang diminta.', 'qty.min' => 'Jumlah minimal 1.']);

        $item = Consumable::findOrFail($data['consumable_id']);
        $this->stock->request($request->user(), $item, (int) $data['qty'], $data['note'] ?? null);

        return redirect()->route('requests.index')->with('success', "Permintaan {$data['qty']} {$item->unit_label} {$item->name} terkirim ke pengelola {$item->unit->name}.");
    }

    public function approve(Request $request, ConsumableRequest $stockRequest): RedirectResponse
    {
        $this->authorizeUnit($stockRequest->consumable->unit_id);
        $data = $request->validate([
            'qty' => ['nullable', 'integer', 'min:1', 'max:'.$stockRequest->qty],
            'decision_note' => ['nullable', 'string', 'max:500'],
        ], ['qty.max' => 'Jumlah yang disetujui tidak boleh melebihi permintaan.']);

        $this->stock->approve($stockRequest, $request->user(), $data['qty'] ?? null, $data['decision_note'] ?? null);

        return back()->with('success', "Permintaan {$stockRequest->requester_name} disetujui, stok dikurangi.");
    }

    public function reject(Request $request, ConsumableRequest $stockRequest): RedirectResponse
    {
        $this->authorizeUnit($stockRequest->consumable->unit_id);
        $data = $request->validate(['decision_note' => ['required', 'string', 'max:500']], ['decision_note.required' => 'Tuliskan alasan penolakan.']);
        $this->stock->reject($stockRequest, $request->user(), $data['decision_note']);

        return back()->with('success', 'Permintaan ditolak.');
    }

    public function cancel(Request $request, ConsumableRequest $stockRequest): RedirectResponse
    {
        abort_unless($stockRequest->user_id === $request->user()->id, 403);
        if ($stockRequest->status !== 'menunggu') {
            return back()->with('error', 'Permintaan yang sudah diputuskan tidak bisa dibatalkan.');
        }
        $stockRequest->update(['status' => 'dibatalkan']);

        return back()->with('success', 'Permintaan dibatalkan.');
    }
}
