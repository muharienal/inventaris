{{--
    Pilihan pemegang: karyawan (NIK), bidang, atau divisi (struktur dari HaloDek).
    $p     : awalan id unik per form (semua pilihan berupa dropdown dari database)
    $none  : tampilkan pilihan "Tidak ada" (form tambah aset)
    $label : judul pilihan jenis pemegang
--}}
@php
    $none = $none ?? false;
    $oldType = old('holder_type', $none ? '' : 'orang');
    $groupErr = $errors->first('holder') ?: $errors->first('holder_type') ?: $errors->first('holder_org_id');
    $byDivision = $org['departments']->groupBy(fn ($d) => (int) $d->parent_id);
    $divNames = $org['divisions']->pluck('name', 'id');
@endphp
<div class="holder-picker" data-holder-picker @unless ($none) data-required @endunless>
    <div class="form-grid">
        <x-select name="holder_type" :id="$p.'-holder-type'" :label="$label" data-holder-type>
            @if ($none)<option value="" @selected($oldType === '')>Tidak ada (gudang / dipakai bersama)</option>@endif
            <option value="orang" @selected($oldType === 'orang')>Karyawan</option>
            <option value="bidang" @selected($oldType === 'bidang') @disabled($org['departments']->isEmpty())>Bidang</option>
            <option value="divisi" @selected($oldType === 'divisi') @disabled($org['divisions']->isEmpty())>Divisi</option>
        </x-select>

        <div data-holder="orang">
            <div class="field">
                <label class="label" for="{{ $p }}-holder-nik">Karyawan</label>
                <select class="select {{ $errors->has('holder_nik') ? 'is-invalid' : '' }}" name="holder_nik" id="{{ $p }}-holder-nik">
                    <option value="">- Pilih karyawan -</option>
                    @foreach ($org['people']->groupBy(fn ($u) => (string) $u->department) as $dept => $rows)
                        <optgroup label="{{ $dept !== '' ? 'Bidang '.$dept : 'Tanpa bidang' }}">
                            @foreach ($rows as $u)<option value="{{ $u->nik }}" @selected((string) old('holder_nik') === (string) $u->nik)>{{ $u->name }} ({{ $u->nik }})</option>@endforeach
                        </optgroup>
                    @endforeach
                </select>
                @error('holder_nik')<span class="error">{{ $message }}</span>@enderror
            </div>
        </div>
        <div data-holder="bidang" hidden>
            <div class="field">
                <label class="label" for="{{ $p }}-holder-bidang">Bidang</label>
                <select class="select" name="holder_org_id" id="{{ $p }}-holder-bidang" disabled>
                    <option value="">- Pilih bidang -</option>
                    @foreach ($byDivision as $divId => $rows)
                        <optgroup label="{{ $divId && isset($divNames[$divId]) ? 'Divisi '.$divNames[$divId] : 'Tanpa divisi' }}">
                            @foreach ($rows as $d)<option value="{{ $d->id }}" @selected((int) old('holder_org_id') === $d->id)>{{ $d->name }}</option>@endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>
        </div>
        <div data-holder="divisi" hidden>
            <div class="field">
                <label class="label" for="{{ $p }}-holder-divisi">Divisi</label>
                <select class="select" name="holder_org_id" id="{{ $p }}-holder-divisi" disabled>
                    <option value="">- Pilih divisi -</option>
                    @foreach ($org['divisions'] as $d)<option value="{{ $d->id }}" @selected((int) old('holder_org_id') === $d->id)>{{ $d->name }}</option>@endforeach
                </select>
            </div>
        </div>
    </div>
    @if ($groupErr)<span class="error">{{ $groupErr }}</span>@endif
    @if ($org['divisions']->isEmpty() && $org['departments']->isEmpty())
        <span class="hint">Daftar bidang &amp; divisi diambil dari HaloDek saat data pegawai disinkron (menu Pengguna).</span>
    @endif
</div>
