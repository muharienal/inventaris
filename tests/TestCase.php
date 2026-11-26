<?php

namespace Tests;

use App\Models\Unit;
use App\Models\User;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Jangan pernah memanggil HaloDek sungguhan dari tes; pakai Http::fake().
        \Illuminate\Support\Facades\Http::preventStrayRequests();
    }

    use CreatesApplication;

    /**
     * Beberapa pengguna dipakai bergantian dalam satu tes: hapus hash password sesi milik
     * pengguna sebelumnya agar AuthenticateSession tidak menganggapnya sesi yang dibajak.
     */
    public function actingAs(\Illuminate\Contracts\Auth\Authenticatable $user, $guard = null)
    {
        $this->flushSession();

        return parent::actingAs($user, $guard);
    }

    protected function seedUnits(): void
    {
        $this->seed(UnitSeeder::class);
    }

    protected function user(string $role = User::ROLE_KARYAWAN, ?string $unitCode = null, array $attrs = []): User
    {
        if ($unitCode) {
            $attrs['unit_id'] = Unit::where('code', $unitCode)->value('id');
        }

        return User::factory()->role($role)->create($attrs);
    }
}
