@extends('layouts.app')
@section('title', $asset->code)
@section('subtitle', $asset->name)

@section('content')
@php
    $rp = fn ($v) => $v === null ? '-' : 'Rp '.number_format((float) $v, 0, ',', '.');
    $fill = [
        'category_id' => $asset->category_id, 'code' => $asset->code, 'name' => $asset->name, 'brand' => $asset->brand, 'model' => $asset->model,
        'serial_number' => $asset->serial_number, 'specs' => $asset->specs, 'status' => $asset->status, 'condition' => $asset->condition,
        'purchase_date' => $asset->purchase_date?->toDateString(),
        'vendor' => $asset->vendor, 'due_date' => $asset->due_date?->toDateString(),
        'next_service_at' => $asset->next_service_at?->toDateString(), 'service_interval_months' => $asset->service_interval_months, 'notes' => $asset->notes,
    ];
@endphp

<div class="detail-head mb-4">
    <div class="detail-nav">
        <a href="{{ route('assets.index', ['unit' => $asset->unit_id]) }}" class="btn btn-ghost btn-sm"><x-icon name="arrow-left" :size="16" /> Semua Aset</a>
    </div>
    <div class="detail-panel">
        <dl class="meta-list">
            <div><dt>Status</dt><dd><x-badge :tone="$asset->statusTone()">{{ $asset->statusLabel() }}</x-badge></dd></div>
            <div><dt>Kondisi</dt><dd><x-badge :tone="$asset->conditionTone()" plain>{{ $asset->conditionLabel() }}</x-badge></dd></div>
            <div><dt>Unit</dt><dd><x-unit-chip :unit="$asset->unit" /></dd></div>
            @if ($openReports)<div><dt>Laporan kendala</dt><dd><span class="note-flag is-orange"><x-icon name="alert" :size="14" /> {{ $openReports }} masih aktif</span></dd></div>@endif
        </dl>
        <div class="detail-actions" role="group" aria-label="Tindakan">
            <a class="btn btn-secondary btn-sm" href="{{ route('assets.qr', ['asset' => $asset->id]) }}" target="_blank" rel="noopener"><x-icon name="qr" :size="16" /> Cetak QR</a>
            @if ($canManage)
                <button type="button" class="btn btn-primary btn-sm" data-modal-open="asset-modal" data-action="{{ route('assets.update', $asset) }}" data-fill="{{ json_encode($fill) }}"><x-icon name="edit" :size="16" /> Ubah</button>
            @endif
        </div>
    </div>
</div>

