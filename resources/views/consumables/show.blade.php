@extends('layouts.app')
@section('title', $item->name)
@section('subtitle', $item->code.' · '.$item->unit->name)

@section('content')
<div class="detail-head mb-4">
    <div class="detail-nav"><a href="{{ route('consumables.index', ['unit' => $item->unit_id]) }}" class="btn btn-ghost btn-sm"><x-icon name="arrow-left" :size="16" /> Semua Barang</a></div>
    <div class="detail-panel">
        <dl class="meta-list">
            <div><dt>Stok</dt><dd><x-badge :tone="$item->stockTone()">{{ $item->stock }} {{ $item->unit_label }}</x-badge></dd></div>
            <div><dt>Minimum</dt><dd>@if ($item->isLow())<span class="note-flag is-red"><x-icon name="alert" :size="14" /> Di bawah minimum ({{ $item->min_stock }})</span>@else<span class="text-sm">{{ $item->min_stock }} {{ $item->unit_label }}</span>@endif</dd></div>
            <div><dt>Unit</dt><dd><x-unit-chip :unit="$item->unit" /></dd></div>
            @unless ($item->is_active)<div><dt>Keadaan</dt><dd><x-badge tone="gray">Nonaktif</x-badge></dd></div>@endunless
        </dl>
        @manage
        <div class="detail-actions" role="group" aria-label="Tindakan">
            <button class="btn btn-secondary btn-sm" data-modal-open="consumable-modal" data-action="{{ route('consumables.update', $item) }}"
                    data-fill="{{ json_encode(['code' => $item->code, 'name' => $item->name, 'category_id' => $item->category_id, 'unit_label' => $item->unit_label, 'min_stock' => $item->min_stock, 'location_id' => $item->location_id, 'notes' => $item->notes, 'is_active' => $item->is_active]) }}"><x-icon name="edit" :size="16" /> Ubah</button>
            <button class="btn btn-primary btn-sm" data-modal-open="stock-modal" data-action="{{ route('consumables.adjust', $item) }}" data-method="POST" data-fill="{{ json_encode(['type' => 'masuk']) }}"><x-icon name="swap" :size="16" /> Catat Stok</button>
        </div>
        @endmanage
    </div>
</div>

<div class="grid cols-main-r" style="align-items:start">
    <div class="card">
        <div class="card-head"><h2>Riwayat Stok</h2></div>
        @if ($movements->isEmpty())
            <x-empty icon="history" title="Belum ada mutasi">Catat stok masuk atau keluar lewat tombol Catat Stok.</x-empty>
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Tanggal</th><th>Jenis</th><th>Jumlah</th><th>Sisa</th><th>Catatan</th><th>Oleh</th></tr></thead>
                <tbody>@foreach ($movements as $m)
                    <tr>
                        <td class="text-sm nowrap">{{ $m->created_at->translatedFormat('d M Y H:i') }}</td>
                        <td><x-badge :tone="['masuk' => 'green', 'keluar' => 'blue', 'penyesuaian' => 'amber'][$m->type] ?? 'gray'" plain>{{ $m->typeLabel() }}</x-badge></td>
                        <td class="strong {{ $m->qty < 0 ? 'danger-text' : 'ok-text' }}">{{ $m->qty > 0 ? '+' : '' }}{{ $m->qty }}</td>
                        <td>{{ $m->stock_after }}</td>
                        <td class="text-sm">{{ $m->note ?: '-' }}</td>
                        <td class="text-sm">{{ $m->user->name ?? 'Sistem' }}</td>
                    </tr>
                @endforeach</tbody></table></div>
            {{ $movements->links() }}
        @endif
    </div>

    <div style="display:grid;gap:20px;min-width:0">
        <div class="card">
            <div class="card-head"><h2>Detail</h2></div>
            <div class="card-body" style="display:grid;gap:10px">
                <div class="flex justify-between gap-3 text-sm"><span class="muted">Kategori</span><span>{{ $item->category->name ?? '-' }}</span></div>
                <div class="flex justify-between gap-3 text-sm"><span class="muted">Satuan</span><span>{{ $item->unit_label }}</span></div>
                <div class="flex justify-between gap-3 text-sm"><span class="muted">Stok minimum</span><span>{{ $item->min_stock }}</span></div>
                <div class="flex justify-between gap-3 text-sm"><span class="muted">Lokasi</span><span>{{ $item->location->name ?? '-' }}</span></div>
                @if ($item->notes)<div class="pre-line text-sm muted">{{ $item->notes }}</div>@endif
            </div>
        </div>
        @lead
        @php $hasHistory = $item->movements()->exists(); @endphp
        @if (! $hasHistory || $item->is_active)
        <div class="card"><div class="card-body">
            <form method="POST" action="{{ route('consumables.destroy', $item) }}" data-confirm="{{ $hasHistory ? 'Barang ini punya riwayat stok, jadi dinonaktifkan saja (riwayat tetap ada). Lanjutkan?' : 'Hapus barang ini?' }}" data-confirm-title="{{ $hasHistory ? 'Nonaktifkan barang' : 'Hapus barang' }}">
                @csrf @method('DELETE')<button class="btn btn-soft-danger btn-block"><x-icon :name="$hasHistory ? 'power' : 'trash'" :size="16" /> {{ $hasHistory ? 'Nonaktifkan Barang' : 'Hapus Barang' }}</button></form>
        </div></div>
        @endif
        @endlead
    </div>
</div>
@endsection

@push('modals')
@manage
    <x-form-modal id="consumable-modal" create-title="Ubah Barang" edit-title="Ubah Barang" :action="route('consumables.update', $item)">
        @php $categories = $categories; @endphp
        @include('consumables._form')
        <label class="check mt-3"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked> Aktif (tampil di daftar stok)</label>
    </x-form-modal>
    @include('consumables._stock-modal')
@endmanage
@endpush
