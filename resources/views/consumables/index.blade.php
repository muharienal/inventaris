@extends('layouts.app')
@section('title', 'Barang Habis Pakai')
@section('subtitle', 'Stok ATK, tinta, suku cadang, dan perlengkapan unit '.$unit->name)

@section('content')
@if ($units->count() > 1)
    <div class="tabs mb-4">
        @foreach ($units as $u)
            <a class="tab {{ $u->id === $unit->id ? 'active' : '' }}" href="{{ route('consumables.index', ['unit' => $u->id]) }}">{{ $u->name }}</a>
        @endforeach
    </div>
@endif

<div class="card">
    <div class="card-head" style="flex-wrap:wrap">
        <div class="tabs">
            <a class="tab {{ ! $filter ? 'active' : '' }}" href="{{ route('consumables.index', ['unit' => $unit->id]) }}">Aktif</a>
            <a class="tab {{ $filter === 'menipis' ? 'active' : '' }}" href="{{ route('consumables.index', ['unit' => $unit->id, 'filter' => 'menipis']) }}">Menipis <span class="n">{{ $lowCount }}</span></a>
            <a class="tab {{ $filter === 'semua' ? 'active' : '' }}" href="{{ route('consumables.index', ['unit' => $unit->id, 'filter' => 'semua']) }}">Termasuk nonaktif</a>
        </div>
        <div class="flex gap-2 wrap">
            <a class="btn btn-secondary btn-sm" href="{{ route('consumables.export', ['unit' => $unit->id]) }}"><x-icon name="download" :size="16" /> Ekspor Excel</a>
            @manage<button class="btn btn-primary btn-sm" data-modal-open="consumable-modal"><x-icon name="plus" :size="16" /> Tambah Barang</button>@endmanage
        </div>
    </div>

    <form method="GET" class="card-body filters" style="padding-bottom:0">
        <input type="hidden" name="unit" value="{{ $unit->id }}">
        @if ($filter)<input type="hidden" name="filter" value="{{ $filter }}">@endif
        <div class="field grow" style="min-width:220px"><label class="label" for="q">Cari</label>
            <div class="input-icon"><x-icon name="search" :size="16" /><input class="input" id="q" name="q" value="{{ $q }}" placeholder="Kode atau nama barang…"></div></div>
    </form>

    @if ($items->isEmpty())
        <x-empty icon="box" title="Belum ada barang">Tambahkan barang habis pakai seperti kertas, tinta, atau lampu beserta stok awalnya.</x-empty>
    @else
        <div class="table-wrap mt-4"><table class="table">
            <thead><tr><th>Barang</th><th>Kategori</th><th>Stok</th><th>Minimum</th><th>Lokasi</th><th></th></tr></thead>
            <tbody>@foreach ($items as $c)
                <tr>
                    <td><a class="strong" href="{{ route('consumables.show', $c) }}">{{ $c->name }}</a><div class="text-xs muted mono">{{ $c->code }}@unless ($c->is_active) · nonaktif @endunless</div></td>
                    <td class="text-sm">{{ $c->category->name ?? '-' }}</td>
                    <td><x-badge :tone="$c->stockTone()">{{ $c->stock }} {{ $c->unit_label }}</x-badge></td>
                    <td class="text-sm">{{ $c->min_stock }}</td>
                    <td class="text-sm">{{ $c->location->name ?? '-' }}</td>
                    <td><div class="actions">@manage
                        <button class="btn btn-secondary btn-sm" data-modal-open="stock-modal" data-action="{{ route('consumables.adjust', $c) }}" data-method="POST" data-title="Stok {{ $c->name }}" data-fill="{{ json_encode(['type' => 'masuk']) }}"><x-icon name="swap" :size="15" /> Catat Stok</button>
                    @endmanage</div></td>
                </tr>
            @endforeach</tbody></table></div>
        {{ $items->links() }}
    @endif
</div>
@endsection

@push('modals')
@manage
    <x-form-modal id="consumable-modal" create-title="Tambah Barang Habis Pakai" edit-title="Ubah Barang" :action="route('consumables.store')">
        <input type="hidden" name="unit_id" value="{{ $unit->id }}">
        @include('consumables._form')
        <div class="mt-4" data-hide-on-edit><x-input name="initial_stock" type="number" label="Stok awal" min="0" value="0" hint="Dicatat sebagai stok masuk pertama." /></div>
    </x-form-modal>
    @include('consumables._stock-modal')
@endmanage
@endpush
