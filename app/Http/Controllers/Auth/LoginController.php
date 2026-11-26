<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    /** Hash bcrypt tak bertuan, hanya untuk menyamakan waktu pengecekan. */
    private const DUMMY_HASH = '$2y$12$ABiNF0AR/3zIrpB/M/4zreuNZ5fi0EqenzIUapoSEXMvyM7VNKgPu';

    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Satu jalur masuk: NIK + password, untuk Superadmin, Manajer, dan Pengelola.
     * Akun karyawan hanya data pemegang aset dan tidak bisa masuk. Pesan galat dibuat sama
     * untuk NIK tak dikenal / bukan pengguna aplikasi / password salah agar NIK tidak bisa ditebak.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nik' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:255'],
        ], [
            'nik.required' => 'NIK wajib diisi.',
            'password.required' => 'Password wajib diisi.',
        ]);

        $user = User::where('nik', trim($data['nik']))->first();

        // Hash::check dummy menjaga waktu respons tetap serupa sehingga keberadaan NIK tidak bisa ditebak.
        if (! $user || ! $user->isAdmin()) {
            Hash::check($data['password'], self::DUMMY_HASH);

            throw ValidationException::withMessages(['nik' => 'NIK atau password salah.']);
        }
        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['nik' => 'NIK atau password salah.']);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages(['nik' => 'Akun Anda dinonaktifkan. Hubungi Superadmin.']);
        }

        Auth::login($user, $request->boolean('remember'));

        return $this->finish($request);
    }

    private function finish(Request $request): RedirectResponse
    {
        $request->session()->regenerate();
        $request->user()->forceFill(['last_login_at' => now()])->saveQuietly();

        return redirect()->intended(route('dashboard'))
            ->with('success', 'Selamat datang, '.\Illuminate\Support\Str::words($request->user()->name, 1, '').'.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'Anda sudah keluar.');
    }
}
