@php
    $active = $asset->isActive();
    $photo = $asset->photos->first();
    $brand = (string) $asset->brandModel();
    $report = $active ? $asset->haloDekUrl() : null;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Cek Aset {{ $asset->code }} · {{ config('inventaris.name') }}</title>
    @include('partials.head-icons')
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body class="check-body">
<main class="check-wrap">
    <header class="check-top">
        <span class="auth-co"><img src="{{ asset('images/logo-pcs.png') }}" alt="{{ config('inventaris.company') }}"></span>
        <span class="auth-sep" aria-hidden="true"></span>
        <img class="check-logo" src="{{ asset('images/inventaris-icon.svg') }}" alt="">
        <span class="check-brand"><b>Inventaris</b><small>Umum &amp; IT · {{ config('inventaris.company') }}</small></span>
    </header>

    @if ($active)
        <section class="check-verdict is-ok" role="status">
            <x-icon name="check-circle" :size="28" />
            <div><h1>Aset terdaftar</h1>
                <p>Barang ini tercatat resmi di Inventaris. Cocokkan kode, merek, dan nomor seri di bawah dengan barang fisiknya.</p></div>
        </section>
    @else
        <section class="check-verdict is-warn" role="status">
            <x-icon name="alert" :size="28" />
            <div><h1>Aset sudah tidak aktif</h1>
                <p>Barang ini pernah terdaftar, tetapi sudah dinonaktifkan. Bila barangnya masih dipakai, hubungi tim Umum &amp; IT.</p></div>
        </section>
    @endif

    <section class="card check-card">
        <div class="check-id">
            @if ($photo)
                <img class="check-photo" src="{{ route('assets.check.photo', ['asset' => $asset->id, 'token' => $token, 'photo' => $photo->id]) }}" alt="Foto {{ $asset->name }}">
            @endif
            <div>
                <div class="check-code mono">{{ $asset->code }}</div>
                <div class="check-name">{{ $asset->name }}</div>
            </div>
        </div>

        <dl class="check-list">
            <div><dt>Merek / model</dt><dd>{{ $brand !== '' ? $brand : '-' }}</dd></div>
            <div><dt>Nomor seri</dt><dd class="mono">{{ $asset->serial_number ?: '-' }}</dd></div>
            <div><dt>Kategori</dt><dd>{{ $asset->category->name ?? '-' }}</dd></div>
            <div><dt>Regu pengelola</dt><dd><x-unit-chip :unit="$asset->unit" /></dd></div>
            <div><dt>Lokasi</dt><dd>{{ $asset->location?->fullName() ?? '-' }}</dd></div>
            <div><dt>Pemegang</dt><dd>{{ $asset->holderName() ?? '-' }}</dd></div>
            <div><dt>Status</dt><dd><x-badge :tone="$asset->statusTone()">{{ $asset->statusLabel() }}</x-badge></dd></div>
            <div><dt>Kondisi</dt><dd><x-badge :tone="$asset->conditionTone()" plain>{{ $asset->conditionLabel() }}</x-badge></dd></div>
            <div><dt>Terdaftar sejak</dt><dd>{{ $asset->created_at?->translatedFormat('d M Y') ?? '-' }}</dd></div>
            @if ($asset->due_date)<div><dt>Garansi / lisensi s.d.</dt><dd>{{ $asset->due_date->translatedFormat('d M Y') }}</dd></div>@endif
            <div><dt>Servis / perbaikan terakhir</dt><dd>{{ $lastService ? $lastService->typeLabel().', '.$lastService->completed_at?->translatedFormat('d M Y') : 'Belum ada' }}</dd></div>
        </dl>

        @if ($repairing || $openReports)
            <p class="check-note"><x-icon name="wrench" :size="16" /> {{ $repairing ? 'Sedang dalam perbaikan.' : 'Ada '.$openReports.' laporan kendala yang belum selesai.' }}</p>
        @endif
    </section>

    @if ($report || $canOpen)
        <div class="check-actions">
            @if ($canOpen)<a class="btn btn-secondary btn-lg" href="{{ route('assets.show', $asset) }}"><x-icon name="eye" :size="18" /> Buka di Inventaris</a>@endif
            @if ($report)<a class="btn btn-primary btn-lg" href="{{ $report }}"><x-icon name="alert" :size="18" /> Laporkan Kendala</a>@endif
        </div>
    @endif

    <footer class="check-foot">Dicek {{ now()->translatedFormat('d M Y, H:i') }} · IT {{ config('inventaris.company') }}</footer>
</main>
</body>
</html>
