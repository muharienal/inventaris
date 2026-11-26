@extends('layouts.app')
@section('title', 'Beranda')

@section('content')
    @include('partials.hero', ['metrics' => [
        ['value' => $assets->count(), 'label' => 'Barang yang Saya Pegang', 'href' => '#barang-saya'],
        ['value' => $loans->count(), 'label' => 'Sedang Dipinjam', 'href' => '#barang-saya', 'warn' => $loans->contains(fn ($l) => $l->isOverdue())],
        ['value' => $requests->where('status', 'menunggu')->count(), 'label' => 'Permintaan Menunggu', 'href' => route('requests.index')],
        ['value' => $requests->where('status', 'disetujui')->count(), 'label' => 'Permintaan Disetujui', 'href' => route('requests.index')],
    ]])

    <div class="grid cols-main mt-5" style="align-items:start">
        <div class="card" id="barang-saya">
            <div class="card-head"><h2>Barang yang Saya Pegang</h2></div>
            @if ($assets->isEmpty())
                <x-empty icon="laptop" title="Belum ada barang">Barang inventaris yang diserahkan atau dipinjamkan kepada Anda akan tampil di sini.</x-empty>
            @else
                <ul class="report-mini">
                    @foreach ($assets as $a)
                        @php $loan = $loans[$a->id] ?? null; @endphp
                        <li><div class="asset-mini">
                            <a href="{{ route('assets.show', $a) }}" class="grow" style="color:inherit;min-width:0">
                                <span class="rm-top"><span class="mono strong">{{ $a->code }}</span>
                                    @if ($loan)<x-badge :tone="$loan->isOverdue() ? 'red' : 'violet'">{{ $loan->isOverdue() ? 'Lewat tenggat' : 'Dipinjam' }}</x-badge>@else<x-badge :tone="$a->conditionTone()" plain>{{ $a->conditionLabel() }}</x-badge>@endif</span>
                                <span class="rm-title truncate" style="display:block">{{ $a->name }}</span>
                                <span class="rm-meta">{{ $a->unit->name }} · {{ $a->location->name ?? 'Tanpa lokasi' }}@if ($loan) · kembalikan {{ $loan->due_back_at?->translatedFormat('d M Y') }}@endif</span>
                            </a>
                            @if ($a->haloDekUrl())<a class="btn btn-secondary btn-sm" href="{{ $a->haloDekUrl() }}" target="_blank" rel="noopener"><x-icon name="alert" :size="15" /> Lapor kendala</a>@endif
                        </div></li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="card">
            <div class="card-head"><h2>Permintaan Saya</h2><a href="{{ route('requests.index', ['minta' => 1]) }}" class="text-sm strong">+ Minta barang</a></div>
            @if ($requests->isEmpty())
                <div class="card-body muted text-sm">Butuh ATK, tinta, atau barang habis pakai lain? Tekan <strong>Minta barang</strong>.</div>
            @else
                <ul class="report-mini">
                    @foreach ($requests as $r)
                        <li><a href="{{ route('requests.index') }}">
                            <span class="rm-top"><span class="strong">{{ $r->qty }} {{ $r->consumable->unit_label }}</span><x-badge :tone="$r->statusTone()">{{ $r->statusLabel() }}</x-badge></span>
                            <span class="rm-title truncate">{{ $r->consumable->name }}</span>
                            <span class="rm-meta">{{ $r->created_at->diffForHumans() }}</span></a></li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@endsection
