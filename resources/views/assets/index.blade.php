@extends('layouts.app')
@section('title', 'Aset')
@section('subtitle', 'Barang tahan lama milik unit '.$unit->name)

@section('content')
@php
    $keep = fn (array $extra = []) => array_filter(['unit' => $unit->id, 'category' => $f['category'], 'status' => $f['status'] !== 'aktif' ? $f['status'] : null, 'q' => $f['q'] ?: null, 'perhatian' => $f['perhatian']] + $extra, fn ($v) => $v !== null && $v !== '');
    $total = $statusCounts->except('nonaktif')->sum();
@endphp

@if ($units->count() > 1)
    <div class="tabs mb-4">
        @foreach ($units as $u)
            <a class="tab {{ $u->id === $unit->id ? 'active' : '' }}" href="{{ route('assets.index', ['unit' => $u->id]) }}">{{ $u->name }}</a>
        @endforeach
    </div>
@endif

<div class="card">
    <div class="card-head" style="flex-wrap:wrap">
        <div class="tabs">
            <a class="tab {{ $f['status'] === 'aktif' && ! $f['perhatian'] ? 'active' : '' }}" href="{{ route('assets.index', $keep(['status' => null, 'perhatian' => null])) }}">Aktif <span class="n">{{ $total }}</span></a>
            @foreach (config('inventaris.statuses') as $k => $s)
                @if (($statusCounts[$k] ?? 0) > 0)
                    <a class="tab {{ $f['status'] === $k ? 'active' : '' }}" href="{{ route('assets.index', $keep(['status' => $k, 'perhatian' => null])) }}">{{ $s['label'] }} <span class="n">{{ $statusCounts[$k] }}</span></a>
                @endif
            @endforeach
        </div>
        <div class="flex gap-2 wrap">
            <a class="btn btn-secondary btn-sm" href="{{ route('assets.qr', $keep()) }}" target="_blank" rel="noopener" title="Cetak stiker QR untuk aset yang sedang ditampilkan"><x-icon name="qr" :size="16" /> Cetak QR</a>
            <a class="btn btn-secondary btn-sm" href="{{ route('assets.export', $keep()) }}"><x-icon name="download" :size="16" /> Ekspor Excel</a>
            @lead<button class="btn btn-secondary btn-sm" data-modal-open="import-modal"><x-icon name="upload" :size="16" /> Impor CSV</button>@endlead
            @manage<button class="btn btn-primary btn-sm" data-modal-open="asset-modal"><x-icon name="plus" :size="16" /> Tambah Aset</button>@endmanage
        </div>
    </div>

    <form method="GET" class="card-body filters" style="padding-bottom:0" data-autosubmit>
        <input type="hidden" name="unit" value="{{ $unit->id }}">
        @if ($f['status'] !== 'aktif')<input type="hidden" name="status" value="{{ $f['status'] }}">@endif
        <div class="field grow" style="min-width:220px"><label class="label" for="q">Cari</label>
            <div class="input-icon"><x-icon name="search" :size="16" /><input class="input" id="q" name="q" value="{{ $f['q'] }}" placeholder="Kode, nama, nomor seri, pemegang, lokasi…" data-no-auto></div></div>
        <div class="field"><label class="label" for="category">Kategori</label>
            <select class="select" id="category" name="category"><option value="">Semua</option>
                @foreach ($categories as $c)<option value="{{ $c->id }}" @selected($f['category'] == $c->id)>{{ $c->name }} ({{ $c->assets_count }})</option>@endforeach</select></div>
        <div class="field"><label class="label" for="perhatian">Perlu perhatian</label>
            <select class="select" id="perhatian" name="perhatian"><option value="">Semua</option>
                <option value="servis" @selected($f['perhatian'] === 'servis')>Servis ≤ {{ config('inventaris.due_soon_days') }} hari</option>
                <option value="garansi" @selected($f['perhatian'] === 'garansi')>Garansi/lisensi habis</option>
                <option value="rusak" @selected($f['perhatian'] === 'rusak')>Kondisi tidak baik</option>
                <option value="pemegang" @selected($f['perhatian'] === 'pemegang')>Pemegang sudah nonaktif</option>
            </select></div>
    </form>

    @if ($assets->isEmpty())
        <x-empty icon="laptop" title="Belum ada aset">{{ $categories->isEmpty() ? 'Buat kategori aset di menu Kategori, lalu tambahkan asetnya.' : 'Tidak ada aset yang cocok dengan filter ini.' }}</x-empty>
    @else
        <div class="table-wrap mt-4"><table class="table">
            <thead><tr><th>Aset</th><th>Kategori</th><th>Status</th><th>Pemegang</th><th>Lokasi</th><th>Servis / garansi</th></tr></thead>
            <tbody>@foreach ($assets as $a)
                <tr>
                    <td style="max-width:300px"><a class="mono strong" href="{{ route('assets.show', $a) }}">{{ $a->code }}</a>
                        <div class="truncate text-sm">{{ $a->name }}</div>
                        @if ($a->brand || $a->serial_number)<div class="text-xs muted truncate">{{ trim($a->brand.' '.$a->model) }}{{ $a->serial_number ? ' · SN '.$a->serial_number : '' }}</div>@endif</td>
                    <td class="text-sm">{{ $a->category->name }}</td>
                    <td><x-badge :tone="$a->statusTone()">{{ $a->statusLabel() }}</x-badge>
                        @if ($a->condition !== 'baik')<div class="mt-1"><x-badge :tone="$a->conditionTone()" plain>{{ $a->conditionLabel() }}</x-badge></div>@endif</td>
                    <td class="text-sm">{{ $a->holder->name ?? '-' }}@if ($a->holder && ! $a->holder->is_active)<div class="text-xs danger-text">Nonaktif</div>@endif</td>
                    <td class="text-sm">{{ $a->location->name ?? '-' }}</td>
                    <td class="text-sm nowrap">
                        @if ($a->next_service_at)<div class="{{ $a->isServiceDue() ? ($a->next_service_at->isPast() ? 'danger-text strong' : 'warn-text') : 'muted' }}">Servis {{ $a->next_service_at->translatedFormat('d M Y') }}</div>@endif
                        @if ($a->due_date)<div class="{{ $a->isDueSoon() ? ($a->due_date->isPast() ? 'danger-text strong' : 'warn-text') : 'muted' }}">Garansi {{ $a->due_date->translatedFormat('d M Y') }}</div>@endif
                        @if (! $a->next_service_at && ! $a->due_date)<span class="faint">-</span>@endif
                    </td>
                </tr>
            @endforeach</tbody></table></div>
        {{ $assets->links() }}
    @endif
