@extends('layouts.app')
@section('title', 'Beranda')

@section('content')
    @php
        $u = request()->user()->unitIds();
    @endphp
    @include('partials.hero', ['metrics' => [
        ['value' => $stats['total'], 'label' => 'Aset Aktif', 'href' => route('assets.index')],
        ['value' => $stats['dipinjam'], 'label' => 'Sedang Dipinjam', 'href' => route('assets.index', ['status' => 'dipinjam'])],
        ['value' => $stats['servis'], 'label' => 'Servis ≤ '.config('inventaris.due_soon_days').' hari', 'href' => route('assets.index', ['perhatian' => 'servis']), 'warn' => $stats['servis'] > 0],
        ['value' => $stats['menipis'], 'label' => 'Stok Menipis', 'href' => route('consumables.index', ['filter' => 'menipis']), 'warn' => $stats['menipis'] > 0],
    ], 'bar' => collect(config('inventaris.statuses'))->except('nonaktif')->map(fn ($s, $k) => [
        'label' => $s['label'], 'value' => (int) ($byStatus[$k] ?? 0), 'tone' => $s['tone'], 'href' => route('assets.index', ['status' => $k]),
    ])->values()->all()])

    <div class="grid cols-main mt-5" style="align-items:start">
        {{-- ===== PERLU PERHATIAN ===== --}}
        <div class="card">
            <div class="card-head"><h2>Perlu Perhatian</h2></div>
            @php $none = collect($attention)->every(fn ($c) => $c->isEmpty()); @endphp
            @if ($none)
                <x-empty icon="check-circle" title="Semua aman">Tidak ada servis, garansi, pinjaman, stok, atau laporan kendala yang perlu ditindaklanjuti.</x-empty>
            @else
                <ul class="attn-list">
                    @foreach ($attention['overdueLoans'] as $m)
                        <li><span class="attn-ico tone-red"><x-icon name="clock" :size="16" /></span>
                            <a href="{{ route('assets.show', $m->asset_id) }}"><strong>{{ $m->asset->code }}</strong> {{ $m->asset->name }}</a>
                            <span class="attn-meta">Pinjaman {{ $m->to_holder_name }} lewat tenggat {{ $m->due_back_at->translatedFormat('d M') }}</span></li>
                    @endforeach
                    @foreach ($attention['reports'] as $group)
                        @php $r = $group->first(); @endphp
                        <li><span class="attn-ico tone-orange"><x-icon name="alert" :size="16" /></span>
                            <a href="{{ route('assets.show', $r->asset_id) }}"><strong>{{ $r->asset->code }}</strong> {{ $r->asset->name }}</a>
                            <span class="attn-meta">{{ $group->count() > 1 ? $group->count().' laporan kendala aktif, terbaru '.$r->report_number : 'Laporan kendala '.$r->report_number }} · {{ $r->status_label }}</span></li>
                    @endforeach
                    @foreach ($attention['inactiveHolders'] as $a)
                        <li><span class="attn-ico tone-red"><x-icon name="user" :size="16" /></span>
                            <a href="{{ route('assets.show', $a) }}"><strong>{{ $a->code }}</strong> {{ $a->name }}</a>
                            <span class="attn-meta">Dipegang {{ $a->holderName() }} yang sudah nonaktif. Alihkan atau tarik ke gudang.</span></li>
                    @endforeach
                    @foreach ($attention['service'] as $a)
                        <li><span class="attn-ico tone-amber"><x-icon name="wrench" :size="16" /></span>
                            <a href="{{ route('assets.show', $a) }}"><strong>{{ $a->code }}</strong> {{ $a->name }}</a>
                            <span class="attn-meta">Servis {{ $a->next_service_at->isPast() ? 'terlewat sejak' : 'jatuh tempo' }} {{ $a->next_service_at->translatedFormat('d M Y') }}</span></li>
                    @endforeach
                    @foreach ($attention['expiring'] as $a)
                        <li><span class="attn-ico tone-violet"><x-icon name="calendar" :size="16" /></span>
                            <a href="{{ route('assets.show', $a) }}"><strong>{{ $a->code }}</strong> {{ $a->name }}</a>
                            <span class="attn-meta">Garansi/lisensi {{ $a->due_date->isPast() ? 'sudah berakhir' : 'berakhir' }} {{ $a->due_date->translatedFormat('d M Y') }}</span></li>
                    @endforeach
                    @foreach ($attention['lowStock'] as $c)
                        <li><span class="attn-ico tone-red"><x-icon name="box" :size="16" /></span>
                            <a href="{{ route('consumables.show', $c) }}"><strong>{{ $c->name }}</strong></a>
                            <span class="attn-meta">Sisa {{ $c->stock }} {{ $c->unit_label }} (minimum {{ $c->min_stock }})</span></li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div style="display:grid;gap:20px;min-width:0">
            {{-- ===== STOK KELUAR TERBARU ===== --}}
            <div class="card">
                <div class="card-head"><h2>Stok Keluar Terbaru</h2><a href="{{ route('consumables.index') }}" class="text-sm strong">Lihat stok</a></div>
                @if ($recentOut->isEmpty())
                    <div class="card-body muted text-sm">Belum ada stok keluar.</div>
                @else
                    <ul class="report-mini">
                        @foreach ($recentOut as $m)
                            <li><a href="{{ route('consumables.show', $m->consumable_id) }}">
                                <span class="rm-top"><span class="strong truncate">{{ $m->consumable->name }}</span><span class="nowrap">{{ abs($m->qty) }} {{ $m->consumable->unit_label }}</span></span>
                                @if ($m->note)<span class="rm-meta truncate">{{ $m->note }}</span>@endif
                                <span class="rm-meta">{{ $m->created_at->diffForHumans() }}</span></a></li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @if ($units->count() > 1)
                <div class="card">
                    <div class="card-head"><h2>Per Regu</h2></div>
                    <div class="card-body" style="display:grid;gap:10px">
                        @foreach ($units as $row)
                            <a class="flex items-center justify-between gap-3" href="{{ route('assets.index', ['unit' => $row['unit']->id]) }}" style="color:inherit">
                                <x-unit-chip :unit="$row['unit']" />
                                <span class="text-sm muted">{{ $row['assets'] }} aset · {{ $row['stock'] }} jenis stok</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
@endsection
