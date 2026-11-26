@extends('layouts.app')
@section('title', $queue ? 'Permintaan Stok' : 'Permintaan Saya')
@section('subtitle', $queue ? 'Setujui atau tolak permintaan barang habis pakai dari karyawan' : 'Minta ATK, tinta, atau barang habis pakai lain ke pengelola')

@section('content')
<div class="grid {{ $queue ? 'cols-main-r' : 'cols-main' }}" style="align-items:start">
    @if ($queue)
        <div class="card">
            <div class="card-head" style="flex-wrap:wrap">
                <div class="tabs">
                    @foreach (['menunggu' => 'Menunggu', 'disetujui' => 'Disetujui', 'ditolak' => 'Ditolak', 'semua' => 'Semua'] as $k => $l)
                        <a class="tab {{ $status === $k ? 'active' : '' }}" href="{{ route('requests.index', ['status' => $k]) }}">{{ $l }}</a>
                    @endforeach
                </div>
            </div>
            @if ($queue->isEmpty())
                <x-empty icon="check-circle" title="Tidak ada permintaan">Belum ada permintaan dengan status ini.</x-empty>
            @else
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>Peminta</th><th>Barang</th><th>Jumlah</th><th>Status</th><th></th></tr></thead>
                    <tbody>@foreach ($queue as $r)
                        <tr>
                            <td><div class="strong">{{ $r->requester_name }}</div><div class="text-xs muted">{{ $r->created_at->translatedFormat('d M Y H:i') }}</div>
                                @if ($r->note)<div class="text-xs muted">“{{ $r->note }}”</div>@endif</td>
                            <td><a href="{{ route('consumables.show', $r->consumable) }}">{{ $r->consumable->name }}</a>
                                <div class="text-xs muted">Stok: {{ $r->consumable->stock }} {{ $r->consumable->unit_label }}</div></td>
                            <td class="strong">{{ $r->qty }} {{ $r->consumable->unit_label }}</td>
                            <td><x-badge :tone="$r->statusTone()">{{ $r->statusLabel() }}</x-badge>
                                @if ($r->decision_note)<div class="text-xs muted mt-1">{{ $r->decision_note }}</div>@endif</td>
                            <td>@if ($r->status === 'menunggu')<div class="actions">
                                <button class="btn btn-success btn-sm" data-modal-open="approve-modal" data-action="{{ route('requests.approve', $r) }}" data-fill="{{ json_encode(['qty' => $r->qty]) }}" data-title="Setujui: {{ $r->qty }} {{ $r->consumable->unit_label }} {{ $r->consumable->name }}" @disabled($r->consumable->stock <= 0)>Setujui</button>
                                <button class="btn btn-soft-danger btn-sm" data-modal-open="reject-modal" data-action="{{ route('requests.reject', $r) }}" data-fill="{}">Tolak</button>
                            </div>@endif</td>
                        </tr>
                    @endforeach</tbody></table></div>
                {{ $queue->links() }}
            @endif
        </div>
    @endif

    <div style="display:grid;gap:20px;min-width:0">
        <div class="card" id="minta">
            <div class="card-head"><h2>Minta Barang</h2></div>
            <form method="POST" action="{{ route('requests.store') }}" class="card-body" style="display:grid;gap:14px">
                @csrf
                @if ($catalog->isEmpty())
                    <p class="muted text-sm" style="margin:0">Belum ada barang habis pakai yang tersedia.</p>
                @else
                    <x-select name="consumable_id" id="req-item" label="Barang" required>
                        <option value="">— Pilih barang —</option>
                        @foreach ($catalog as $unitName => $items)
                            <optgroup label="{{ $unitName }}">
                                @foreach ($items as $c)<option value="{{ $c->id }}" @selected(old('consumable_id') == $c->id)>{{ $c->name }} ({{ $c->stock > 0 ? 'tersedia' : 'stok habis' }})</option>@endforeach
                            </optgroup>
                        @endforeach
                    </x-select>
                    <x-input name="qty" type="number" label="Jumlah" required min="1" max="10000" value="1" />
                    <x-input name="note" label="Keperluan" maxlength="500" placeholder="Mis. untuk cetak laporan bulanan" />
                    <button class="btn btn-primary"><x-icon name="send" :size="16" /> Kirim Permintaan</button>
                @endif
            </form>
        </div>

        <div class="card">
            <div class="card-head"><h2>Riwayat Permintaan Saya</h2></div>
            @if ($mine->isEmpty())
                <div class="card-body muted text-sm">Belum ada permintaan.</div>
            @else
                <ul class="report-mini">
                    @foreach ($mine as $r)
                        <li><div class="asset-mini">
                            <div class="grow">
                                <span class="rm-top"><span class="strong">{{ $r->qty }} {{ $r->consumable->unit_label }} {{ $r->consumable->name }}</span><x-badge :tone="$r->statusTone()">{{ $r->statusLabel() }}</x-badge></span>
                                <span class="rm-meta">{{ $r->created_at->translatedFormat('d M Y') }} · {{ $r->consumable->unit->name }}@if ($r->decision_note) · {{ $r->decision_note }}@endif</span>
                            </div>
                            @if ($r->status === 'menunggu')
                                <form method="POST" action="{{ route('requests.cancel', $r) }}" data-confirm="Batalkan permintaan ini?" data-confirm-title="Batalkan permintaan" data-confirm-ok="Batalkan">@csrf @method('PATCH')<button class="btn btn-ghost btn-sm">Batalkan</button></form>
                            @endif
                        </div></li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
@endsection

@push('modals')
    @if ($queue)
        <x-form-modal id="approve-modal" create-title="Setujui Permintaan" edit-title="Setujui Permintaan" :action="route('requests.index')" submit="Setujui &amp; Kurangi Stok">
            <div style="display:grid;gap:14px">
                <x-input name="qty" id="approve-qty" type="number" label="Jumlah yang diberikan" required min="1" hint="Boleh lebih kecil dari permintaan bila stok terbatas." />
                <x-input name="decision_note" id="approve-note" label="Catatan untuk peminta" maxlength="500" placeholder="Mis. ambil di ruang Kesekretariatan" />
            </div>
        </x-form-modal>
        <x-form-modal id="reject-modal" create-title="Tolak Permintaan" edit-title="Tolak Permintaan" :action="route('requests.index')" submit="Tolak">
            <x-textarea name="decision_note" id="reject-note" label="Alasan" required rows="3" maxlength="500" placeholder="Mis. stok habis, sudah diajukan pembelian" />
        </x-form-modal>
    @endif
@endpush

@if (request()->boolean('minta'))
    @push('scripts')
    <script nonce="{{ $cspNonce ?? '' }}">(function () { var el = document.getElementById('req-item'); if (el) { el.scrollIntoView({ block: 'center' }); el.focus(); } })();</script>
    @endpush
@endif
