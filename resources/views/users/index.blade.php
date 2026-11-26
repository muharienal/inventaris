@extends('layouts.app')
@section('title', 'Manajemen Pengguna')
@section('subtitle', 'Atur siapa yang bisa masuk Inventaris dan perannya.')

@section('content')
{{-- Data karyawan dari HaloDek --}}
<div class="card mb-4">
    <div class="card-body flex items-center justify-between gap-3 wrap">
        <div class="grow">
            <div class="strong">Data pegawai dari HaloDek <span class="text-xs muted" style="font-weight:400">· otomatis tiap 5 menit · {{ rtrim(config('inventaris.halodek_url'), '/') ?: 'HALODEK_URL belum diisi' }}</span></div>
            @if ($syncEnabled)<div class="text-sm muted">Nama, NIK, divisi, bidang, jabatan, dan No. WA diatur di HaloDek, lalu disalin ke sini.</div>@endif
            <div class="text-sm muted">
                @if (! $syncEnabled)
                    Belum tersambung. Isi <code>HALODEK_URL</code> dan <code>INVENTARIS_API_TOKEN</code> di .env (token sama dengan HaloDek).
                @elseif ($lastSync)
                    Terakhir diambil {{ \Illuminate\Support\Carbon::parse($lastSync['at'])->diffForHumans() }}:
                    <span class="{{ $lastSync['ok'] ? '' : 'danger-text' }}">{{ $lastSync['message'] }}</span>
                @else
                    Diambil otomatis tiap 5 menit, dan saat NIK pemegang belum dikenal.
                @endif
            </div>
        </div>
        @if ($syncEnabled)
            <form method="POST" action="{{ route('users.sync') }}">@csrf<button class="btn btn-secondary btn-sm"><x-icon name="refresh" :size="16" /> Ambil Sekarang</button></form>
        @endif
    </div>
</div>