</div>
@endsection

@push('modals')
@manage
    <x-form-modal id="asset-modal" create-title="Tambah Aset" edit-title="Ubah Aset" :action="route('assets.store')" size="lg" :files="true">
        <input type="hidden" name="unit_id" value="{{ $unit->id }}">
        @include('assets._form')
    </x-form-modal>
    @if (request()->boolean('tambah') && ! old('_modal'))
        <span hidden data-open-on-load="asset-modal"></span>
    @endif
    @lead<x-import-modal :action="route('assets.import')" :template="route('assets.template')" title="Impor Aset dari CSV"
                    columns="category, code, name, brand, model, serial_number, status, condition, holder_nik, location, purchase_date, vendor, due_date, next_service_at, service_interval_months, specs, notes" />@endlead
@endmanage
@endpush

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
    // Import modal mengirim unit aktif; "?tambah=1" (dari tombol di header) langsung membuka form tambah.
    (function () {
        var f = document.querySelector('#import-modal form');
        if (f && !f.querySelector('[name=unit_id]')) { var i = document.createElement('input'); i.type = 'hidden'; i.name = 'unit_id'; i.value = '{{ $unit->id }}'; f.appendChild(i); }
        var auto = document.querySelector('[data-open-on-load]');
        if (auto) { var b = document.querySelector('[data-modal-open="asset-modal"]'); if (b) { b.click(); } }
    })();
</script>
@endpush
