<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex, nofollow">
    <title>Stiker QR · {{ config('inventaris.name') }}</title>
    @include('partials.head-icons')
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    <script src="{{ asset('js/vendor/qrcode-generator-1.4.4.js') }}"></script>
    <script src="{{ asset('js/print.js') }}?v={{ filemtime(public_path('js/print.js')) }}" defer></script>
    <style>
        body{display:block;-webkit-print-color-adjust:exact;print-color-adjust:exact;background:#fff;padding:20px;max-width:210mm;margin:0 auto;color:#0F1B2D}
        .noprint{display:grid;gap:10px;margin-bottom:16px}
        .qr-sheet{display:grid;grid-template-columns:repeat(3,1fr);gap:4mm}
        .qr-lbl{display:grid;grid-template-rows:auto 1fr;gap:2mm;border:1px dashed #B8C4D8;border-radius:3mm;padding:2.6mm 3mm 3mm;height:42mm;break-inside:avoid;overflow:hidden}
        .qr-head{display:flex;align-items:center;gap:1.6mm;padding-bottom:1.6mm;border-bottom:.3mm solid #E3E8F0;min-width:0}
        .qr-head img{height:5.2mm;width:auto;display:block;flex:none}
        .qr-head .sep{width:.3mm;height:4.4mm;background:#D5DCE8;flex:none}
        .qr-head .app{font-size:7pt;font-weight:700;color:#0B5E4E;line-height:1.1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .qr-body{display:flex;gap:2.6mm;align-items:center;min-height:0}
        .qr-lbl .qr{width:25mm;height:25mm;flex-shrink:0}
        .qr-lbl .qr svg{width:100%;height:100%;display:block}
        .qr-lbl .t{min-width:0;display:grid;gap:.6mm;line-height:1.2}
        .qr-lbl .unit{font-size:7pt;font-weight:700;color:#263A86}
        .qr-lbl .code{font-size:10.5pt;font-weight:700}
        .qr-lbl .name{font-size:8pt;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
        .qr-lbl .loc{font-size:7pt;color:#55627A;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .qr-lbl .cta{font-size:7pt;font-weight:700;color:#C2410C;margin-top:.6mm}
        @media print{.noprint{display:none} body{padding:0} @page{size:A4;margin:10mm} .qr-lbl{border-color:#D5DCE8}}
    </style>
</head>
<body data-autoprint>
    <div class="noprint">
        <div class="flex items-center justify-between wrap gap-2">
            <div><div class="strong">Stiker QR · {{ $title }}</div><div class="text-sm muted">{{ $assets->count() }} stiker · ukuran ±6×4,2 cm, 3 kolom per baris A4</div></div>
            <button type="button" class="btn btn-primary" data-print>Cetak Stiker</button>
        </div>
        @php
            $base = \App\Models\Asset::qrBaseUrl();
            $host = (string) parse_url($base, PHP_URL_HOST);
            $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
            // Nama komputer (tanpa titik, bukan IP) ikut berubah bila Inventaris pindah komputer.
            $machine = ! $local && $host !== '' && ! str_contains($host, '.') && ! filter_var($host, FILTER_VALIDATE_IP);
        @endphp
        @if ($local)
            <div class="alert alert-error" style="margin:0"><div><strong>Alamat stiker masih {{ $base }}.</strong> Alamat ini tidak bisa dibuka dari HP. Isi <code>QR_URL</code> (atau <code>APP_URL</code>) di <code>.env</code> dengan alamat yang bisa dibuka di jaringan kantor, jalankan <code>php artisan optimize:clear</code>, lalu cetak ulang.</div></div>
        @elseif ($machine && ! config('inventaris.qr_url'))
            <div class="alert alert-warning" style="margin:0"><div><strong>Stiker memakai nama komputer ({{ $host }}).</strong> Bila Inventaris nanti pindah ke komputer atau server lain, stiker yang sudah ditempel tidak bisa dibuka. Sebelum mencetak banyak, isi <code>QR_URL</code> di <code>.env</code> dengan alamat tetap (IP tetap atau nama domain kantor).</div></div>
        @else
            <div class="alert alert-info" style="margin:0"><div>Memindai QR membuka halaman <strong>cek aset</strong> di <strong>{{ $base }}</strong> tanpa login{{ $halodek ? ', dengan tombol lapor kendala ke HaloDek' : '' }}. Pastikan alamat ini bisa dibuka dari HP.</div></div>
        @endif
    </div>

    @if ($assets->isEmpty())
        <p class="muted">Tidak ada aset aktif untuk dicetak.</p>
    @else
        <div class="qr-sheet">
            @foreach ($assets as $item)
                <div class="qr-lbl">
                    <div class="qr-head">
                        <img src="{{ asset('images/logo-pcs.png') }}" alt="{{ config('inventaris.company') }}">
                        <span class="sep"></span>
                        <img src="{{ asset('images/inventaris-icon.svg') }}" alt="">
                        <span class="app">{{ config('inventaris.full_name') }}</span>
                    </div>
                    <div class="qr-body">
                        <div class="qr" data-qr="{{ $item->verifyUrl() }}" role="img" aria-label="QR {{ $item->code }}"></div>
                        <div class="t">
                            <span class="unit">Regu {{ $item->unit->name ?? '' }}</span>
                            <span class="code">{{ $item->code }}</span>
                            <span class="name">{{ $item->name }}</span>
                            @if ($item->location)<span class="loc">{{ $item->location->name }}</span>@endif
                            <span class="cta">Scan untuk cek aset</span>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <script nonce="{{ $cspNonce ?? '' }}">
        document.querySelectorAll('[data-qr]').forEach(function (el) {
            var q = qrcode(0, 'M');
            q.addData(el.getAttribute('data-qr'));
            q.make();
            el.innerHTML = q.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
        });
    </script>
</body>
</html>