<div class="card">
    <div class="card-head" style="flex-wrap:wrap">
        <div class="tabs">
            <a class="tab {{ $role === 'semua' ? 'active' : '' }}" href="{{ route('users.index', array_filter(['q' => $search, 'state' => $state])) }}">Semua <span class="n">{{ $roleCounts->sum() }}</span></a>
            @foreach (config('inventaris.roles') as $k => $label)
                <a class="tab {{ $role === $k ? 'active' : '' }}" href="{{ route('users.index', array_filter(['role' => $k, 'q' => $search, 'state' => $state])) }}">{{ $k === 'karyawan' ? 'Tanpa akses' : $label }} <span class="n">{{ $roleCounts[$k] ?? 0 }}</span></a>
            @endforeach
        </div>
        <div class="flex gap-2 wrap">
            <a class="btn btn-secondary btn-sm" href="{{ route('users.export') }}"><x-icon name="download" :size="16" /> Ekspor</a>
            @if ($syncEnabled)
                <button class="btn btn-secondary btn-sm" data-modal-open="import-modal"><x-icon name="upload" :size="16" /> Impor Akses</button>
                <button class="btn btn-primary btn-sm" data-modal-open="access-modal"><x-icon name="key" :size="16" /> Beri Akses</button>
            @else
                <button class="btn btn-primary btn-sm" data-modal-open="user-modal"><x-icon name="plus" :size="16" /> Tambah Akun</button>
            @endif
        </div>
    </div>

    <form method="GET" class="card-body filters" style="padding-bottom:0" data-autosubmit>
        <input type="hidden" name="role" value="{{ $role }}">
        <div class="field grow" style="min-width:220px"><label class="label" for="q">Cari</label><div class="input-icon"><x-icon name="search" :size="16" /><input class="input" id="q" name="q" value="{{ $search }}" placeholder="Nama, NIK, bidang…" data-no-auto></div></div>
        <div class="field"><label class="label" for="state">Status</label><select class="select" id="state" name="state"><option value="semua">Semua</option><option value="aktif" @selected($state === 'aktif')>Aktif</option><option value="nonaktif" @selected($state === 'nonaktif')>Nonaktif</option></select></div>
        <div class="field"><label class="label" for="tid">Regu</label><select class="select" id="tid" name="unit_id"><option value="">Semua</option>@foreach ($units as $t)<option value="{{ $t->id }}" @selected($unitId == $t->id)>{{ $t->name }}</option>@endforeach</select></div>
    </form>

    @if ($users->isEmpty())
        <x-empty icon="users" title="Tidak ada pengguna" />
    @else
        <div class="table-wrap mt-4"><table class="table">
            <thead><tr><th>Pengguna</th><th>NIK</th><th>Peran</th><th>Regu</th><th>Aset dipegang</th><th>Login terakhir</th><th></th></tr></thead>
            <tbody>@foreach ($users as $u)
                <tr style="{{ $u->is_active ? '' : 'opacity:.6' }}">
                    <td><div class="flex items-center gap-3"><span class="avatar sm">{{ $u->initials() }}</span>
                        <div><div class="strong">{{ $u->name }} @unless ($u->is_active)<span class="badge tone-red plain">Nonaktif</span>@endunless</div><div class="text-xs muted" title="{{ collect([$u->division, $u->department])->filter()->implode(' › ') }}">{{ collect([$u->position, $u->department])->filter()->implode(' · ') ?: '-' }}</div></div></div></td>
                    <td class="mono nowrap">{{ $u->nik }}</td>
                    <td>@if ($u->isKaryawan())<span class="text-sm faint">Tanpa akses</span>@else<x-badge :tone="match($u->role) { 'superadmin' => 'violet', 'manajer' => 'teal', default => 'blue' }" plain>{{ $u->roleLabel() }}</x-badge>@endif</td>
                    <td>@if ($u->unit)<x-unit-chip :unit="$u->unit" />@else<span class="faint">-</span>@endif</td>
                    <td>{{ $u->held_assets_count ?: '-' }}</td>
                    <td class="text-sm muted">{{ $u->isKaryawan() ? '-' : ($u->last_login_at ? $u->last_login_at->diffForHumans() : 'Belum pernah') }}@if ($u->must_change_password && ! $u->isKaryawan())<div class="text-xs" style="color:#B45309">Password awal</div>@endif</td>
                    <td><div class="actions">
                        @if ($u->isKaryawan())
                            @if ($syncEnabled && $u->is_active)
                                <button class="btn btn-secondary btn-sm" data-modal-open="access-modal" data-fill="{{ json_encode(['nik' => $u->nik.' - '.$u->name]) }}" title="Izinkan {{ $u->name }} masuk Inventaris"><x-icon name="key" :size="14" /> Beri Akses</button>
                            @endif
                        @else
                        @if ($syncEnabled)
                            <button class="btn btn-secondary btn-sm btn-icon" aria-label="Ubah akses {{ $u->name }}" title="Ubah peran / regu" data-modal-open="edit-access-modal" data-title="Ubah Akses · {{ $u->name }}" data-action="{{ route('users.update', $u) }}"
                                    data-fill="{{ json_encode(['role' => $u->role, 'unit_id' => $u->unit_id, 'is_active' => $u->is_active]) }}"><x-icon name="edit" :size="15" /></button>
                        @else
                            <button class="btn btn-secondary btn-sm btn-icon" aria-label="Ubah" data-modal-open="user-modal" data-title="Ubah Pengguna" data-action="{{ route('users.update', $u) }}"
                                    data-fill="{{ json_encode(['nik' => $u->nik, 'name' => $u->name, 'phone' => $u->phone, 'division' => $u->division, 'department' => $u->department, 'position' => $u->position, 'role' => $u->role, 'unit_id' => $u->unit_id, 'is_active' => $u->is_active]) }}"><x-icon name="edit" :size="15" /></button>
                        @endif
                        <form method="POST" action="{{ route('users.reset-password', $u) }}" data-confirm="Atur ulang password {{ $u->name }}? Password sementara akan ditampilkan sekali dan wajib diganti saat ia masuk berikutnya." data-confirm-title="Reset password" data-confirm-ok="Reset" data-confirm-tone="primary">@csrf
                            <button class="btn btn-secondary btn-sm btn-icon" aria-label="Reset password {{ $u->name }}" title="Reset password"><x-icon name="lock" :size="15" /></button></form>
                        @if ($u->id !== auth()->id())
                            {{-- Mode terpusat: aktif/nonaktif diatur di form Ubah akses, jadi tombol terpisah tidak perlu. --}}
                            @unless ($syncEnabled)
                            <form method="POST" action="{{ route('users.toggle', $u) }}" data-confirm="{{ $u->is_active ? 'Nonaktifkan' : 'Aktifkan' }} akun {{ $u->name }}?" data-confirm-title="{{ $u->is_active ? 'Nonaktifkan' : 'Aktifkan' }} akun" data-confirm-ok="Ya" data-confirm-tone="primary">@csrf @method('PATCH')
                                <button class="btn btn-secondary btn-sm btn-icon" aria-label="Aktif/nonaktif" title="{{ $u->is_active ? 'Nonaktifkan' : 'Aktifkan' }}"><x-icon name="power" :size="15" /></button></form>
                            @endunless
                            <form method="POST" action="{{ route('users.destroy', $u) }}" data-confirm="Cabut akses {{ $u->name }}? Ia tidak bisa masuk Inventaris lagi. Data diri, aset yang dipegang, dan riwayatnya tetap ada." data-confirm-title="Cabut akses" data-confirm-ok="Cabut akses">@csrf @method('DELETE')
                                <button class="btn btn-soft-danger btn-sm btn-icon" aria-label="Cabut akses {{ $u->name }}" title="Cabut akses"><x-icon name="logout" :size="15" /></button></form>
                        @endif
                        @endif
                    </div></td>
                </tr>
            @endforeach</tbody></table></div>
    @endif
    {{ $users->links() }}
