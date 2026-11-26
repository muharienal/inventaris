@php
    $hh = (int) now()->format('G');
    $hello = $hh < 11 ? 'Selamat pagi' : ($hh < 15 ? 'Selamat siang' : ($hh < 18 ? 'Selamat sore' : 'Selamat malam'));
    $who = \Illuminate\Support\Str::words(auth()->user()->name, 1, '');
@endphp
{{-- Ringkasan beranda: sapaan + angka utama dalam satu panel bersekat. --}}
<section class="summary">
    <div class="summary-head">
        <h1>{{ $hello }}, {{ $who }}</h1>
        <span class="muted">{{ now()->translatedFormat('l, d F Y') }}</span>
    </div>
    <div class="summary-cells">
        @foreach ($metrics as $m)
            <a href="{{ $m['href'] ?? '#' }}" class="cell {{ ! empty($m['warn']) ? 'warn' : '' }}">
                <span class="cell-n">{{ $m['value'] }}</span>
                <span class="cell-l">{{ $m['label'] }}</span>
            </a>
        @endforeach
    </div>
    @if (! empty($bar) && ($barTotal = array_sum(array_column($bar, 'value'))) > 0)
        {{-- Komposisi status aset: satu batang bertumpuk + keterangan yang bisa diklik. --}}
        <div class="summary-bar">
            <div class="sbar" role="img" aria-label="Komposisi status aset">
                @foreach ($bar as $seg)
                    @if ($seg['value'] > 0)<span class="seg seg-{{ $seg['tone'] }}" style="flex: {{ $seg['value'] }}" title="{{ $seg['label'] }}: {{ $seg['value'] }}"></span>@endif
                @endforeach
            </div>
            <div class="sbar-legend">
                @foreach ($bar as $seg)
                    @if ($seg['value'] > 0)<a href="{{ $seg['href'] }}"><i class="seg-{{ $seg['tone'] }}"></i>{{ $seg['label'] }} <b>{{ $seg['value'] }}</b> <span class="faint">{{ round($seg['value'] / $barTotal * 100) }}%</span></a>@endif
                @endforeach
            </div>
        </div>
    @endif
</section>
