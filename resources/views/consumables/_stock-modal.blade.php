<x-form-modal id="stock-modal" create-title="Catat Stok" edit-title="Catat Stok" :action="route('consumables.index')" submit="Simpan">
    <div style="display:grid;gap:14px">
        <x-select name="type" id="stock-type" label="Jenis" required>
            <option value="masuk">Stok masuk (pembelian / penerimaan)</option>
            <option value="keluar">Stok keluar (pemakaian)</option>
            <option value="penyesuaian">Penyesuaian (hasil hitung fisik)</option>
        </x-select>
        <x-input name="qty" id="stock-qty" type="number" label="Jumlah" required min="1" />
        <div id="stock-recipient-wrap" hidden><x-input name="recipient" id="stock-recipient" label="Diterima oleh" maxlength="120" placeholder="Mis. Andi (Keuangan)" /></div>
        <x-input name="note" id="stock-note" label="Catatan" maxlength="500" placeholder="Mis. pembelian PO-0123, dipakai rapat direksi" />
    </div>
</x-form-modal>

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    // Kolom menyesuaikan jenis: "Diterima oleh" hanya untuk stok keluar; penyesuaian = stok sebenarnya.
    var type = document.getElementById('stock-type'), qty = document.getElementById('stock-qty'), wrap = document.getElementById('stock-recipient-wrap');
    if (!type || !qty || !wrap) { return; }
    var lbl = document.querySelector('label[for=stock-qty]');
    function sync() {
        var adjust = type.value === 'penyesuaian';
        wrap.hidden = type.value !== 'keluar';
        wrap.querySelector('input').disabled = wrap.hidden;
        qty.min = adjust ? 0 : 1;
        if (lbl) { lbl.firstChild.textContent = adjust ? 'Stok sebenarnya (hasil hitung) ' : 'Jumlah '; }
    }
    type.addEventListener('change', sync);
    document.addEventListener('hu:prepared', sync);
    sync();
})();
</script>
@endpush