</div>
@endsection

@push('modals')
    @if ($syncEnabled)
    {{-- Beri akses: pilih orang dari data HaloDek, lalu peran & regu. --}}
    <x-form-modal id="access-modal" create-title="Beri Akses Inventaris" :action="route('users.store')" submit="Beri Akses">
        <div style="display:grid;gap:16px">
            <x-select name="nik" label="Orang" required hint="Hanya orang yang sudah ada di HaloDek dan belum punya akses. Orang baru ditambahkan dulu di HaloDek (Manajemen Pengguna).">
                <option value="">- Pilih orang -</option>
                @foreach ($candidates->groupBy(fn ($u) => (string) $u->department) as $dept => $rows)
                    <optgroup label="{{ $dept !== '' ? 'Bidang '.$dept : 'Tanpa bidang' }}">
                        @foreach ($rows as $u)<option value="{{ $u->nik }}" @selected((string) old('nik') === (string) $u->nik)>{{ $u->name }} ({{ $u->nik }})</option>@endforeach
                    </optgroup>
                @endforeach
            </x-select>
            @include('users._access-fields', ['prefix' => 'a'])
            <p class="hint" style="margin:0">Password sementara ditampilkan sekali setelah disimpan dan wajib diganti saat masuk pertama.</p>
        </div>
    </x-form-modal>
    <x-form-modal id="edit-access-modal" create-title="Ubah Akses" edit-title="Ubah Akses" :action="route('users.store')">
        <div style="display:grid;gap:16px">
            @include('users._access-fields', ['prefix' => 'e'])
            <label class="check"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked> Akun aktif (bisa masuk)</label>
            <p class="hint" style="margin:0">Akun ikut nonaktif saat orangnya dinonaktifkan di HaloDek, tetapi tidak aktif kembali sendiri. Bila orangnya sudah aktif lagi di HaloDek, centang lagi di sini. Nama, divisi, bidang, jabatan, dan No. WA diubah di HaloDek.</p>
        </div>
    </x-form-modal>
    @else
    <x-form-modal id="user-modal" create-title="Tambah Akun" edit-title="Ubah Pengguna" :action="route('users.store')" size="lg">
        <div class="form-sections">
            <section class="form-section">
                <h4>Identitas</h4>
                <div class="form-grid"><x-input name="nik" label="NIK" required maxlength="50" inputmode="numeric" /><x-input name="name" label="Nama lengkap" required maxlength="255" /></div>
                <x-input name="phone" label="No. WhatsApp" maxlength="30" data-phone placeholder="08xxxxxxxxxx" />
            </section>

            <section class="form-section">
                <h4>Penempatan</h4>
                <div class="form-grid"><x-input name="division" label="Divisi" maxlength="100" /><x-input name="department" label="Bidang" maxlength="100" /></div>
                <x-input name="position" label="Jabatan" maxlength="100" />
                <p class="hint" style="margin:0">Mode mandiri (HaloDek belum tersambung). Setelah <code>HALODEK_URL</code> diisi, data diri diambil otomatis dari HaloDek.</p>
            </section>

            <section class="form-section">
                <h4>Peran &amp; akses</h4>
                <x-select name="role" label="Peran" required id="user-role">
                    @foreach (\App\Models\User::ADMIN_ROLES as $k)<option value="{{ $k }}" @selected(old('role', 'staf') === $k)>{{ config("inventaris.roles.$k") }}</option>@endforeach
                </x-select>
                <p class="hint" id="user-role-desc" style="margin-top:-8px"></p>
                <div class="field" id="user-teams">
                    <label class="label" for="user-team-id">Regu kelolaan <span class="req">*</span></label>
                    <select class="select" id="user-team-id" name="unit_id">
                        <option value="">- Pilih regu -</option>
                        @foreach ($units as $t)<option value="{{ $t->id }}" @selected(old('unit_id') == $t->id)>{{ $t->name }}</option>@endforeach
                    </select>
                    <p class="hint">Pengelola hanya bisa mengelola aset dan stok regu ini.</p>
                    @error('unit_id')<span class="error">{{ $message }}</span>@enderror
                </div>
                <label class="check" data-show-on-edit hidden><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked> Akun aktif (bisa masuk)</label>
                <p class="hint" data-hide-on-edit style="margin:0">Password sementara ditampilkan sekali setelah disimpan dan wajib diganti saat masuk pertama. Bila NIK sudah ada sebagai karyawan dari HaloDek, datanya dijadikan akun.</p>
            </section>
        </div>
    </x-form-modal>
    @endif
    <x-import-modal :action="route('users.import')" :template="route('users.template')" title="Beri Akses Massal (CSV)" columns="nik, role (superadmin, manajer, kepala_regu, staf), regu (kode regu, untuk kepala_regu/staf)" />
