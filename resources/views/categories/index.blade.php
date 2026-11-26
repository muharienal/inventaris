@extends('layouts.app')
@section('title', 'Kategori')
@section('subtitle', 'Pengelompokan aset dan barang habis pakai unit '.$unit->name)

@section('content')
@if ($units->count() > 1)
    <div class="tabs mb-4">
        @foreach ($units as $u)
            <a class="tab {{ $u->id === $unit->id ? 'active' : '' }}" href="{{ route('categories.index', ['unit' => $u->id]) }}">{{ $u->name }}</a>
        @endforeach
    </div>
@endif

<div class="grid cols-2" style="align-items:start">
    @foreach (['aset' => ['Kategori Aset', $assetCategories, 'assets_count', 'aset'], 'stok' => ['Kategori Barang Habis Pakai', $stockCategories, 'consumables_count', 'barang']] as $type => [$title, $list, $countKey, $noun])
        <div class="card">
            <div class="card-head"><h2>{{ $title }}</h2>
                @lead<button class="btn btn-primary btn-sm" data-modal-open="category-modal" data-action="{{ route('categories.store') }}" data-method="POST" data-title="Tambah {{ $title }}" data-fill="{{ json_encode(['type' => $type]) }}"><x-icon name="plus" :size="15" /> Tambah Kategori</button>@endlead</div>
            @if ($list->isEmpty())
                <div class="card-body muted text-sm">Belum ada kategori.</div>
            @else
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>Nama</th><th>Jumlah</th><th></th></tr></thead>
                    <tbody>@foreach ($list as $c)
                        <tr>
                            <td class="strong">{{ $c->name }}</td>
                            <td class="text-sm">{{ $c->{$countKey} }} {{ $noun }}</td>
                            <td><div class="actions">@lead
                                <button class="btn btn-secondary btn-sm btn-icon" aria-label="Ubah" data-modal-open="category-modal" data-action="{{ route('categories.update', $c) }}" data-title="Ubah Kategori"
                                        data-fill="{{ json_encode(['type' => $c->type, 'name' => $c->name]) }}"><x-icon name="edit" :size="15" /></button>
                                <form method="POST" action="{{ route('categories.destroy', $c) }}" data-confirm="Hapus kategori {{ $c->name }}? Hanya bisa bila kosong." data-confirm-title="Hapus kategori">@csrf @method('DELETE')
                                    <button class="btn btn-soft-danger btn-sm btn-icon" aria-label="Hapus"><x-icon name="trash" :size="15" /></button></form>
                            @endlead</div></td>
                        </tr>
                    @endforeach</tbody></table></div>
            @endif
        </div>
    @endforeach
</div>
@endsection

@push('modals')
@lead
    <x-form-modal id="category-modal" create-title="Tambah Kategori" edit-title="Ubah Kategori" :action="route('categories.store')">
        <input type="hidden" name="unit_id" value="{{ $unit->id }}">
        <input type="hidden" name="type" id="cat-type" value="{{ old('type', 'aset') }}">
        <div style="display:grid;gap:14px">
            <x-input name="name" label="Nama kategori" required maxlength="100" />
        </div>
    </x-form-modal>
@endlead
@endpush

