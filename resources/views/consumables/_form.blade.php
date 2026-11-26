<div style="display:grid;gap:14px">
    <div class="form-grid">
        <x-input name="code" label="Kode" required maxlength="50" placeholder="Mis. KRT-A4" />
        <x-select name="category_id" label="Kategori">
            <option value="">- Tanpa kategori -</option>
            @foreach ($categories as $cat)<option value="{{ $cat->id }}">{{ $cat->name }}</option>@endforeach
        </x-select>
    </div>
    <x-input name="name" label="Nama barang" required maxlength="150" placeholder="Mis. Kertas A4 80 gsm" />
    <div class="form-grid">
        <x-input name="unit_label" label="Satuan" required maxlength="20" value="pcs" placeholder="pcs, rim, botol, box" />
        <x-input name="min_stock" type="number" label="Stok minimum" required min="0" value="0" hint="Di bawah angka ini, barang ditandai menipis." />
    </div>
    <x-select name="location_id" label="Lokasi penyimpanan">
        <option value="">- Tidak ditentukan -</option>
        @foreach ($locations as $l)<option value="{{ $l->id }}">{{ $l->name }}</option>@endforeach
    </x-select>
    <x-textarea name="notes" label="Catatan" rows="2" maxlength="2000" />
</div>