@endpush

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    var role = document.getElementById('user-role'), box = document.getElementById('user-teams'), desc = document.getElementById('user-role-desc');
    var texts = {
        superadmin: 'Mengelola seluruh aplikasi, semua regu, dan akses pengguna.',
        manajer: 'Melihat semua regu tanpa mengubah data.',
        kepala_regu: 'Mengelola penuh unitnya, termasuk kategori, lokasi, hapus, dan impor.',
        staf: 'Pekerjaan harian unitnya: aset, mutasi, servis, dan stok.'
    };
    function sync() {
        box.hidden = role.value !== 'kepala_regu' && role.value !== 'staf';
        desc.textContent = texts[role.value] || '';
    }
    if (role) {
        role.addEventListener('change', sync);
        document.addEventListener('hu:prepared', sync);
        sync();
    }

    // Form akses (Beri akses / Ubah akses): regu hanya untuk Kepala Regu & Staf.
    document.querySelectorAll('[data-access-role]').forEach(function (sel) {
        var unit = document.getElementById(sel.getAttribute('data-access-role')), help = document.getElementById(sel.id + '-desc');
        function s2() {
            unit.hidden = sel.value !== 'kepala_regu' && sel.value !== 'staf';
            if (help) { help.textContent = texts[sel.value] || ''; }
        }
        sel.addEventListener('change', s2);
        document.addEventListener('hu:prepared', s2);
        s2();
    });
})();
</script>
@endpush
