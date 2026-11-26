<x-select name="role" label="Peran di Inventaris" required id="{{ $prefix }}-role" data-access-role="{{ $prefix }}-unit-box">
    @foreach (\App\Models\User::ADMIN_ROLES as $k)<option value="{{ $k }}" @selected($k === 'staf')>{{ config("inventaris.roles.$k") }}</option>@endforeach
</x-select>
<p class="hint" id="{{ $prefix }}-role-desc" style="margin-top:-8px"></p>
<div class="field" id="{{ $prefix }}-unit-box">
    <label class="label" for="{{ $prefix }}-unit">Unit kelolaan <span class="req">*</span></label>
    <select class="select" id="{{ $prefix }}-unit" name="unit_id">
        <option value="">- Pilih unit -</option>
        @foreach ($units as $t)<option value="{{ $t->id }}">{{ $t->name }}</option>@endforeach
    </select>
    <p class="hint">Hanya bisa mengelola aset dan stok di unit ini.</p>
</div>
