<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk · {{ config('inventaris.name') }}</title>
    @include('partials.head-icons')
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
</head>
<body class="auth-body">
<x-toasts :detail="true" />

<div class="auth-bg">
    {{-- Latar abstrak: grid titik + bingkai bertingkat di belakang kartu (tanpa data apa pun). --}}
    <div class="auth-art" aria-hidden="true">
        <span class="frame f1"></span><span class="frame f2"></span><span class="frame f3"></span>
    </div>

    <header class="auth-top">
        <span class="auth-co"><img src="{{ asset('images/logo-pcs.png') }}" alt="{{ config('inventaris.company') }}"></span>
        <span class="auth-sep" aria-hidden="true"></span>
        <img class="logo" src="{{ asset('images/inventaris-icon.svg') }}" alt="">
        <span class="auth-wordmark"><b>Inventaris</b><small>Umum &amp; IT</small></span>
    </header>

    <section class="auth-copyblock">
        <h1 class="auth-title">Kelola aset dan stok kantor dengan mudah.</h1>
        <p class="auth-lead">Catat pemegang, lokasi, jadwal servis, dan stok barang habis pakai Bidang Umum dan IT dalam satu aplikasi.</p>
    </section>

    <main class="auth-panel">
        <section class="auth-card">
            <h2 class="auth-h">Masuk</h2>
            <p class="auth-sub">Gunakan NIK dan password akun Inventaris Anda.</p>

            <form method="POST" action="{{ route('login') }}" id="login-form" class="auth-form" autocomplete="on">
                @csrf
                <div class="field">
                    <label class="label" for="nik">NIK</label>
                    <div class="input-icon">
                        <x-icon name="user" :size="18" />
                        <input type="text" id="nik" name="nik" value="{{ old('nik') }}" class="input {{ $errors->has('nik') ? 'is-invalid' : '' }}" placeholder="Masukkan NIK" required autofocus autocomplete="username">
                    </div>
                    @error('nik')<span class="error">{{ $message }}</span>@enderror
                </div>

                <div class="field">
                    <label class="label" for="password">Password</label>
                    <div class="input-icon input-wrap">
                        <x-icon name="lock" :size="18" />
                        <input type="password" id="password" name="password" class="input {{ $errors->has('password') ? 'is-invalid' : '' }}" placeholder="Masukkan password" required autocomplete="current-password">
                        <button type="button" class="input-eye" id="toggle-pw" aria-label="Tampilkan password"><x-icon name="eye" :size="18" /></button>
                    </div>
                    @error('password')<span class="error">{{ $message }}</span>@enderror
                </div>

                <label class="check"><input type="checkbox" name="remember" value="1"> Ingat saya</label>

                <button type="submit" class="btn btn-primary btn-block btn-lg login-go">Masuk <x-icon name="arrow-right" :size="18" /></button>
            </form>
            <p class="auth-note">Lupa password? Hubungi Superadmin untuk mengatur ulang.</p>
        </section>
    </main>

    <footer class="auth-copy">IT {{ config('inventaris.company') }} © {{ date('Y') }}</footer>
</div>

<script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}"></script>
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    var pw = document.getElementById('password'), form = document.getElementById('login-form');
    document.getElementById('toggle-pw').addEventListener('click', function () {
        pw.type = pw.type === 'password' ? 'text' : 'password';
    });

    // Cegah 419: halaman dari cache (tombol Back) atau yang terlalu lama terbuka dimuat ulang agar token CSRF-nya baru.
    var loadedAt = Date.now();
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) { location.reload(); return; }
        form.querySelector('button[type=submit]').disabled = false;
    });
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && Date.now() - loadedAt > 15 * 60 * 1000) { location.reload(); }
    });
    form.addEventListener('submit', function (e) {
        var b = form.querySelector('button[type=submit]');
        if (b.disabled) { e.preventDefault(); return; }
        setTimeout(function () { b.disabled = true; }, 0);
    });
})();
</script>
</body>
</html>
