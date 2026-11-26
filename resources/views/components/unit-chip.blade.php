@props(['unit'])
@if ($unit)<span {{ $attributes->class(['chip']) }}><span class="chip-ico" style="background: {{ $unit->hex() }}1F; color: {{ $unit->hex() }}"><x-icon :name="$unit->icon" :size="13" /></span>{{ $unit->name }}</span>@endif
