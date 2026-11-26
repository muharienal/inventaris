<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Kategori aset & barang habis pakai per unit. */
class CategoryController extends Controller
{
    public function index(Request $request): View
    {
        $unit = $this->currentUnit($request->integer('unit'));

        return view('categories.index', [
            'units' => $this->managedUnits(),
            'unit' => $unit,
            'assetCategories' => $unit->categories()->where('type', 'aset')->withCount('assets')->get(),
            'stockCategories' => $unit->categories()->where('type', 'stok')->withCount('consumables')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $unit = $this->currentUnit($request->integer('unit_id'));
        $category = Category::create($this->validated($request, $unit->id) + ['unit_id' => $unit->id]);
        Audit::log('category.create', "Menambah kategori {$category->name} di unit {$unit->name}.", $category);

        return back()->with('success', 'Kategori ditambahkan.');
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $this->authorizeUnit($category->unit_id);
        $category->update($this->validated($request, $category->unit_id, $category));
        Audit::log('category.update', "Mengubah kategori {$category->name}.", $category);

        return back()->with('success', 'Kategori diperbarui.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        $this->authorizeUnit($category->unit_id);
        if ($category->assets()->exists() || $category->consumables()->exists()) {
            return back()->with('error', 'Kategori masih dipakai, pindahkan dulu barangnya ke kategori lain.');
        }
        Audit::log('category.delete', "Menghapus kategori {$category->name}.", $category);
        $category->delete();

        return back()->with('success', 'Kategori dihapus.');
    }

    private function validated(Request $request, int $unitId, ?Category $category = null): array
    {
        $type = $category?->type ?? $request->input('type');

        return $request->validate([
            'type' => [$category ? 'sometimes' : 'required', Rule::in(['aset', 'stok'])],
            'name' => ['required', 'string', 'max:100', Rule::unique('categories', 'name')->where('unit_id', $unitId)->where('type', $type)->ignore($category?->id)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ], ['name.unique' => 'Nama kategori sudah ada di unit ini.']) + ['sort_order' => 0];
    }
}
