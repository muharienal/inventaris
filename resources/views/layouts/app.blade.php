<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Beranda') · {{ config('inventaris.name') }}</title>
    @include('partials.head-icons')
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    @stack('head')
</head>
<body class="app {{ auth()->user()->canManage() ? '' : 'read-only' }}">
@php
    $me = auth()->user();
    $canManage = $me->canManage();
    $on = fn (string ...$patterns) => request()->routeIs(...$patterns);
    $act = fn (bool $cond) => $cond ? 'active' : '';
    $isDash = $on('dashboard');
@endphp

<div class="backdrop"></div>

{{-- ===================== NAVIGASI SAMPING ===================== --}}
<aside class="side" aria-label="Menu utama">
    <a href="{{ route('dashboard') }}" class="side-brand" aria-label="{{ config('inventaris.full_name') }}">
        <span class="side-co"><img src="{{ asset('images/logo-pcs.png') }}" alt="{{ config('inventaris.company') }}"></span>
        <span class="side-sep" aria-hidden="true"></span>
        <img class="side-mark" src="{{ asset('images/inventaris-icon.svg') }}" alt="">
        <span class="side-name"><b>Inventaris</b><small>Umum &amp; IT</small></span>
    </a>

    <nav class="side-nav">
        <a href="{{ route('dashboard') }}" class="side-link {{ $act($isDash) }}"><x-icon name="home" :size="18" /> Beranda</a>

        <div class="side-group">Barang</div>
        <a href="{{ route('assets.index') }}" class="side-link {{ $act($on('assets.*')) }}"><x-icon name="laptop" :size="18" /> Aset</a>
        <a href="{{ route('consumables.index') }}" class="side-link {{ $act($on('consumables.*')) }}"><x-icon name="box" :size="18" /> Barang habis pakai</a>

        <div class="side-group">Data induk</div>
        <a href="{{ route('locations.index') }}" class="side-link {{ $act($on('locations.*')) }}"><x-icon name="pin" :size="18" /> Lokasi &amp; ruangan</a>
        <a href="{{ route('categories.index') }}" class="side-link {{ $act($on('categories.*')) }}"><x-icon name="tag" :size="18" /> Kategori</a>
        @if ($me->isSuperadmin())
            <a href="{{ route('users.index') }}" class="side-link {{ $act($on('users.*')) }}"><x-icon name="users" :size="18" /> Pengguna</a>
        @endif
        @if ($me->isSuperadmin() || $me->isManajer())
            <a href="{{ route('audit.index') }}" class="side-link {{ $act($on('audit.*')) }}"><x-icon name="history" :size="18" /> Log aktivitas</a>
        @endif

    </nav>

    <div class="side-foot">{{ config('inventaris.company') }}</div>
</aside>

{{-- ===================== AREA KERJA ===================== --}}
<div class="work">
    <header class="bar">
        <button type="button" class="icon-btn bar-menu" data-nav-toggle aria-label="Buka menu"><x-icon name="menu" :size="20" /></button>
        <a href="{{ route('dashboard') }}" class="bar-brand" aria-label="Beranda"><span class="bar-co"><img src="{{ asset('images/logo-pcs.png') }}" alt=""></span><img src="{{ asset('images/inventaris-icon.svg') }}" alt=""><b>Inventaris</b></a>

            <form class="bar-search" method="GET" action="{{ route('assets.index') }}" role="search">
                <x-icon name="search" :size="16" />
                <input id="q-nav" type="search" name="q" maxlength="60" value="{{ $on('assets.index') ? request('q') : '' }}" placeholder="Cari aset: kode, nama, nomor seri, pemegang" autocomplete="off" aria-label="Cari aset">
                <kbd>/</kbd>
            </form>

        <div class="bar-actions">
            @if ($canManage)
                @unless ($on('assets.index'))
                    <a href="{{ route('assets.index', ['tambah' => 1]) }}" class="btn btn-cta btn-sm"><x-icon name="plus" :size="16" /> <span class="hide-xs">Tambah Aset</span></a>
                @endunless
            @else
                <span class="badge tone-blue plain" title="Akun supervisi hanya dapat melihat data">Mode lihat</span>
            @endif

            <div class="dropdown">
                <button class="userbtn" type="button" data-dropdown-toggle aria-expanded="false" aria-label="Akun">
                    <span class="avatar sm">{{ $me->initials() }}</span>
                    <span class="userbtn-name">{{ \Illuminate\Support\Str::words($me->name, 2, '') }}<small>{{ $me->roleLabel() }}</small></span>
                    <x-icon name="chevron-down" :size="14" />
                </button>
                <div class="dropdown-menu">
                    <div class="dd-head">
                        <div class="strong">{{ $me->name }}</div>
                        <div class="text-xs muted">NIK {{ $me->nik }}</div>
                    </div>
                    <a class="dropdown-item" href="{{ route('profile.edit') }}"><x-icon name="user" :size="16" /> Profil &amp; password</a>
                    <form method="POST" action="{{ route('logout') }}">@csrf<button class="dropdown-item danger" data-no-lock><x-icon name="logout" :size="16" /> Keluar</button></form>
                </div>
            </div>
        </div>
    </header>

    <main class="shell">
        @unless ($isDash)
            <div class="page-head">
                <h1>@yield('title', 'Beranda')</h1>
                @hasSection('subtitle')<p class="page-sub">@yield('subtitle')</p>@endif
            </div>
        @endunless

        @include('components.flash')
        @yield('content')
    </main>

    <footer class="footer">IT {{ config('inventaris.company') }} © {{ date('Y') }}</footer>
</div>

@stack('modals')

@if (old('_modal'))
    <script nonce="{{ $cspNonce ?? '' }}">window.HD_RESTORE = @json(['modal' => old('_modal'), 'action' => old('_action'), 'method' => old('_method')]);</script>
@endif
<script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}"></script>
@stack('scripts')
</body>
</html>
