<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\AssetReport;
use App\Models\Category;
use App\Models\Consumable;
use App\Models\ConsumableRequest;
use App\Models\Location;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InventarisTest extends TestCase
{
    use RefreshDatabase;

    private User $super;
    private User $it;      // kepala regu IT
    private User $sar;     // kepala regu Sarpras
    private User $emp;
    private User $emp2;
    private Unit $unitIt;
    private Location $loc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedUnits();
        Storage::fake('local');
        $this->super = $this->user(User::ROLE_SUPERADMIN);
        $this->it = $this->user(User::ROLE_KEPALA_REGU, 'it');
        $this->sar = $this->user(User::ROLE_KEPALA_REGU, 'sarpras');
        $this->emp = $this->user();
        $this->emp2 = $this->user();
        $this->unitIt = Unit::where('code', 'it')->first();
        $this->loc = Location::create(['name' => 'Ruang Finance', 'building' => 'Gedung A', 'floor' => '1']);
    }

    private function laptopCat(): Category
    {
        return Category::where('unit_id', $this->unitIt->id)->where('name', 'Laptop & PC')->first();
    }

    private function createAsset(array $extra = []): Asset
    {
        $this->actingAs($this->it)->post(route('assets.store'), $extra + [
            'unit_id' => $this->unitIt->id, 'category_id' => $this->laptopCat()->id, 'code' => 'AST-0001', 'name' => 'Lenovo ThinkPad',
            'status' => 'tersedia', 'condition' => 'baik', 'location_id' => $this->loc->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return Asset::latest('id')->first();
    }

    // ------------------------------------------------------------ login & akses

    public function test_login_needs_nik_and_password_and_karyawan_cannot_login(): void
    {
        $this->get('/login')->assertOk()->assertSee('name="password"', false)->assertDontSee('data-mode', false);

        $this->post('/login', ['nik' => $this->it->nik, 'password' => 'password'])->assertRedirect(route('dashboard'));
        auth()->logout();
        $mgr = $this->user(User::ROLE_MANAJER);
        $this->post('/login', ['nik' => $mgr->nik, 'password' => 'password'])->assertRedirect(route('dashboard'));
        auth()->logout();

        // Tanpa password ditolak; karyawan (pemegang aset) tidak punya akses, pesannya sama dengan NIK tak dikenal.
        $this->post('/login', ['nik' => $this->it->nik])->assertSessionHasErrors('password');
        $this->post('/login', ['nik' => $this->emp->nik, 'password' => 'password'])->assertSessionHasErrors(['nik' => 'NIK atau password salah.']);
        $this->post('/login', ['nik' => '99999', 'password' => 'password'])->assertSessionHasErrors(['nik' => 'NIK atau password salah.']);
        $this->assertGuest();

        // Sesi lama karyawan langsung dikeluarkan.
        $this->actingAs($this->emp)->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_staf_does_daily_work_but_not_master_data_delete_or_import(): void
    {
        $staf = $this->user(User::ROLE_STAF, 'it');
        $asset = $this->createAsset();

        $this->actingAs($staf)->patch(route('assets.update', $asset), array_merge($asset->only(['category_id', 'code', 'status', 'condition']), ['name' => 'Laptop Baru']))->assertSessionHasNoErrors();
        $this->assertSame('Laptop Baru', $asset->fresh()->name);
        $this->actingAs($staf)->get(route('assets.show', $asset))->assertSee('Alihkan')->assertDontSee('Hapus Aset');
        $this->actingAs($staf)->get(route('assets.index'))->assertSee('Tambah Aset')->assertDontSee('Impor CSV');
        $this->actingAs($staf)->get(route('categories.index'))->assertOk()->assertDontSee('data-modal-open="category-modal"', false);

        $this->actingAs($staf)->delete(route('assets.destroy', $asset))->assertForbidden();
        $this->actingAs($staf)->post(route('categories.store'), ['unit_id' => $this->unitIt->id, 'type' => 'aset', 'name' => 'X'])->assertForbidden();
        $this->actingAs($staf)->post(route('locations.store'), ['name' => 'X'])->assertForbidden();
        $this->actingAs($staf)->get(route('assets.template'))->assertForbidden();
        $this->assertNotNull($asset->fresh());
    }

    public function test_manajer_sees_all_units_read_only(): void
    {
        $asset = $this->createAsset();
        $mgr = $this->user(User::ROLE_MANAJER);
        foreach (['dashboard', 'assets.index', 'consumables.index', 'categories.index', 'locations.index', 'audit.index'] as $r) {
            $this->actingAs($mgr)->get(route($r))->assertOk();
        }
        $this->actingAs($mgr)->get(route('assets.index', ['unit' => $this->unitIt->id]))->assertSee('AST-0001')->assertDontSee('Tambah Aset')->assertSee('Mode lihat');
        $this->actingAs($mgr)->get(route('assets.show', $asset))->assertOk()->assertSee('Cetak QR')->assertDontSee('Alihkan')->assertDontSee('Hapus Aset')->assertDontSee('id="asset-modal"', false);
        $this->actingAs($mgr)->get(route('assets.export', ['unit' => $this->unitIt->id]))->assertOk();

        // Semua aksi ubah ditolak.
        $this->actingAs($mgr)->patch(route('assets.update', $asset), ['name' => 'x'])->assertForbidden();
        $this->actingAs($mgr)->delete(route('assets.destroy', $asset))->assertForbidden();
        $this->actingAs($mgr)->post(route('assets.move', $asset), ['type' => 'pindah'])->assertForbidden();
        $this->actingAs($mgr)->post(route('locations.store'), ['name' => 'X'])->assertForbidden();
        $this->actingAs($mgr)->get(route('users.index'))->assertForbidden();
        $this->assertSame('Lenovo ThinkPad', $asset->fresh()->name);
    }

    public function test_pages_render_for_each_role(): void
    {
        $asset = $this->createAsset();
        foreach ([$this->super, $this->it] as $u) {
            foreach (['dashboard', 'assets.index', 'consumables.index', 'categories.index', 'locations.index'] as $r) {
                $this->actingAs($u)->get(route($r))->assertOk();
            }
            $this->actingAs($u)->get(route('assets.show', $asset))->assertOk()->assertSee('AST-0001');
        }
        $this->actingAs($this->super)->get(route('users.index'))->assertOk();
        $this->actingAs($this->super)->get(route('audit.index'))->assertOk();
        $this->actingAs($this->it)->get(route('users.index'))->assertForbidden();
    }

    public function test_pengelola_only_manages_own_unit(): void
    {
        $asset = $this->createAsset();
        $this->actingAs($this->sar)->get(route('assets.show', $asset))->assertForbidden();
        $this->actingAs($this->sar)->patch(route('assets.update', $asset), ['name' => 'x'])->assertForbidden();
        $this->actingAs($this->sar)->get(route('assets.index', ['unit' => $this->unitIt->id]))->assertOk()->assertDontSee('AST-0001');
        $this->actingAs($this->super)->get(route('assets.index', ['unit' => $this->unitIt->id]))->assertSee('AST-0001');
    }

    // ---------------------------------------------------------------- aset

    public function test_create_asset_with_holder_photos_and_initial_history(): void
    {
        $asset = $this->createAsset([
            'holder_nik' => $this->emp->nik.' - '.$this->emp->name, 'purchase_date' => now()->subYear()->toDateString(),
            'photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
        ]);
        $this->assertSame($this->emp->id, $asset->holder_id);
        $this->assertSame('digunakan', $asset->status);
        $this->assertCount(2, $asset->photos);
        $this->assertSame('serah_terima', $asset->movements()->first()->type);

        $this->actingAs($this->it)->get(route('assets.show', $asset))->assertOk()->assertSee('Pembelian &amp; Garansi', false)->assertDontSee('Nilai buku');
        $this->actingAs($this->it)->get(route('assets.photo', $asset->photos->first()))->assertOk();
        $this->actingAs($this->sar)->get(route('assets.photo', $asset->photos->first()))->assertForbidden();
    }

    public function test_validation_unique_code_per_unit_and_unknown_holder(): void
    {
        $this->createAsset();
        $this->actingAs($this->it)->post(route('assets.store'), [
            'unit_id' => $this->unitIt->id, 'category_id' => $this->laptopCat()->id, 'code' => 'AST-0001', 'name' => 'x', 'status' => 'tersedia', 'condition' => 'baik',
        ])->assertSessionHasErrors('code');
        $this->actingAs($this->it)->post(route('assets.store'), [
            'unit_id' => $this->unitIt->id, 'category_id' => $this->laptopCat()->id, 'code' => 'AST-0002', 'name' => 'x', 'status' => 'tersedia', 'condition' => 'baik', 'holder_nik' => '99999',
        ])->assertSessionHasErrors('holder_nik');
    }

    public function test_movements_handover_lend_return_withdraw(): void
    {
        $asset = $this->createAsset();
        $loc2 = Location::create(['name' => 'Ruang Rapat']);

        $this->actingAs($this->it)->post(route('assets.move', $asset), ['type' => 'serah_terima', 'holder_nik' => $this->emp->nik])->assertSessionHas('success');
        $asset->refresh();
        $this->assertSame([$this->emp->id, 'digunakan'], [$asset->holder_id, $asset->status]);

        // Dipinjam orang lain lalu dikembalikan -> kembali ke pemegang semula.
        $this->actingAs($this->it)->post(route('assets.move', $asset), ['type' => 'pinjam', 'holder_nik' => $this->emp2->nik, 'location_id' => $loc2->id, 'due_back_at' => now()->addDays(3)->toDateString()])->assertSessionHas('success');
        $asset->refresh();
        $this->assertSame([$this->emp2->id, 'dipinjam', $loc2->id], [$asset->holder_id, $asset->status, $asset->location_id]);

        // Saat dipinjam tidak bisa serah terima.
        $this->actingAs($this->it)->post(route('assets.move', $asset), ['type' => 'serah_terima', 'holder_nik' => $this->emp->nik])->assertSessionHasErrors('type');

        $this->actingAs($this->it)->post(route('assets.move', $asset), ['type' => 'kembali'])->assertSessionHas('success');
        $asset->refresh();
        $this->assertSame([$this->emp->id, 'digunakan', $this->loc->id], [$asset->holder_id, $asset->status, $asset->location_id]);
        $this->assertNotNull($asset->movements()->where('type', 'pinjam')->first()->returned_at);

        $this->actingAs($this->it)->post(route('assets.move', $asset), ['type' => 'tarik', 'location_id' => $loc2->id]);
        $asset->refresh();
        $this->assertSame([null, 'tersedia', $loc2->id], [$asset->holder_id, $asset->status, $asset->location_id]);
        $this->assertSame(5, $asset->movements()->count()); // awal + 4

        $this->actingAs($this->it)->get(route('assets.show', $asset))->assertSee('Dipinjamkan')->assertSee('Ditarik ke gudang');
    }

    public function test_loan_requires_due_date_and_overdue_shows_on_dashboard(): void
    {
        $asset = $this->createAsset();
        $this->actingAs($this->it)->post(route('assets.move', $asset), ['type' => 'pinjam', 'holder_nik' => $this->emp->nik])->assertSessionHasErrors('due_back_at');
        $this->actingAs($this->it)->post(route('assets.move', $asset), ['type' => 'pinjam', 'holder_nik' => $this->emp->nik, 'due_back_at' => now()->addDay()->toDateString()]);
        $asset->movements()->where('type', 'pinjam')->update(['due_back_at' => now()->subDays(2)->toDateString()]);
        $this->actingAs($this->it)->get(route('dashboard'))->assertSee('lewat tenggat');
    }

    public function test_service_schedule_and_complete_updates_status_and_next_date(): void
    {
        $asset = $this->createAsset(['service_interval_months' => 3]);
        $this->actingAs($this->it)->post(route('assets.services.store', $asset), [
            'type' => 'perbaikan', 'status' => 'dijadwalkan', 'scheduled_at' => now()->addDay()->toDateString(), 'description' => 'Layar berkedip',
        ])->assertSessionHas('success');
        $this->assertSame('perbaikan', $asset->fresh()->status);

        $service = $asset->services()->first();
        $this->actingAs($this->it)->patch(route('assets.services.update', $service), [
            'status' => 'selesai', 'completed_at' => today()->toDateString(), 'cost' => 450000, 'result' => 'Ganti kabel fleksibel',
        ])->assertSessionHas('success');
        $asset->refresh();
        $this->assertSame('tersedia', $asset->status);
        $this->assertSame(today()->addMonthsNoOverflow(3)->toDateString(), $asset->next_service_at->toDateString());
        $this->actingAs($this->it)->get(route('assets.show', $asset))->assertSee('Rp 450.000');
    }

    public function test_service_due_and_warranty_filters(): void
    {
        $a = $this->createAsset(['next_service_at' => now()->addDays(5)->toDateString(), 'due_date' => now()->addDays(10)->toDateString()]);
        $this->actingAs($this->it)->get(route('assets.index', ['perhatian' => 'servis']))->assertSee($a->code);
        $this->actingAs($this->it)->get(route('assets.index', ['perhatian' => 'garansi']))->assertSee($a->code);
        $this->actingAs($this->it)->get(route('dashboard'))->assertSee('Servis jatuh tempo');
    }

    public function test_delete_asset_removes_photo_files(): void
    {
        $asset = $this->createAsset(['photos' => [UploadedFile::fake()->image('a.jpg')]]);
        $path = $asset->photos->first()->path;
        Storage::disk('local')->assertExists($path);
        $this->actingAs($this->it)->delete(route('assets.destroy', $asset))->assertRedirect();
        $this->assertModelMissing($asset);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_qr_export_and_import(): void
    {
        $asset = $this->createAsset();
        $this->actingAs($this->it)->get(route('assets.qr', ['unit' => $this->unitIt->id]))->assertOk()
            ->assertSee('data-qr="'.$asset->verifyUrl().'"', false);
        $res = $this->actingAs($this->it)->get(route('assets.export', ['unit' => $this->unitIt->id]))->assertOk();
        $this->assertStringContainsString('spreadsheetml', $res->headers->get('Content-Type'));

        $csv = "category,code,name,status,condition,holder_nik,location\nLaptop & PC,AST-0200,HP ProBook,tersedia,baik,{$this->emp->nik},Ruang Baru\nLaptop & PC,AST-0001,Duplikat,tersedia,baik,,,\n";
        $this->actingAs($this->it)->post(route('assets.import'), ['unit_id' => $this->unitIt->id, 'file' => UploadedFile::fake()->createWithContent('a.csv', $csv)])
            ->assertSessionHas('success', '1 aset berhasil diimpor. 1 baris dilewati.');
        $imp = Asset::where('code', 'AST-0200')->first();
        $this->assertSame([$this->emp->id, 'digunakan'], [$imp->holder_id, $imp->status]);
        $this->assertTrue(Location::where('name', 'Ruang Baru')->exists());
    }

    // ---------------------------------------------------------------- stok

    public function test_stock_in_out_adjust_and_never_negative(): void
    {
        $cat = Category::where('unit_id', $this->unitIt->id)->where('type', 'stok')->first();
        $this->actingAs($this->it)->post(route('consumables.store'), [
            'unit_id' => $this->unitIt->id, 'code' => 'TNT-1', 'name' => 'Tinta hitam', 'category_id' => $cat->id, 'unit_label' => 'botol', 'min_stock' => 3, 'initial_stock' => 5,
        ])->assertSessionHas('success');
        $item = Consumable::first();
        $this->assertSame(5, $item->stock);

        $this->actingAs($this->it)->post(route('consumables.adjust', $item), ['type' => 'keluar', 'qty' => 3])->assertSessionHas('success');
        $this->assertTrue($item->fresh()->isLow());
        $this->actingAs($this->it)->post(route('consumables.adjust', $item), ['type' => 'keluar', 'qty' => 9])->assertSessionHasErrors('qty');
        $this->assertSame(2, $item->fresh()->stock);
        $this->actingAs($this->it)->post(route('consumables.adjust', $item), ['type' => 'penyesuaian', 'qty' => 7]);
        $this->assertSame(7, $item->fresh()->stock);
        $this->assertSame([5, -3, 5], $item->movements()->oldest('id')->pluck('qty')->all());
        $this->actingAs($this->it)->get(route('consumables.show', $item))->assertOk()->assertSee('Penyesuaian');
    }

    public function test_stock_out_records_recipient_and_shows_on_dashboard(): void
    {
        $item = Consumable::create(['unit_id' => $this->unitIt->id, 'code' => 'KRT', 'name' => 'Kertas A4', 'unit_label' => 'rim', 'min_stock' => 1]);
        app(\App\Services\StockManager::class)->adjust($item, $this->it, 'masuk', 4);

        $this->actingAs($this->it)->post(route('consumables.adjust', $item), ['type' => 'keluar', 'qty' => 2, 'recipient' => 'Andi (Keuangan)', 'note' => 'cetak laporan'])->assertSessionHas('success');
        $this->assertSame('Diterima Andi (Keuangan). cetak laporan', $item->movements()->latest('id')->value('note'));
        $this->actingAs($this->it)->get(route('dashboard'))->assertSee('Stok Keluar Terbaru')->assertSee('Andi (Keuangan)');
    }

    public function test_categories_and_locations(): void
    {
        $this->actingAs($this->it)->post(route('categories.store'), ['unit_id' => $this->unitIt->id, 'type' => 'aset', 'name' => 'Tablet'])->assertSessionHas('success');
        $this->actingAs($this->it)->post(route('categories.store'), ['unit_id' => $this->unitIt->id, 'type' => 'aset', 'name' => 'Tablet'])->assertSessionHasErrors('name');
        $this->createAsset();
        $this->actingAs($this->it)->delete(route('categories.destroy', $this->laptopCat()))->assertSessionHas('error');
        // Lokasi dipakai bersama: Kepala Regu boleh menambah, mengubah/menghapus hanya Superadmin.
        $this->actingAs($this->it)->delete(route('locations.destroy', $this->loc))->assertForbidden();
        $this->actingAs($this->it)->patch(route('locations.update', $this->loc), ['name' => 'X'])->assertForbidden();
        $this->actingAs($this->super)->delete(route('locations.destroy', $this->loc))->assertSessionHas('error');
        $this->actingAs($this->it)->post(route('locations.store'), ['name' => 'Ruang Server'])->assertSessionHas('success');
    }

    public function test_standalone_mode_creates_accounts_and_revoke_keeps_person(): void
    {
        config(['inventaris.halodek_url' => '']); // HaloDek belum tersambung: akun boleh dibuat manual
        $this->actingAs($this->super)->get(route('users.index'))->assertSee('Tambah Akun')->assertDontSee('data-modal-open="access-modal"', false);
        $this->actingAs($this->super)->post(route('users.store'), ['nik' => '77777', 'name' => 'Pengelola Baru', 'role' => 'staf'])->assertSessionHasErrors('unit_id');
        $this->actingAs($this->super)->post(route('users.store'), ['nik' => '77777', 'name' => 'Pengelola Baru', 'role' => 'staf', 'unit_id' => $this->unitIt->id])->assertSessionHas('tempPassword');

        // Cabut akses: kembali jadi karyawan, aset & data tetap.
        $staf = User::where('nik', '77777')->first();
        $this->createAsset(['holder_nik' => '77777']);
        $this->actingAs($this->super)->delete(route('users.destroy', $staf))->assertSessionHas('success');
        $staf->refresh();
        $this->assertTrue($staf->isKaryawan());
        $this->assertNull($staf->unit_id);
        $this->assertSame(1, $staf->heldAssets()->count());
        $this->actingAs($this->super)->delete(route('users.destroy', $this->super))->assertSessionHas('error');
    }

    // ------------------------------------------------- karyawan dari HaloDek

    private function fakeHaloDekEmployees(array $rows): void
    {
        \Illuminate\Support\Facades\Http::fake(['halodek.test/api/v1/employees' => fn ($req) => $req->hasHeader('Authorization', 'Bearer test-token-0123456789abcdef')
            ? \Illuminate\Support\Facades\Http::response(['app' => 'HaloDek', 'data' => $rows, 'count' => count($rows)])
            : \Illuminate\Support\Facades\Http::response(['message' => 'Token API tidak valid.'], 401)]);
    }

    public function test_employees_are_synced_from_halodek(): void
    {
        $this->user(User::ROLE_KARYAWAN, null, ['nik' => '55555', 'name' => 'Sudah Keluar']);
        $this->fakeHaloDekEmployees([
            ['nik' => '12345', 'name' => 'Rina Kartika', 'department' => 'Keuangan', 'position' => 'Staf', 'phone' => '6281234567890', 'active' => true],
            ['nik' => $this->emp->nik, 'name' => 'Nama Baru', 'department' => 'HRD', 'position' => null, 'phone' => null, 'active' => true],
            ['nik' => $this->it->nik, 'name' => 'Akun Tidak Diubah', 'department' => null, 'position' => null, 'phone' => null, 'active' => false],
        ]);

        $this->actingAs($this->super)->post(route('users.sync'))->assertSessionHas('success');

        $rina = User::where('nik', '12345')->first();
        $this->assertTrue($rina->isKaryawan() && $rina->is_active);
        $this->assertSame('Keuangan', $rina->department);
        $this->assertSame('Nama Baru', $this->emp->fresh()->name);
        // Data diri pemilik akun juga mengikuti HaloDek; peran tetap, dan ikut nonaktif bila nonaktif di HaloDek.
        $this->assertSame('Akun Tidak Diubah', $this->it->fresh()->name);
        $this->assertSame(User::ROLE_KEPALA_REGU, $this->it->fresh()->role);
        $this->assertFalse($this->it->fresh()->is_active);
        $this->assertTrue($this->super->fresh()->is_active, 'superadmin tidak pernah dinonaktifkan sinkron');
        $this->assertFalse(User::where('nik', '55555')->value('is_active'), 'karyawan yang tidak ada di HaloDek dinonaktifkan');

        // Tidak ada isian data diri di Inventaris: hanya "Beri akses" ke orang dari HaloDek.
        $this->actingAs($this->super)->get(route('users.index'))->assertSee('Beri akses')->assertDontSee('Tambah Akun')
            ->assertSee('12345 - Rina Kartika', false)->assertDontSee('name="name"', false);
        $this->actingAs($this->super)->post(route('users.store'), ['nik' => '99999', 'role' => 'staf', 'unit_id' => $this->unitIt->id])->assertSessionHasErrors('nik');
        $this->actingAs($this->super)->post(route('users.store'), ['nik' => '12345 - Rina Kartika', 'role' => 'staf'])->assertSessionHasErrors('unit_id');
        $this->actingAs($this->super)->post(route('users.store'), ['nik' => '12345 - Rina Kartika', 'name' => 'Diabaikan', 'role' => 'staf', 'unit_id' => $this->unitIt->id])->assertSessionHas('tempPassword');
        $rina->refresh();
        $this->assertSame(['staf', 'Rina Kartika'], [$rina->role, $rina->name]);
        $this->assertSame(1, User::where('nik', '12345')->count());
        $this->actingAs($this->super)->post(route('users.store'), ['nik' => '12345', 'role' => 'staf', 'unit_id' => $this->unitIt->id])->assertSessionHasErrors('nik');

        // Ubah akses: hanya peran/unit/status; nama tidak bisa diubah dari sini.
        $this->actingAs($this->super)->patch(route('users.update', $rina), ['name' => 'X', 'role' => 'manajer', 'is_active' => 1])->assertSessionHas('success');
        $this->assertSame(['manajer', 'Rina Kartika', null], [$rina->fresh()->role, $rina->fresh()->name, $rina->fresh()->unit_id]);
    }

    public function test_unknown_holder_nik_fetches_from_halodek_and_failures_are_reported(): void
    {
        $this->fakeHaloDekEmployees([['nik' => '77001', 'name' => 'Karyawan Baru', 'active' => true]]);
        $asset = $this->createAsset(['holder_nik' => '77001']);
        $this->assertSame('Karyawan Baru', $asset->holder->name);

        config(['inventaris.api_token' => 'token-lain-yang-salah-123456']);
        $this->actingAs($this->super)->post(route('users.sync'))->assertSessionHas('error', 'Token API ditolak HaloDek. Samakan INVENTARIS_API_TOKEN di .env kedua aplikasi.');
        config(['inventaris.halodek_url' => '']);
        $this->actingAs($this->super)->get(route('users.index'))->assertSee('Belum tersambung');
    }

    public function test_sync_handles_case_and_bad_rows_without_duplicates(): void
    {
        $this->user(User::ROLE_KARYAWAN, null, ['nik' => 'ab-77', 'name' => 'Lama']);
        $this->fakeHaloDekEmployees([
            ['nik' => 'AB-77', 'name' => 'Baru', 'active' => true],   // beda huruf besar/kecil saja
            ['nik' => 123456, 'name' => 'NIK angka', 'active' => true],
            ['nik' => ['x'], 'name' => 'rusak'], 'teks', ['nik' => '9', 'name' => ''],
        ]);

        $this->actingAs($this->super)->post(route('users.sync'))->assertSessionHas('success');
        if (\DB::getDriverName() === 'mysql') { // MySQL/MariaDB (produksi): NIK tidak peka huruf besar/kecil
            $this->assertSame(1, User::where('nik', 'ab-77')->count());
            $this->assertSame('Baru', User::where('nik', 'ab-77')->value('name'));
        }
        $this->assertTrue(User::where('nik', '123456')->exists());

        // Sinkron kedua tidak membuat apa pun lagi.
        $this->actingAs($this->super)->post(route('users.sync'))->assertSessionHas('success');
        $this->assertSame(1, User::where('nik', '123456')->count());
    }

    public function test_long_stock_note_with_recipient_fits_column(): void
    {
        $item = Consumable::create(['unit_id' => $this->unitIt->id, 'code' => 'TNR', 'name' => 'Toner', 'unit_label' => 'pcs', 'min_stock' => 0]);
        app(\App\Services\StockManager::class)->adjust($item, $this->it, 'masuk', 3);
        $this->actingAs($this->it)->post(route('consumables.adjust', $item), ['type' => 'keluar', 'qty' => 1, 'recipient' => str_repeat('R', 120), 'note' => str_repeat('n', 500)])->assertSessionHas('success');
        $this->assertSame(500, mb_strlen($item->movements()->latest('id')->value('note')));
    }

    public function test_sync_refuses_answer_that_is_not_halodek(): void
    {
        // Mis. HALODEK_URL salah menunjuk ke aplikasi lain yang kebetulan membalas JSON kosong.
        \Illuminate\Support\Facades\Http::fake(['halodek.test/*' => \Illuminate\Support\Facades\Http::response(['data' => []])]);
        $this->actingAs($this->super)->post(route('users.sync'))->assertSessionHas('error');
        $this->assertTrue($this->emp->fresh()->is_active, 'karyawan tidak boleh dinonaktifkan karena jawaban yang salah');
    }

    // ---------------------------------------------------------------- API

    private function api(): array
    {
        return ['Authorization' => 'Bearer test-token-0123456789abcdef', 'Accept' => 'application/json'];
    }

    public function test_api_requires_valid_token(): void
    {
        $this->getJson('/api/v1/ping')->assertStatus(401);
        $this->getJson('/api/v1/ping', ['Authorization' => 'Bearer salah'])->assertStatus(401);
        $this->getJson('/api/v1/ping', $this->api())->assertOk()->assertJsonPath('ok', true);
        config(['inventaris.api_token' => '']);
        $this->getJson('/api/v1/ping', $this->api())->assertStatus(503);
    }

    public function test_api_locations_and_reportable_assets(): void
    {
        $held = $this->createAsset(['holder_nik' => $this->emp->nik]);                       // dipegang emp
        $shared = $this->createAsset(['code' => 'PRN-1', 'name' => 'Printer Finance']);     // tanpa pemegang di Ruang Finance
        $other = $this->createAsset(['code' => 'AST-9', 'holder_nik' => $this->emp2->nik]); // dipegang emp2
        $this->createAsset(['code' => 'OLD-1', 'status' => 'nonaktif']);

        $this->getJson('/api/v1/locations', $this->api())->assertOk()->assertJsonPath('data.0.label', 'Ruang Finance (Gedung A, Lt. 1)');

        $ids = fn ($q) => collect($this->getJson('/api/v1/assets?'.http_build_query($q), $this->api())->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$held->id], $ids(['unit' => 'it', 'holder_nik' => $this->emp->nik]));
        $this->assertSame(collect([$held->id, $shared->id])->sort()->values()->all(), $ids(['unit' => 'it', 'holder_nik' => $this->emp->nik, 'location_id' => $this->loc->id]));
        $this->assertContains($other->id, $ids(['unit' => 'it', 'holder_nik' => $this->emp->nik, 'include_id' => $other->id]));
        $this->assertSame([], $ids(['unit' => 'sarpras', 'holder_nik' => $this->emp->nik]));

        $this->getJson('/api/v1/assets/'.$held->id, $this->api())->assertOk()
            ->assertJsonPath('data.code', 'AST-0001')->assertJsonPath('data.unit', 'it')->assertJsonPath('data.holder.nik', $this->emp->nik);
        $this->getJson('/api/v1/assets/99999', $this->api())->assertNotFound();
    }

    public function test_api_report_events_are_idempotent_and_shown_on_asset(): void
    {
        $asset = $this->createAsset();
        $payload = ['report_id' => 55, 'report_number' => 'IT-20261118-0001', 'asset_id' => $asset->id, 'title' => 'Laptop mati',
            'status' => 'menunggu', 'status_label' => 'Menunggu', 'reporter_name' => 'Budi', 'url' => 'http://halodek.test/admin/laporan/55', 'reported_at' => now()->toIso8601String()];
        $this->postJson('/api/v1/report-events', $payload, $this->api())->assertCreated();
        $this->postJson('/api/v1/report-events', ['status' => 'diproses', 'status_label' => 'Diproses'] + $payload, $this->api())->assertOk();
        $this->assertSame(1, AssetReport::count());
        $this->assertSame('diproses', AssetReport::first()->status);
        $this->postJson('/api/v1/report-events', ['asset_id' => 99999] + $payload, $this->api())->assertNotFound();
        $this->postJson('/api/v1/report-events', ['status' => 'aneh'] + $payload, $this->api())->assertUnprocessable();

        $this->actingAs($this->it)->get(route('assets.show', $asset))->assertSee('IT-20261118-0001')->assertSee('Laporan kendala')->assertSee('1 masih aktif');
        $this->actingAs($this->it)->get(route('dashboard'))->assertSee('Laporan kendala IT-20261118-0001');
    }

    public function test_halodek_reports_become_repair_history_automatically(): void
    {
        $asset = $this->createAsset();
        $base = ['report_id' => 77, 'report_number' => 'IT-20261125-0007', 'asset_id' => $asset->id, 'title' => 'Layar berkedip',
            'status_label' => 'x', 'reporter_name' => 'Budi', 'url' => 'http://halodek.test/admin/laporan/77', 'reported_at' => '2026-11-20T08:00:00+07:00',
            'team' => 'IT', 'category' => 'Komputer & Laptop', 'description' => 'Berkedip saat dinyalakan'];

        // Menunggu: belum ditangani -> belum ada catatan perbaikan.
        $this->postJson('/api/v1/report-events', ['status' => 'menunggu'] + $base, $this->api())->assertCreated();
        $this->assertSame(0, \App\Models\AssetService::count());

        // Diproses: tercatat "Sedang ditangani" + nama petugas; tidak bisa diubah manual.
        $this->postJson('/api/v1/report-events', ['status' => 'diproses', 'handler_name' => 'Dimas Pratama'] + $base, $this->api())->assertOk();
        $svc = \App\Models\AssetService::sole();
        $this->assertSame(['perbaikan', 'dijadwalkan', 'Dimas Pratama', 'IT-20261125-0007'], [$svc->type, $svc->status, $svc->handler_name, $svc->report_number]);
        $this->assertSame('Sedang ditangani', $svc->statusLabel());
        $this->actingAs($this->it)->patch(route('assets.services.update', $svc), ['type' => 'perbaikan', 'status' => 'batal'])->assertSessionHas('error');
        $this->assertSame('dijadwalkan', $svc->fresh()->status);

        // Selesai: hasil = catatan penyelesaian dari HaloDek.
        $this->postJson('/api/v1/report-events', ['status' => 'selesai', 'handler_name' => 'Dimas Pratama', 'resolution' => 'Kabel LVDS diganti', 'resolved_at' => '2026-11-21T10:00:00+07:00'] + $base, $this->api())->assertOk();
        $svc->refresh();
        $this->assertSame(['selesai', '2026-11-21', 'Kabel LVDS diganti'], [$svc->status, $svc->completed_at->toDateString(), $svc->result]);
        $this->assertSame(1, \App\Models\AssetService::count());

        $html = $this->actingAs($this->it)->get(route('assets.show', $asset))->assertOk()
            ->assertSee('Kabel LVDS diganti')->assertSee('ditangani Dimas Pratama')->assertSee('Servis &amp; perbaikan terakhir', false)->getContent();
        $this->assertSame(1, substr_count($html, 'IT-20261125-0007 </strong>') + substr_count($html, 'laporan IT-20261125-0007'), 'laporan tampil sekali sebagai perbaikan');

        // Dibuka ulang lalu ditolak -> catatan jadi Batal, tidak dihapus.
        $this->postJson('/api/v1/report-events', ['status' => 'ditolak', 'resolution' => 'Bukan kerusakan'] + $base, $this->api())->assertOk();
        $this->assertSame('batal', $svc->fresh()->status);

        // Laporan ditolak tanpa pernah ditangani -> tidak membuat catatan.
        $this->postJson('/api/v1/report-events', ['report_id' => 78, 'report_number' => 'IT-20261125-0008', 'status' => 'ditolak'] + $base, $this->api())->assertCreated();
        $this->assertSame(1, \App\Models\AssetService::count());
    }

    public function test_qr_check_page_is_public_with_secret_token(): void
    {
        $asset = $this->createAsset(['serial_number' => 'SN-123']);
        $url = $asset->verifyUrl();
        $this->assertStringContainsString('/cek/'.$asset->id.'/', $url);

        $path = parse_url($url, PHP_URL_PATH);
        $this->app['auth']->forgetGuards(); // tamu (pemindai QR tanpa login)
        $this->get($path)->assertOk()->assertSee('Aset terdaftar')->assertSee($asset->code)->assertSee('SN-123')
            ->assertDontSee('Buka di Inventaris');
        $this->get('/cek/'.$asset->id.'/'.str_repeat('a', 20))->assertNotFound();
        $this->get('/cek/999999/'.$asset->verifyToken())->assertNotFound();

        // Pengelola yang sedang masuk mendapat tombol ke halaman aset.
        $this->actingAs($this->it)->get($path)->assertSee('Buka di Inventaris');

        $asset->update(['status' => 'nonaktif']);
        $this->app['auth']->forgetGuards();
        $this->get($path)->assertOk()->assertSee('Aset sudah tidak aktif');

        // Stiker QR memakai alamat cek aset.
        $asset->update(['status' => 'tersedia']);
        $this->actingAs($this->it)->get(route('assets.qr', ['asset' => $asset->id]))->assertSee($url, false)->assertSee('Scan untuk cek aset');
    }

    public function test_bulk_grant_access_by_csv(): void
    {
        $this->user(User::ROLE_KARYAWAN, null, ['nik' => '61001', 'name' => 'A']);
        $this->user(User::ROLE_KARYAWAN, null, ['nik' => '61002', 'name' => 'B']);
        $csv = "nik,role,unit\n61001,staf,it\n61002,staf,\n61003,manajer,\n";
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('a.csv', $csv);
        $this->actingAs($this->super)->post(route('users.import'), ['file' => $file])->assertSessionHas('success');
        $this->assertSame('staf', User::where('nik', '61001')->value('role'));
        $this->assertSame('karyawan', User::where('nik', '61002')->value('role'), 'staf tanpa unit ditolak');
        $this->assertNull(User::where('nik', '61003')->first(), 'NIK yang tidak ada di HaloDek ditolak');
    }

    public function test_no_inline_event_handlers(): void
    {
        foreach (glob(resource_path('views/**/*.blade.php')) ?: [] as $f) {
            $this->assertDoesNotMatchRegularExpression('/\son(click|load|change|submit|input)=/', file_get_contents($f), $f);
        }
    }

    // ------------------------------------------------------- audit 2026-11-26

    public function test_status_stays_consistent_with_holder(): void
    {
        $asset = $this->createAsset(['holder_nik' => $this->emp->nik]);
        $this->assertSame('digunakan', $asset->status);
        $base = ['category_id' => $asset->category_id, 'code' => $asset->code, 'name' => $asset->name, 'condition' => 'baik'];

        // Masih dipegang: tidak bisa langsung "Tersedia"/"Nonaktif".
        $this->actingAs($this->it)->patch(route('assets.update', $asset), $base + ['status' => 'tersedia'])->assertSessionHasErrors('status');
        $this->actingAs($this->it)->patch(route('assets.update', $asset), $base + ['status' => 'perbaikan'])->assertSessionHasNoErrors();
        $this->assertSame('perbaikan', $asset->fresh()->status);

        // Nonaktif sejak awal tapi ada pemegang: ditolak.
        $this->actingAs($this->it)->post(route('assets.store'), [
            'unit_id' => $this->unitIt->id, 'category_id' => $this->laptopCat()->id, 'code' => 'AST-0002', 'name' => 'X',
            'status' => 'nonaktif', 'condition' => 'baik', 'holder_nik' => $this->emp->nik,
        ])->assertSessionHasErrors('status');
    }

    public function test_inactive_employee_cannot_receive_asset(): void
    {
        $asset = $this->createAsset();
        $this->emp2->update(['is_active' => false]);
        $this->actingAs($this->it)->post(route('assets.move', $asset), ['type' => 'serah_terima', 'holder_nik' => $this->emp2->nik])
            ->assertSessionHasErrors(['holder_nik' => "{$this->emp2->name} ({$this->emp2->nik}) sudah nonaktif, tidak bisa menerima aset."]);
    }

    public function test_dashboard_with_stock_below_minimum_and_grouped_reports(): void
    {
        $cat = Category::create(['unit_id' => $this->unitIt->id, 'type' => 'stok', 'name' => 'Tinta']);
        \App\Models\Consumable::create(['unit_id' => $this->unitIt->id, 'category_id' => $cat->id, 'code' => 'T1', 'name' => 'Tinta Hitam', 'unit_label' => 'botol', 'stock' => 1, 'min_stock' => 5, 'is_active' => true]);
        $asset = $this->createAsset();
        foreach ([1, 2, 3] as $n) {
            \App\Models\AssetReport::create(['asset_id' => $asset->id, 'report_id' => 900 + $n, 'report_number' => 'IT-X-'.$n, 'title' => 'Rusak', 'status' => 'menunggu', 'status_label' => 'Menunggu', 'reported_at' => now()->subMinutes($n)]);
        }
        $this->actingAs($this->it)->get(route('dashboard'))->assertOk()->assertSee('Tinta Hitam')
            ->assertSee('3 laporan kendala aktif, terbaru IT-X-1')->assertDontSee('IT-X-2');
    }

    public function test_qr_sticker_has_both_logos_and_audit_log_is_readable(): void
    {
        $asset = $this->createAsset();
        $this->actingAs($this->it)->get(route('assets.qr', ['asset' => $asset->id]))->assertOk()
            ->assertSee('images/logo-pcs.png', false)->assertSee('images/inventaris-icon.svg', false)->assertSee('Inventaris Umum &amp; IT', false);
        $this->actingAs($this->super)->get(route('audit.index'))->assertOk()->assertSee('Tambah aset')->assertDontSee('>asset.create<', false);
    }

    // ------------------------------------------------------- finalisasi 2026-11-26

    public function test_qr_token_is_stored_and_survives_app_key_change(): void
    {
        $asset = $this->createAsset();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{20}$/', (string) $asset->verify_token);
        $url = $asset->verifyUrl();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]); // APP_KEY diganti
        $this->assertSame($url, $asset->fresh()->verifyUrl());
        $this->get($url)->assertOk()->assertSee($asset->code);
        $this->assertArrayNotHasKey('verify_token', $asset->fresh()->toArray());

        // Alamat stiker: QR_URL lebih diutamakan dari APP_URL; nama komputer diberi peringatan.
        config(['app.url' => 'http://laptop-abc:8082', 'inventaris.qr_url' => null]);
        $this->actingAs($this->it)->get(route('assets.qr', ['asset' => $asset->id]))->assertSee('Stiker memakai nama komputer');
        config(['inventaris.qr_url' => 'http://inventaris.pcs.local']);
        $this->assertStringStartsWith('http://inventaris.pcs.local/cek/', $asset->fresh()->verifyUrl());
        $this->actingAs($this->it)->get(route('assets.qr', ['asset' => $asset->id]))->assertDontSee('Stiker memakai nama komputer');
    }

    public function test_inactive_holder_is_flagged(): void
    {
        $asset = $this->createAsset(['holder_nik' => $this->emp->nik]);
        $this->emp->update(['is_active' => false]);
        $this->actingAs($this->it)->get(route('dashboard'))->assertSee('yang sudah nonaktif');
        $this->actingAs($this->it)->get(route('assets.index', ['perhatian' => 'pemegang']))->assertSee($asset->code);
        $this->actingAs($this->it)->get(route('assets.show', $asset))->assertSee('sudah nonaktif. Alihkan aset ini');
        // Tarik ke gudang tetap bisa.
        $this->actingAs($this->it)->post(route('assets.move', $asset), ['type' => 'tarik'])->assertSessionHasNoErrors();
        $this->assertNull($asset->fresh()->holder_id);
    }

    public function test_holder_and_access_pickers_are_dropdowns_from_database(): void
    {
        $this->emp2->update(['is_active' => false]);
        $this->actingAs($this->it)->get(route('assets.index'))->assertOk()
            ->assertSee('<select class="select " name="holder_nik" id="asset-holder-nik">', false)
            ->assertSee('value="'.$this->emp->nik.'"', false)->assertDontSee('value="'.$this->emp2->nik.'"', false)
            ->assertDontSee('data-people-src', false);
    }

    public function test_api_asset_has_public_check_url(): void
    {
        $asset = $this->createAsset();
        config(['inventaris.api_token' => 'token-uji-0123456789abcdef']);
        $this->withToken('token-uji-0123456789abcdef')->getJson('/api/v1/assets/'.$asset->id)->assertOk()
            ->assertJsonPath('data.check_url', $asset->verifyUrl())->assertJsonMissingPath('data.verify_token');
    }
}