<div class="grid cols-main-r asset-layout" style="align-items:start">
    <div class="asset-col">
        {{-- ===== RINGKASAN ===== --}}
        <div class="card o-1">
            <div class="card-head"><h2>Detail Aset</h2><span class="text-sm muted">{{ $asset->category->name }}</span></div>
            <div class="card-body" style="display:grid;gap:18px">
                @if ($asset->photos->isNotEmpty() || $canManage)
                    <div class="photo-strip">
                        @foreach ($asset->photos as $p)
                            <div class="photo-item">
                                <a href="{{ route('assets.photo', $p) }}" target="_blank" rel="noopener"><img src="{{ route('assets.photo', $p) }}" alt="Foto {{ $asset->code }}" loading="lazy"></a>
                                @if ($canManage)
                                    <form method="POST" action="{{ route('assets.photos.destroy', $p) }}" data-confirm="Hapus foto ini?" data-confirm-title="Hapus foto">@csrf @method('DELETE')
                                        <button class="photo-del" aria-label="Hapus foto"><x-icon name="x" :size="14" /></button></form>
                                @endif
                            </div>
                        @endforeach
                        @if ($canManage && $asset->photos->count() < config('inventaris.photos.max_files'))
                            <form method="POST" action="{{ route('assets.photos.store', $asset) }}" enctype="multipart/form-data" class="photo-add" data-autosubmit-file>
                                @csrf
                                <label><x-icon name="camera" :size="22" /><span>Tambah foto</span>
                                    <input type="file" name="photos[]" multiple accept="image/jpeg,image/png,image/webp" class="sr-only" data-file-input data-max-files="{{ config('inventaris.photos.max_files') - $asset->photos->count() }}" data-max-kb="{{ config('inventaris.photos.max_kb') }}"></label>
                            </form>
                        @endif
                    </div>
                @endif

                <dl class="kv-grid">
                    <div><dt>Merek / model</dt><dd>{{ trim($asset->brand.' '.$asset->model) ?: '-' }}</dd></div>
                    <div><dt>Nomor seri</dt><dd>{{ $asset->serial_number ?: '-' }}</dd></div>
                    <div><dt>Didaftarkan</dt><dd>{{ $asset->created_at->translatedFormat('d M Y') }}</dd></div>
                </dl>
                @if ($asset->specs)<div><div class="text-xs muted mb-1">Spesifikasi</div><div class="pre-line text-sm">{{ $asset->specs }}</div></div>@endif
                @if ($asset->notes)<div><div class="text-xs muted mb-1">Catatan</div><div class="pre-line text-sm">{{ $asset->notes }}</div></div>@endif
            </div>
        </div>

        {{-- ===== RIWAYAT TERPADU ===== --}}
        <div class="card o-5">
            <div class="card-head"><h2>Riwayat</h2><span class="text-xs muted">Mutasi, servis, dan laporan kendala</span></div>
            <div class="card-body">
                @if ($timeline->isEmpty())
                    <p class="muted text-sm" style="margin:0">Belum ada riwayat.</p>
                @else
                    <ul class="timeline">
                        @foreach ($timeline as $e)
                            @php $i = $e['item']; @endphp
                            @if ($e['kind'] === 'move')
                                <li class="tl-item t-{{ $i->type === 'pinjam' ? 'claimed' : ($i->type === 'kembali' ? 'confirmed' : 'status') }}">
                                    <span class="tl-dot"><x-icon :name="['serah_terima' => 'handshake', 'pindah' => 'pin', 'pinjam' => 'clock', 'kembali' => 'check-circle', 'tarik' => 'box'][$i->type] ?? 'swap'" :size="15" /></span>
                                    <div class="flex items-center gap-2 wrap text-sm"><strong>{{ $i->typeLabel() }}</strong>
                                        <span class="faint text-xs" title="{{ $i->created_at->translatedFormat('d M Y H:i') }}">{{ $i->created_at->translatedFormat('d M Y') }} · {{ $i->user->name ?? 'Sistem' }}</span></div>
                                    <div class="text-sm muted mt-1">
                                        @if (in_array($i->type, ['serah_terima', 'pinjam'], true)){{ $i->from_holder_name ? $i->from_holder_name.' → ' : '' }}<strong>{{ $i->to_holder_name ?? '-' }}</strong>@endif
                                        @if ($i->type === 'kembali')Dikembalikan{{ $i->from_holder_name ? ' oleh '.$i->from_holder_name : '' }}@endif
                                        @if ($i->type === 'tarik')Ditarik dari {{ $i->from_holder_name ?? '-' }}@endif
                                        @if ($i->from_location_id !== $i->to_location_id && $i->toLocation) · lokasi {{ $i->fromLocation?->name ? $i->fromLocation->name.' → ' : '' }}{{ $i->toLocation->name }}@elseif ($i->type === 'pindah' && $i->toLocation) · {{ $i->toLocation->name }}@endif
                                        @if ($i->type === 'pinjam') · kembali {{ $i->due_back_at?->translatedFormat('d M Y') }}@if ($i->returned_at) <span class="ok-text">(sudah kembali {{ $i->returned_at->translatedFormat('d M') }})</span>@elseif ($i->isOverdue()) <span class="danger-text strong">(lewat tenggat)</span>@endif @endif
                                    </div>
                                    @if ($i->note)<div class="bubble pre-line text-sm">{{ $i->note }}</div>@endif
                                </li>
                            @elseif ($e['kind'] === 'service')
                                <li class="tl-item t-{{ $i->status === 'selesai' ? 'confirmed' : 'reopened' }}">
                                    <span class="tl-dot"><x-icon name="wrench" :size="15" /></span>
                                    <div class="flex items-center gap-2 wrap text-sm"><strong>{{ $i->typeLabel() }}</strong> <x-badge :tone="$i->statusTone()" plain>{{ $i->statusLabel() }}</x-badge>
                                        <span class="faint text-xs">{{ ($i->completed_at ?? $i->scheduled_at ?? $i->created_at)->translatedFormat('d M Y') }}</span></div>
                                    <div class="text-sm muted mt-1">{{ collect([$i->vendor, $i->handler_name ? 'ditangani '.$i->handler_name : null, $i->cost !== null ? $rp($i->cost) : null, $i->report_number ? 'laporan '.$i->report_number : null])->filter()->implode(' · ') ?: '' }}
                                        @if ($i->assetReport?->url) · <a href="{{ $i->assetReport->url }}" target="_blank" rel="noopener">Buka di HaloDek</a>@endif</div>
                                    @if ($i->description || $i->result)<div class="bubble pre-line text-sm">{{ trim($i->description."\n".($i->result ? 'Hasil: '.$i->result : '')) }}</div>@endif
                                </li>
                            @else
                                <li class="tl-item t-transferred">
                                    <span class="tl-dot"><x-icon name="alert" :size="15" /></span>
                                    <div class="flex items-center gap-2 wrap text-sm"><strong>Laporan kendala {{ $i->report_number }}</strong> <x-badge :tone="$i->tone()" plain>{{ $i->status_label }}</x-badge>
                                        <span class="faint text-xs">{{ $i->reported_at?->translatedFormat('d M Y') }}</span></div>
                                    <div class="text-sm mt-1">{{ $i->title }} <span class="muted">· {{ $i->reporter_name }}</span>
                                        @if ($i->url) · <a href="{{ $i->url }}" target="_blank" rel="noopener">Buka di HaloDek</a>@endif</div>
                                </li>
                            @endif
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>

    <div class="asset-col">
        {{-- ===== PEMEGANG, LOKASI & MUTASI ===== --}}
        <div class="card o-2">
            <div class="card-head"><h2>Pemegang &amp; Lokasi</h2></div>
            <div class="card-body" style="display:grid;gap:12px">
                <div class="flex justify-between gap-3 text-sm"><span class="muted">Pemegang</span>
                    <span class="text-right">{{ $asset->holder ? $asset->holder->name.' ('.$asset->holder->nik.')' : 'Tidak ada' }}</span></div>
                @if ($asset->holder && ! $asset->holder->is_active)
                    <div class="alert alert-error" style="margin:0"><x-icon name="alert" :size="18" /><div>{{ $asset->holder->name }} sudah nonaktif. Tarik aset ini ke gudang atau serahkan ke orang lain.</div></div>
                @endif
                <div class="flex justify-between gap-3 text-sm"><span class="muted">Lokasi</span>
                    <span class="text-right">{{ $asset->location?->fullName() ?? '-' }}</span></div>
                @if ($loan)
                    <div class="alert {{ $loan->isOverdue() ? 'alert-error' : 'alert-info' }}" style="margin:0"><x-icon name="clock" :size="18" />
                        <div>Dipinjam <strong>{{ $loan->to_holder_name }}</strong> sampai {{ $loan->due_back_at?->translatedFormat('d M Y') }}{{ $loan->isOverdue() ? ' (lewat tenggat)' : '' }}.</div></div>
                @endif
                @if ($canManage)
                    @if ($asset->isActive())
                        <div class="move-actions">
                            @if ($loan)
                                <button class="btn btn-primary" data-modal-open="move-modal" data-fill='{"type":"kembali"}' data-action="{{ route('assets.move', $asset) }}" data-method="POST" data-title="Terima Kembali"><x-icon name="check-circle" :size="16" /> Terima Kembali</button>
                            @else
                                <button class="btn btn-secondary" data-modal-open="move-modal" data-fill='{"type":"serah_terima"}' data-action="{{ route('assets.move', $asset) }}" data-method="POST" data-title="Serah Terima"><x-icon name="handshake" :size="16" /> Serah Terima</button>
                                <button class="btn btn-secondary" data-modal-open="move-modal" data-fill='{"type":"pinjam"}' data-action="{{ route('assets.move', $asset) }}" data-method="POST" data-title="Pinjamkan"><x-icon name="clock" :size="16" /> Pinjamkan</button>
                                <button class="btn btn-secondary" data-modal-open="move-modal" data-fill='{"type":"pindah"}' data-action="{{ route('assets.move', $asset) }}" data-method="POST" data-title="Pindah Lokasi"><x-icon name="pin" :size="16" /> Pindah Lokasi</button>
                                @if ($asset->holder_id)
                                    <button class="btn btn-secondary" data-modal-open="move-modal" data-fill='{"type":"tarik"}' data-action="{{ route('assets.move', $asset) }}" data-method="POST" data-title="Tarik ke Gudang"><x-icon name="box" :size="16" /> Tarik ke Gudang</button>
                                @endif
                            @endif
                        </div>
                    @else
                        <p class="hint" style="margin:0">Aset nonaktif tidak bisa dimutasi. Ubah statusnya dulu bila dipakai kembali.</p>
                    @endif
                @endif
            </div>
        </div>

            {{-- ===== SERVIS ===== --}}
            <div class="card o-3">
                <div class="card-head"><h2>Servis &amp; Perawatan</h2>
                    @if ($canManage)<button class="btn btn-secondary btn-sm" data-modal-open="service-modal"><x-icon name="plus" :size="15" /> Servis</button>@endif</div>
                <div class="card-body" style="display:grid;gap:12px">
                    <div class="flex justify-between gap-3 text-sm"><span class="muted">Servis berikutnya</span>
                        <span class="{{ $asset->isServiceDue() ? ($asset->next_service_at->isPast() ? 'danger-text strong' : 'warn-text strong') : '' }}">{{ $asset->next_service_at?->translatedFormat('d M Y') ?? '-' }}</span></div>
                    <div class="flex justify-between gap-3 text-sm"><span class="muted">Interval</span><span>{{ $asset->service_interval_months ? 'Tiap '.$asset->service_interval_months.' bulan' : '-' }}</span></div>
                    @foreach ($services->where('status', 'dijadwalkan') as $s)
                        <div class="svc-item">
                            @if ($s->isFromHaloDek())
                                <div><strong class="text-sm">Perbaikan</strong> <x-badge tone="blue">Sedang ditangani</x-badge>
                                    <div class="text-xs muted mt-1">Laporan {{ $s->report_number }}{{ $s->handler_name ? ' · '.$s->handler_name : '' }} · sejak {{ $s->scheduled_at?->translatedFormat('d M Y') }}</div>
                                    @if ($s->assetReport?->url)<a class="text-xs" href="{{ $s->assetReport->url }}" target="_blank" rel="noopener">Lihat di HaloDek</a>@endif</div>
                            @else
                                <div><strong class="text-sm">{{ $s->typeLabel() }}</strong> <span class="text-xs muted">· {{ $s->scheduled_at?->translatedFormat('d M Y') ?? 'belum dijadwalkan' }}{{ $s->vendor ? ' · '.$s->vendor : '' }}</span>
                                    @if ($s->description)<div class="text-xs muted">{{ \Illuminate\Support\Str::limit($s->description, 90) }}</div>@endif</div>
                                <div class="flex gap-2" @unless ($canManage) hidden @endunless>
                                    <button class="btn btn-success btn-sm" data-modal-open="service-done-modal" data-action="{{ route('assets.services.update', $s) }}" data-fill="{{ json_encode(['status' => 'selesai', 'completed_at' => today()->toDateString(), 'vendor' => $s->vendor, 'cost' => $s->cost !== null ? (int) $s->cost : null]) }}">Selesai</button>
                                    <form method="POST" action="{{ route('assets.services.update', $s) }}" data-confirm="Batalkan jadwal servis ini?" data-confirm-title="Batalkan servis" data-confirm-ok="Batalkan">@csrf @method('PATCH')<input type="hidden" name="status" value="batal"><button class="btn btn-ghost btn-sm">Batal</button></form>
                                </div>
                            @endif
                        </div>
                    @endforeach
                    @php $done = $services->where('status', 'selesai')->sortByDesc(fn ($s) => $s->completed_at?->getTimestamp() ?? 0)->take(3); @endphp
                    @if ($done->isNotEmpty())
                        <div class="svc-history">
                            <div class="text-xs muted">Servis &amp; perbaikan terakhir</div>
                            @foreach ($done as $s)
                                <div class="flex justify-between gap-3 text-sm"><span class="truncate">{{ $s->typeLabel() }}{{ $s->isFromHaloDek() ? ' · '.$s->report_number : '' }}</span><span class="nowrap">{{ $s->completed_at?->translatedFormat('d M Y') }}</span></div>
                            @endforeach
                        </div>
                    @endif
                    @php $costTotal = $services->where('status', 'selesai')->sum('cost'); @endphp
                    @if ($costTotal > 0)<div class="flex justify-between gap-3 text-sm"><span class="muted">Total biaya servis</span><span class="strong">{{ $rp($costTotal) }}</span></div>@endif
                </div>
            </div>

        {{-- ===== PEMBELIAN & GARANSI ===== --}}
        <div class="card o-4">
            <div class="card-head"><h2>Pembelian &amp; Garansi</h2></div>
            <div class="card-body" style="display:grid;gap:10px">
                <div class="flex justify-between gap-3 text-sm"><span class="muted">Tanggal beli</span><span>{{ $asset->purchase_date?->translatedFormat('d M Y') ?? '-' }}</span></div>
                <div class="flex justify-between gap-3 text-sm"><span class="muted">Vendor</span><span>{{ $asset->vendor ?: '-' }}</span></div>
                <div class="flex justify-between gap-3 text-sm"><span class="muted">Garansi / lisensi s.d.</span>
                    <span class="{{ $asset->isDueSoon() ? ($asset->due_date->isPast() ? 'danger-text strong' : 'warn-text strong') : '' }}">{{ $asset->due_date?->translatedFormat('d M Y') ?? '-' }}</span></div>
            </div>
        </div>

        @if ($canManage && auth()->user()->canLead())
            <div class="card o-6"><div class="card-body">
                <form method="POST" action="{{ route('assets.destroy', $asset) }}" data-confirm="Hapus aset {{ $asset->code }} beserta foto dan riwayatnya? Sebaiknya ubah status menjadi Nonaktif agar riwayat tetap ada." data-confirm-title="Hapus aset" data-confirm-ok="Hapus permanen">
                    @csrf @method('DELETE')<button class="btn btn-soft-danger btn-block"><x-icon name="trash" :size="16" /> Hapus Aset</button></form>
            </div></div>
        @endif
    </div>
