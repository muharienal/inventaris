{{-- Form aset (tambah & ubah). Butuh: $categories, $locations. Pemegang & lokasi hanya saat tambah; setelahnya lewat mutasi. --}}
<div style="display:grid;gap:18px">
    <div class="form-section">
        <h4>Identitas</h4>
        <div class="form-grid">
            <x-select name="category_id" label="Kategori" required>
                <option value="">- Pilih -</option>
                @foreach ($categories as $c)<option value="{{ $c->id }}" @selected(old('category_id') == $c->id)>{{ $c->name }}</option>@endforeach
            </x-select>
            <x-input name="code" label="Kode aset" required maxlength="50" placeholder="Mis. AST-0147" />
        </div>
        <x-input name="name" label="Nama aset" required maxlength="150" placeholder="Mis. Lenovo ThinkPad E14 - Finance" />
        <div class="form-grid">
            <x-input name="brand" label="Merek" maxlength="80" placeholder="Mis. Lenovo" />
            <x-input name="model" label="Model / tipe" maxlength="120" placeholder="Mis. ThinkPad E14 Gen 5" />
        </div>
        <x-input name="serial_number" label="Nomor seri" maxlength="120" />
        <x-textarea name="specs" label="Spesifikasi" rows="3" maxlength="3000" placeholder="Mis. i5-1335U, RAM 16 GB, SSD 512 GB" />
    </div>

    <div class="form-section">
        <h4>Status</h4>
        <div class="form-grid">
            <x-select name="status" label="Status" required>
                @foreach (config('inventaris.statuses') as $k => $s)<option value="{{ $k }}" @selected(old('status', 'tersedia') === $k)>{{ $s['label'] }}</option>@endforeach
            </x-select>
            <x-select name="condition" label="Kondisi" required>
                @foreach (config('inventaris.conditions') as $k => $s)<option value="{{ $k }}" @selected(old('condition', 'baik') === $k)>{{ $s['label'] }}</option>@endforeach
            </x-select>
        </div>
        <div data-hide-on-edit style="display:grid;gap:14px">
            <x-select name="location_id" label="Lokasi">
                <option value="">- Belum ditentukan -</option>
                @foreach ($locations as $l)<option value="{{ $l->id }}" @selected(old('location_id') == $l->id)>{{ $l->fullName() }}</option>@endforeach
            </x-select>
            @include('assets._holder-picker', ['p' => 'asset', 'none' => true, 'label' => 'Pemegang'])
        </div>
        <p class="hint" data-show-on-edit hidden style="margin:0">Pemegang dan lokasi diubah lewat tombol <strong>Alihkan</strong>, <strong>Pinjamkan</strong>, atau <strong>Pindah Lokasi</strong> agar riwayatnya tercatat.</p>
    </div>

    <div class="form-section">
        <h4>Pembelian</h4>
        <div class="form-grid">
            <x-input name="purchase_date" type="date" label="Tanggal beli" />
            <x-input name="vendor" label="Vendor / toko" maxlength="120" />
        </div>
    </div>

    <div class="form-section">
        <h4>Garansi &amp; perawatan</h4>
        <div class="form-grid">
            <x-input name="due_date" type="date" label="Garansi / lisensi s.d." />
            <x-input name="next_service_at" type="date" label="Servis berikutnya" />
        </div>
        <x-input name="service_interval_months" type="number" label="Interval servis (bulan)" min="1" max="120" hint="Bila diisi, jadwal servis berikutnya dihitung otomatis setiap servis selesai." />
        <x-textarea name="notes" label="Catatan" rows="2" maxlength="3000" />
    </div>

    <div class="field" data-hide-on-edit>
        <span class="label">Foto (opsional, maks. {{ config('inventaris.photos.max_files') }})</span>
        <input type="file" class="input" name="photos[]" multiple accept="image/jpeg,image/png,image/webp" data-file-input data-max-files="{{ config('inventaris.photos.max_files') }}" data-max-kb="{{ config('inventaris.photos.max_kb') }}" data-file-error="asset-photo-err">
        <p class="error" id="asset-photo-err" hidden></p>
    </div>
</div>
