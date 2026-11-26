<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HandlesCsvImport;
use App\Http\Controllers\Controller;
use App\Models\Unit;
use App\Models\User;
use App\Services\EmployeeSync;
use App\Support\Audit;
use App\Support\Csv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Manajemen pengguna (khusus Superadmin).
 * Password awal = NIK dan wajib diganti saat login pertama.
 */
class UserController extends Controller
{
    use HandlesCsvImport;

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q'));
        $role = $request->query('role', 'semua');
        $state = $request->query('state', 'semua');
        $unitId = $request->integer('unit_id') ?: null;

        $users = User::with('unit')->withCount('heldAssets')
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('nik', 'like', "%{$search}%")
                ->orWhere('department', 'like', "%{$search}%")))
            ->when($role !== 'semua', fn ($q) => $q->where('role', $role))
            ->when($state === 'aktif', fn ($q) => $q->where('is_active', true))
            ->when($state === 'nonaktif', fn ($q) => $q->where('is_active', false))
            ->when($unitId, fn ($q) => $q->where('unit_id', $unitId))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $roleCounts = User::selectRaw('role, count(*) as c')->groupBy('role')->pluck('c', 'role');
        $units = Unit::ordered()->get();

        $sync = app(EmployeeSync::class);

        return view('users.index', compact('users', 'search', 'role', 'state', 'unitId', 'roleCounts', 'units') + [
            'syncEnabled' => $sync->enabled(), 'lastSync' => $sync->last(),
            // Orang yang bisa diberi akses (data dari HaloDek, belum punya akses): dropdown per bidang.
            'candidates' => $sync->enabled()
                ? User::where('role', User::ROLE_KARYAWAN)->where('is_active', true)->orderBy('department')->orderBy('name')->get(['nik', 'name', 'department'])
                : collect(),
        ]);
    }

    /** Ambil data karyawan dari HaloDek sekarang juga. */
    public function syncEmployees(EmployeeSync $sync): RedirectResponse
    {
        $result = $sync->run();

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /**
     * "Beri akses": orangnya sudah ada (data dari HaloDek), di sini hanya diberi peran Inventaris + unit.
     * Data diri tidak diketik di Inventaris agar tidak ada dua versi data orang yang sama.
     * Bila HaloDek belum tersambung, akun boleh dibuat manual (mode mandiri).
     */
    public function store(Request $request, EmployeeSync $sync): RedirectResponse
    {
        $nik = trim((string) preg_replace('/\s+[---]\s+.*$/u', '', (string) $request->input('nik'))); // "00004 - Budi" -> "00004"
        $request->merge(['nik' => $nik]);
        $central = $sync->enabled();

        if ($central) {
            $person = User::where('nik', $nik)->first();
            if (! $person && $nik !== '' && $sync->refreshForUnknownNik()) {
                $person = User::where('nik', $nik)->first();
            }
            if (! $person) {
                throw ValidationException::withMessages(['nik' => "NIK {$nik} belum ada di HaloDek. Tambahkan orangnya dulu di HaloDek (Manajemen Pengguna), lalu beri akses di sini."]);
            }
            if (! $person->isKaryawan()) {
                throw ValidationException::withMessages(['nik' => "{$person->name} sudah punya akses ({$person->roleLabel()}). Ubah aksesnya dari daftar."]);
            }
            if (! $person->is_active) {
                throw ValidationException::withMessages(['nik' => "{$person->name} nonaktif di HaloDek."]);
            }
            $data = $this->accessData($request);
        } else {
            $existing = User::where('nik', $nik)->first();
            $person = $existing && $existing->isKaryawan() ? $existing : null;
            $data = $this->validated($request, $person);
        }

        $temp = User::temporaryPassword();
        $attrs = $data + ['password' => $temp, 'must_change_password' => true, 'is_active' => true]; // di-hash lewat cast
        $user = $person ? tap($person)->update($attrs) : User::create($attrs);
        if (! $sync->enabled()) {
            \App\Models\OrgUnit::syncFromUsers(); // tanpa HaloDek: bidang/divisi baru jadi pilihan pemegang aset
        }

        Audit::log('user.create', "Memberi akses {$user->roleLabel()} kepada {$user->name} ({$user->nik}).", $user);

        return back()->with('success', "{$user->name} kini bisa masuk sebagai {$user->roleLabel()}.")
            ->with('tempPassword', ['name' => $user->name, 'nik' => $user->nik, 'password' => $temp]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        if ($user->isKaryawan()) {
            return back()->with('error', 'Orang ini belum punya akses. Pakai tombol "Beri akses".');
        }

        // Data diri mengikuti HaloDek; yang diubah di sini hanya peran, unit, dan status akses.
        $data = app(EmployeeSync::class)->enabled() ? $this->accessData($request) : $this->validated($request, $user);

        $data['is_active'] = $request->boolean('is_active');

        if ($user->id === $request->user()->id && ($data['role'] !== $user->role || ! $data['is_active'])) {
            return back()->with('error', 'Anda tidak dapat mengubah peran atau menonaktifkan akun Anda sendiri.');
        }

        if ($this->wouldRemoveLastSuperadmin($user, $data['role'], $data['is_active'])) {
            return back()->with('error', 'Harus ada minimal satu Superadmin aktif.');
        }

        $user->update($data);
        if (! app(EmployeeSync::class)->enabled()) {
            \App\Models\OrgUnit::syncFromUsers();
        }

        Audit::log('user.update', "Mengubah pengguna {$user->name} ({$user->nik}).", $user);

        return back()->with('success', 'Data pengguna berhasil diperbarui.');
    }

    /** Atur ulang password pengelola menjadi password sementara acak (ditampilkan sekali). */
    public function resetPassword(User $user): RedirectResponse
    {
        if ($user->isKaryawan()) {
            return back()->with('error', 'Karyawan tidak memiliki akun, jadi tidak memakai password.');
        }

        $temp = User::temporaryPassword();
        $user->update(['password' => $temp, 'must_change_password' => true]);
        Audit::log('user.reset_password', "Mereset password {$user->name} ({$user->nik}).", $user);

        return back()->with('success', "Password {$user->name} diatur ulang.")
            ->with('tempPassword', ['name' => $user->name, 'nik' => $user->nik, 'password' => $temp]);
    }

    public function toggle(Request $request, User $user): RedirectResponse
    {
        if ($user->isKaryawan()) {
            return back()->with('error', 'Status karyawan mengikuti HaloDek.');
        }
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'Anda tidak dapat menonaktifkan akun Anda sendiri.');
        }

        if ($user->is_active && $this->wouldRemoveLastSuperadmin($user, $user->role, false)) {
            return back()->with('error', 'Harus ada minimal satu Superadmin aktif.');
        }

        $user->update(['is_active' => ! $user->is_active]);
        Audit::log('user.toggle', ($user->is_active ? 'Mengaktifkan' : 'Menonaktifkan')." pengguna {$user->name}.", $user);

        return back()->with('success', $user->is_active ? 'Akun diaktifkan.' : 'Akun dinonaktifkan.');
    }

    /**
     * Cabut akses: orangnya kembali menjadi karyawan biasa (tidak bisa masuk). Data diri, aset yang
     * dipegang, dan riwayatnya tetap; tidak ada data yang dihapus.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', 'Anda tidak dapat mencabut akses Anda sendiri.');
        }
        if ($user->isKaryawan()) {
            return back()->with('error', "{$user->name} memang tidak punya akses.");
        }
        if ($this->wouldRemoveLastSuperadmin($user, 'x', false)) {
            return back()->with('error', 'Harus ada minimal satu Superadmin aktif.');
        }

        $old = $user->roleLabel();
        $user->update(['role' => User::ROLE_KARYAWAN, 'unit_id' => null, 'password' => User::unusablePassword(), 'must_change_password' => false]);
        Audit::log('user.revoke', "Mencabut akses {$old} dari {$user->name} ({$user->nik}).", $user);

        return back()->with('success', "Akses {$user->name} dicabut. Datanya tetap ada sebagai karyawan.");
    }

    public function template(): StreamedResponse
    {
        // Beri akses massal: orangnya harus sudah ada di HaloDek (data diri tidak diisi di sini).
        return Csv::download('template-beri-akses.csv', ['nik', 'role', 'regu'], [
            ['00010', 'manajer', ''],
            ['00011', 'kepala_regu', 'sarpras'],
            ['00012', 'staf', 'it'],
        ]);
    }

    public function export(): StreamedResponse
    {
        $rows = (function () {
            foreach (User::with('unit')->orderBy('name')->cursor() as $u) {
                yield [$u->nik, $u->name, $u->role, $u->phone, $u->division, $u->department, $u->position, $u->unit->code ?? '', $u->is_active ? 'aktif' : 'nonaktif'];
            }
        })();

        return Csv::download('pengguna-inventaris-'.now()->format('Ymd').'.csv', ['nik', 'nama', 'peran', 'no_wa', 'divisi', 'bidang', 'jabatan', 'regu', 'status'], $rows);
    }

    public function import(Request $request)
    {
        $units = Unit::all()->keyBy(fn ($u) => mb_strtolower($u->code));

        $result = $this->runImport($request, ['nik', 'role'], function (array $d) use ($units) {
            $nik = trim((string) ($d['nik'] ?? ''));
            $person = User::where('nik', $nik)->first();
            if (! $person) {
                throw ValidationException::withMessages(['nik' => "NIK {$nik} belum ada di HaloDek. Tambahkan dulu di HaloDek."]);
            }
            if (! $person->isKaryawan()) {
                throw ValidationException::withMessages(['nik' => "{$person->name} sudah punya akses."]);
            }
            $role = trim((string) ($d['role'] ?? ''));
            if (! in_array($role, User::ADMIN_ROLES, true)) {
                throw ValidationException::withMessages(['role' => 'Peran harus salah satu: '.implode(', ', User::ADMIN_ROLES).'.']);
            }
            $unit = null;
            if (in_array($role, User::UNIT_ROLES, true)) {
                $code = mb_strtolower(trim((string) ($d['regu'] ?? $d['unit'] ?? ''))); // kolom lama "unit" tetap diterima
                $unit = $units[$code] ?? throw ValidationException::withMessages(['regu' => 'Kepala Regu dan Staf wajib memiliki regu (kode: '.$units->keys()->implode(', ').').']);
            }

            // Password acak tak diketahui; akun mendapat password sementara lewat "Atur ulang password".
            $person->update(['role' => $role, 'unit_id' => $unit?->id, 'password' => User::unusablePassword(), 'must_change_password' => true]);
        });

        Audit::log('user.import', "Beri akses massal: {$result['ok']} orang.");

        $response = $this->importResponse($result, 'users.index', 'akses');

        return $result['ok'] > 0
            ? $response->with('warning', 'Akun dari impor belum punya password. Buka tiap akun lalu pilih "Atur ulang password" untuk mendapatkan password sementara.')
            : $response;
    }

    // ------------------------------------------------------------------ util

    /** Peran & unit saja (data diri dari HaloDek). */
    private function accessData(Request $request): array
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(User::ADMIN_ROLES)],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
        ], ['role.required' => 'Peran wajib dipilih.']);

        if (in_array($data['role'], User::UNIT_ROLES, true)) {
            if (empty($data['unit_id'])) {
                throw ValidationException::withMessages(['unit_id' => 'Kepala Regu dan Staf wajib memilih regu.']);
            }
        } else {
            $data['unit_id'] = null;
        }

        return $data;
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'nik' => ['required', 'string', 'max:50', Rule::unique('users', 'nik')->ignore($user?->id)],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'division' => ['nullable', 'string', 'max:100'],
            'department' => ['nullable', 'string', 'max:100'],
            'position' => ['nullable', 'string', 'max:100'],
            'role' => ['required', Rule::in(User::ADMIN_ROLES)],
            'unit_id' => ['nullable', 'integer', 'exists:units,id'],
        ], [
            'nik.required' => 'NIK wajib diisi.',
            'nik.unique' => 'NIK ini sudah terdaftar.',
            'name.required' => 'Nama wajib diisi.',
            'role.required' => 'Peran wajib dipilih.',
        ]);

        // Kepala Regu & Staf wajib punya unit; Superadmin & Manajer melihat semua unit.
        if (in_array($data['role'], User::UNIT_ROLES, true)) {
            if (empty($data['unit_id'])) {
                throw ValidationException::withMessages(['unit_id' => 'Kepala Regu dan Staf wajib memilih regu.']);
            }
        } else {
            $data['unit_id'] = null;
        }

        return $data;
    }

    private function wouldRemoveLastSuperadmin(User $user, string $newRole, bool $newActive): bool
    {
        if (! $user->isSuperadmin() || ($newRole === User::ROLE_SUPERADMIN && $newActive)) {
            return false;
        }

        return User::active()->where('role', User::ROLE_SUPERADMIN)->where('id', '!=', $user->id)->doesntExist();
    }
}
