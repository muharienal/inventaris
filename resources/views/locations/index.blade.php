@extends('layouts.app')
@section('title', 'Lokasi & Ruangan')
@section('subtitle', 'Ruangan dan area tempat aset & stok disimpan, dipakai bersama semua unit dan HaloDek. Mengubah atau menghapus hanya Superadmin.')

@section('content')
<div class="card">
    <div class="card-head" style="flex-wrap:wrap">
        <form method="GET" class="grow" style="max-width:420px" role="search"><div class="input-icon"><x-icon name="search" :size="16" /><input class="input" type="search" name="q" value="{{ $search }}" placeholder="Nama, gedung, atau penanggung jawab…" aria-label="Cari lokasi"></div></form>
        <div class="flex gap-2">@lead
            <button class="btn btn-secondary btn-sm" data-modal-open="import-modal"><x-icon name="upload" :size="16" /> Impor CSV</button>
            <button class="btn btn-primary btn-sm" data-modal-open="location-modal"><x-icon name="plus" :size="16" /> Tambah Lokasi</button>
        @endlead</div>
    </div>
    @if ($locations->isEmpty())
        <x-empty icon="pin" title="Belum ada lokasi" />
    @else
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Nama</th><th>Gedung / Lantai</th><th>Penanggung jawab</th><th>Aset</th><th>Stok</th><th></th></tr></thead>
            <tbody>@foreach ($locations as $l)
                <tr>
                    <td class="strong">{{ $l->name }}@if ($l->note)<div class="text-xs muted">{{ \Illuminate\Support\Str::limit($l->note, 70) }}</div>@endif</td>
                    <td>{{ collect([$l->building, $l->floor ? 'Lt. '.$l->floor : null])->filter()->implode(' · ') ?: '-' }}</td>
                    <td>{{ $l->pic_name ?: '-' }}</td><td>{{ $l->assets_count }}</td><td>{{ $l->consumables_count }}</td>
                    <td><div class="actions">@if (auth()->user()->isSuperadmin())
                        <button class="btn btn-secondary btn-sm btn-icon" aria-label="Ubah {{ $l->name }}" title="Ubah" data-modal-open="location-modal" data-title="Ubah Lokasi" data-action="{{ route('locations.update', $l) }}"
                                data-fill="{{ json_encode(['name' => $l->name, 'building' => $l->building, 'floor' => $l->floor, 'pic_name' => $l->pic_name, 'note' => $l->note]) }}"><x-icon name="edit" :size="15" /></button>
                        <form method="POST" action="{{ route('locations.destroy', $l) }}" data-confirm="Hapus lokasi {{ $l->name }}?" data-confirm-title="Hapus lokasi">@csrf @method('DELETE')
                            <button class="btn btn-soft-danger btn-sm btn-icon" aria-label="Hapus {{ $l->name }}" title="Hapus"><x-icon name="trash" :size="15" /></button></form>
                    @endif</div></td>
                </tr>
            @endforeach</tbody></table></div>
    @endif
    {{ $locations->links() }}
</div>
@endsection

@push('modals')
@lead
    <x-form-modal id="location-modal" create-title="Tambah Lokasi" edit-title="Ubah Lokasi" :action="route('locations.store')">
        <div style="display:grid;gap:16px">
            <x-input name="name" label="Nama ruangan / area" required maxlength="150" placeholder="Ruang Rapat Utama" />
            <div class="form-grid"><x-input name="building" label="Gedung" maxlength="100" /><x-input name="floor" label="Lantai" maxlength="30" /></div>
            <x-input name="pic_name" label="Penanggung jawab ruangan" maxlength="150" />
            <x-textarea name="note" label="Catatan" rows="2" maxlength="1000" />
        </div>
    </x-form-modal>
    <x-import-modal :action="route('locations.import')" :template="route('locations.template')" title="Impor Lokasi dari CSV" columns="name, building, floor, pic_name, note" />
@endlead
@endpush