</div>
@endsection

@push('modals')
    @if ($canManage)
        <x-form-modal id="asset-modal" create-title="Ubah Aset" edit-title="Ubah Aset" :action="route('assets.update', $asset)" size="lg">
            @include('assets._form')
        </x-form-modal>

        <x-form-modal id="move-modal" create-title="Mutasi Aset" :action="route('assets.move', $asset)" submit="Simpan Mutasi">
            <div style="display:grid;gap:14px">
                <input type="hidden" name="type" id="move-type" value="{{ old('type', 'serah_terima') }}">
                <div data-move="serah_terima pinjam"><x-input name="holder_nik" id="move-holder" label="Penerima / peminjam (NIK)" list="people-list" maxlength="80" placeholder="Ketik NIK atau nama" /></div>
                <div data-move="serah_terima pinjam pindah kembali tarik">
                    <x-select name="location_id" id="move-location" label="Lokasi">
                        <option value="" data-keep>- Tetap di lokasi sekarang -</option>
                        @foreach ($locations as $l)<option value="{{ $l->id }}">{{ $l->fullName() }}</option>@endforeach
                    </x-select>
                </div>
                <div data-move="pinjam"><x-input name="due_back_at" id="move-due" type="date" label="Batas pengembalian" :min="today()->toDateString()" /></div>
                <p class="hint" data-move="kembali" style="margin:0">Aset kembali ke pemegang sebelum dipinjam (bila ada), atau ke gudang.</p>
                <x-textarea name="note" id="move-note" label="Catatan" rows="2" maxlength="1000" placeholder="Mis. kondisi saat diserahkan, kelengkapan charger, nomor BAST" />
            </div>
        </x-form-modal>

        <x-form-modal id="service-modal" create-title="Servis &amp; Perawatan" :action="route('assets.services.store', $asset)" submit="Simpan">
            <div style="display:grid;gap:14px">
                <div class="form-grid">
                    <x-select name="type" label="Jenis" required>
                        @foreach (config('inventaris.service_types') as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                    </x-select>
                    <x-select name="status" id="svc-status" label="Status" required>
                        <option value="dijadwalkan">Dijadwalkan</option>
                        <option value="selesai">Sudah selesai</option>
                    </x-select>
                </div>
                <div data-svc="dijadwalkan"><x-input name="scheduled_at" type="date" label="Tanggal jadwal" /></div>
                <div data-svc="selesai" hidden><x-input name="completed_at" type="date" label="Tanggal selesai" :max="today()->toDateString()" /></div>
                <div class="form-grid">
                    <x-input name="vendor" label="Vendor / teknisi" maxlength="120" />
                    <x-input name="cost" type="number" label="Biaya (Rp)" min="0" step="1" />
                </div>
                <x-textarea name="description" label="Keterangan" rows="2" maxlength="2000" placeholder="Mis. cuci AC, ganti kampas rem" />
                <div data-svc="selesai" hidden><x-textarea name="result" label="Hasil" rows="2" maxlength="2000" placeholder="Mis. sudah normal, ganti kompresor" /></div>
                <p class="hint" data-svc="dijadwalkan" style="margin:0">Perbaikan yang dijadwalkan menandai aset berstatus <strong>Perbaikan</strong> sampai selesai.</p>
            </div>
        </x-form-modal>

        <x-form-modal id="service-done-modal" create-title="Selesaikan Servis" edit-title="Selesaikan Servis" :action="route('assets.update', $asset)" submit="Tandai Selesai">
            <div style="display:grid;gap:14px">
                <input type="hidden" name="status" value="selesai">
                <div class="form-grid">
                    <x-input name="completed_at" id="done-date" type="date" label="Tanggal selesai" required :max="today()->toDateString()" />
                    <x-input name="cost" id="done-cost" type="number" label="Biaya (Rp)" min="0" step="1" />
                </div>
                <x-input name="vendor" id="done-vendor" label="Vendor / teknisi" maxlength="120" />
                <x-textarea name="result" id="done-result" label="Hasil" rows="3" maxlength="2000" placeholder="Mis. sudah normal, ganti kompresor" />
            </div>
        </x-form-modal>
    @endif
@endpush

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    // Form mutasi: tampilkan kolom sesuai jenis mutasi.
    var type = document.getElementById('move-type');
    function syncMove() {
        if (!type) { return; }
        document.querySelectorAll('[data-move]').forEach(function (el) {
            var show = el.getAttribute('data-move').split(' ').indexOf(type.value) !== -1;
            el.hidden = !show;
            el.querySelectorAll('input,select').forEach(function (i) { i.disabled = !show; });
        });
        var holder = document.getElementById('move-holder'), due = document.getElementById('move-due'), loc = document.getElementById('move-location');
        if (holder) { holder.required = type.value === 'serah_terima' || type.value === 'pinjam'; }
        if (due) { due.required = type.value === 'pinjam'; }
        if (loc) {
            // Pindah lokasi: wajib pilih lokasi tujuan (pilihan "tetap" disembunyikan).
            var moving = type.value === 'pindah';
            loc.required = moving;
            var keep = loc.querySelector('[data-keep]');
            if (keep) { keep.textContent = moving ? '- Pilih lokasi tujuan -' : '- Tetap di lokasi sekarang -'; }
            var lbl = document.querySelector('label[for=move-location]');
            if (lbl) { lbl.firstChild.textContent = moving ? 'Lokasi tujuan ' : 'Lokasi '; }
        }
    }
    // Form servis: tanggal jadwal atau tanggal selesai sesuai status.
    var svc = document.getElementById('svc-status');
    function syncSvc() {
        if (!svc) { return; }
        document.querySelectorAll('[data-svc]').forEach(function (el) {
            var show = el.getAttribute('data-svc') === svc.value;
            el.hidden = !show;
            el.querySelectorAll('input,textarea').forEach(function (i) { i.disabled = !show; });
        });
    }
    if (svc) { svc.addEventListener('change', syncSvc); document.addEventListener('hu:prepared', syncSvc); syncSvc(); }
    document.addEventListener('hu:prepared', syncMove);
    syncMove();

    // Foto: unggah langsung setelah dipilih.
    document.querySelectorAll('[data-autosubmit-file] input[type=file]').forEach(function (i) {
        i.addEventListener('change', function () { if (i.files.length) { i.form.submit(); } });
    });
})();
</script>
@endpush
