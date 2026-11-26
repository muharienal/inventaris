<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', ['user' => $request->user()->load('unit')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (app(\App\Services\EmployeeSync::class)->enabled()) {
            return back()->with('error', 'Data diri diambil dari HaloDek. Ubah No. HP lewat Profil di HaloDek.');
        }
        $data = $request->validate(['phone' => ['nullable', 'string', 'max:30']]);
        $user->update($data);

        return back()->with('success', 'Profil berhasil diperbarui.');
    }

    public function password(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            // Aturan dibuat longgar sesuai permintaan: cukup minimal 6 karakter, bebas isinya.
            'password' => ['required', 'confirmed', 'string', 'min:6', 'max:255', 'different:current_password'],
        ], [
            'password.different' => 'Password baru harus berbeda dari password saat ini.',
            'password.confirmed' => 'Konfirmasi password baru tidak cocok.',
            'password.min' => 'Password minimal 6 karakter.',
        ]);

        $user->update(['password' => $data['password'], 'must_change_password' => false]);

        // Sesi & cookie "ingat saya" di perangkat lain ikut tidak berlaku setelah password berubah.
        Auth::logoutOtherDevices($data['password']);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Password berhasil diganti.');
    }
}
