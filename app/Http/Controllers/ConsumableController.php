<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Consumable;
use App\Models\Location;
use App\Services\StockManager;
use App\Support\Audit;
use App\Support\Xlsx;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Barang habis pakai per unit: stok, batas minimum, mutasi masuk/keluar. */
class ConsumableController extends Controller
{
    public function __construct(private StockManager $stock) {}

    public function index(Request $request): View
    {
        $unit = $this->currentUnit($request->integer('unit'));
        $q = trim((string) $request->query('q'));
        $filter = $request->query('filter'); // menipis

        $items = Consumable::with(['category', 'location'])->where('unit_id', $unit->id)
            ->when($filter === 'menipis', fn ($w) => $w->low())
            ->when($filter !== 'semua', fn ($w) => $w->where('is_active', true))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")))
            ->orderByRaw('CASE WHEN stock <= min_stock THEN 0 ELSE 1 END')->orderBy('name')
            ->paginate(config('inventaris.per_page'))->withQueryString();

        return view('consumables.index', [
            'units' => $this->managedUnits(),
            'unit' => $unit,
            'items' => $items,
            'q' => $q,
            'filter' => $filter,
            'lowCount' => Consumable::where('unit_id', $unit->id)->low()->count(),
            'categories' => $unit->categories()->where('type', 'stok')->get(),
            'locations' => Location::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Consumable $consumable): View
    {
        $this->authorizeUnit($consumable->unit_id);
        $consumable->load(['unit', 'category', 'location']);

        return view('consumables.show', [
            'item' => $consumable,
            'movements' => $consumable->movements()->with('user')->paginate(20),
            'categories' => Category::where('unit_id', $consumable->unit_id)->where('type', 'stok')->orderBy('name')->get(),
            'locations' => Location::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $unit = $this->currentUnit($request->integer('unit_id'));
        $data = $this->validated($request, $unit->id);
        $initial = (int) $request->input('initial_stock', 0);

        $item = Consumable::create($data + ['unit_id' => $unit->id, 'stock' => 0]);
        if ($initial > 0) {
            $this->stock->adjust($item, $request->user(), 'masuk', $initial, 'Stok awal.');
        }
        Audit::log('stock.create', "Menambah barang habis pakai {$item->code} ({$item->name}).", $item);

        return back()->with('success', "Barang {$item->name} ditambahkan.");
    }

    public function update(Request $request, Consumable $consumable): RedirectResponse
    {
        $this->authorizeUnit($consumable->unit_id);
        $consumable->update($this->validated($request, $consumable->unit_id, $consumable) + ['is_active' => $request->boolean('is_active', true)]);
        Audit::log('stock.update', "Mengubah barang habis pakai {$consumable->code}.", $consumable);

        return back()->with('success', 'Data barang diperbarui.');
    }

    public function destroy(Consumable $consumable): RedirectResponse
    {
        $this->authorizeUnit($consumable->unit_id);
        if ($consumable->movements()->exists()) {
            $consumable->update(['is_active' => false]);
            Audit::log('stock.deactivate', "Menonaktifkan barang habis pakai {$consumable->code} (punya riwayat stok).", $consumable);

            return back()->with('success', "{$consumable->name} dinonaktifkan (riwayat stok tetap disimpan).");
        }
        $consumable->delete();
        Audit::log('stock.delete', "Menghapus barang habis pakai {$consumable->code}.");

        return redirect()->route('consumables.index', ['unit' => $consumable->unit_id])->with('success', 'Barang dihapus.');
    }

    /** Stok masuk / keluar / penyesuaian (hasil hitung fisik). */
    public function adjust(Request $request, Consumable $consumable): RedirectResponse
    {
        $this->authorizeUnit($consumable->unit_id);
        $data = $request->validate([
            'type' => ['required', Rule::in(['masuk', 'keluar', 'penyesuaian'])],
            'qty' => ['required', 'integer', 'min:0', 'max:1000000'],
            'recipient' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['qty.required' => 'Isi jumlah.']);

        $m = $this->stock->adjust($consumable, $request->user(), $data['type'], (int) $data['qty'], $data['note'] ?? null, $data['recipient'] ?? null);

        return back()->with('success', "{$consumable->name}: ".($m->qty >= 0 ? '+' : '')."{$m->qty} {$consumable->unit_label} ({$m->typeLabel()}), sisa {$m->stock_after} {$consumable->unit_label}.");
    }

    public function export(Request $request): BinaryFileResponse
    {
        $unit = $this->currentUnit($request->integer('unit'));
        $h = fn ($t) => ['v' => $t, 's' => 'head'];
        $rows = [array_map($h, ['Kode', 'Nama', 'Kategori', 'Stok', 'Satuan', 'Minimum', 'Lokasi', 'Status'])];
        foreach (Consumable::with(['category', 'location'])->where('unit_id', $unit->id)->orderBy('name')->cursor() as $c) {
            $rows[] = [$c->code, $c->name, $c->category->name ?? '', ['v' => $c->stock, 's' => 'int'], $c->unit_label, ['v' => $c->min_stock, 's' => 'int'],
                $c->location->name ?? '', ! $c->is_active ? 'Nonaktif' : ($c->isLow() ? 'Menipis' : 'Aman')];
        }
        $path = Xlsx::build([['name' => 'Stok '.$unit->name, 'widths' => [14, 34, 20, 10, 10, 10, 22, 12], 'rows' => $rows]]);

        return response()->download($path, 'stok-'.$unit->code.'-'.now()->format('Ymd').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function validated(Request $request, int $unitId, ?Consumable $item = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:50', Rule::unique('consumables', 'code')->where('unit_id', $unitId)->ignore($item?->id)],
            'name' => ['required', 'string', 'max:150'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('unit_id', $unitId)->where('type', 'stok')],
            'unit_label' => ['required', 'string', 'max:20'],
            'min_stock' => ['required', 'integer', 'min:0', 'max:1000000'],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], ['code.unique' => 'Kode barang sudah dipakai di regu ini.']);
    }
}
