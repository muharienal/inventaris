<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Category;
use App\Models\Location;
use App\Models\OrgUnit;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Pemegang aset bisa karyawan, bidang, atau divisi; mutasi Alihkan / Pinjamkan ke ketiganya. */
class GroupHolderTest extends TestCase
{
    use RefreshDatabase;

    private User $it;
    private User $emp;
    private Unit $unitIt;
    private Location $loc;
    private OrgUnit $div;
    private OrgUnit $dept;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedUnits();
        $this->it = $this->user(User::ROLE_KEPALA_REGU, 'it');
        $this->emp = $this->user(User::ROLE_KARYAWAN, null, ['division' => 'Keuangan', 'department' => 'Akuntansi']);
        $this->unitIt = Unit::where('code', 'it')->first();
        $this->loc = Location::create(['name' => 'Ruang Finance']);
        $this->div = OrgUnit::create(['type' => 'divisi', 'name' => 'Keuangan']);
        $this->dept = OrgUnit::create(['type' => 'bidang', 'name' => 'Akuntansi', 'parent_id' => $this->div->id]);
    }

    private function create(array $extra = []): Asset
    {
        $this->actingAs($this->it)->post(route('assets.store'), $extra + [
            'unit_id' => $this->unitIt->id, 'category_id' => Category::where('unit_id', $this->unitIt->id)->where('name', 'Laptop & PC')->value('id'),
            'code' => 'PRN-01', 'name' => 'Printer Akuntansi', 'status' => 'tersedia', 'condition' => 'baik', 'location_id' => $this->loc->id,
        ])->assertSessionHasNoErrors();

        return Asset::latest('id')->first();
    }

    public function test_create_with_department_holder_and_form_shows_choices(): void
    {
        $this->actingAs($this->it)->get(route('assets.index', ['unit' => $this->unitIt->id]))->assertOk()
            ->assertSee('data-holder-picker', false)->assertSee('<optgroup label="Divisi Keuangan">', false);

        $a = $this->create(['holder_type' => 'bidang', 'holder_org_id' => $this->dept->id]);
        $this->assertSame([null, $this->dept->id, 'digunakan'], [$a->holder_id, $a->holder_org_id, $a->status]);
        $this->assertSame('Bidang Akuntansi', $a->movements()->first()->to_holder_name);
        $this->actingAs($this->it)->get(route('assets.show', $a))->assertSee('Bidang Akuntansi')->assertSee('Alihkan');
        $this->actingAs($this->it)->get(route('assets.index', ['unit' => $this->unitIt->id, 'q' => 'akuntansi']))->assertSee('PRN-01');

        // Jenis tidak cocok (divisi dipilih tetapi id bidang) ditolak.
        $this->actingAs($this->it)->post(route('assets.store'), [
            'unit_id' => $this->unitIt->id, 'category_id' => $a->category_id, 'code' => 'PRN-02', 'name' => 'X', 'status' => 'tersedia', 'condition' => 'baik',
            'holder_type' => 'divisi', 'holder_org_id' => $this->dept->id,
        ])->assertSessionHasErrors('holder_org_id');
    }

    public function test_transfer_and_lend_between_person_department_and_division(): void
    {
        $a = $this->create(['holder_nik' => $this->emp->nik]);

        // Alihkan karyawan -> divisi.
        $this->actingAs($this->it)->post(route('assets.move', $a), ['type' => 'serah_terima', 'holder_type' => 'divisi', 'holder_org_id' => $this->div->id])->assertSessionHas('success', 'Dialihkan tercatat.');
        $a->refresh();
        $this->assertSame([null, $this->div->id, 'digunakan'], [$a->holder_id, $a->holder_org_id, $a->status]);
        $m = $a->movements()->first();
        $this->assertSame([$this->emp->name, 'Divisi Keuangan', $this->emp->id, $this->div->id], [$m->from_holder_name, $m->to_holder_name, $m->from_holder_id, $m->to_holder_org_id]);

        // Ke pemegang yang sama ditolak.
        $this->actingAs($this->it)->post(route('assets.move', $a), ['type' => 'serah_terima', 'holder_type' => 'divisi', 'holder_org_id' => $this->div->id])->assertSessionHasErrors('holder');
        // Penerima wajib dipilih.
        $this->actingAs($this->it)->post(route('assets.move', $a), ['type' => 'serah_terima', 'holder_type' => 'bidang'])->assertSessionHasErrors('holder_org_id');

        // Pinjamkan divisi -> bidang, lalu kembali ke divisi.
        $this->actingAs($this->it)->post(route('assets.move', $a), ['type' => 'pinjam', 'holder_type' => 'bidang', 'holder_org_id' => $this->dept->id, 'due_back_at' => now()->addDays(2)->toDateString()])->assertSessionHas('success');
        $a->refresh();
        $this->assertSame([$this->dept->id, 'dipinjam'], [$a->holder_org_id, $a->status]);
        $this->actingAs($this->it)->post(route('assets.move', $a), ['type' => 'kembali'])->assertSessionHas('success');
        $a->refresh();
        $this->assertSame([null, $this->div->id, 'digunakan'], [$a->holder_id, $a->holder_org_id, $a->status]);

        // Alihkan divisi -> karyawan: pemegang bidang/divisi dikosongkan.
        $this->actingAs($this->it)->post(route('assets.move', $a), ['type' => 'serah_terima', 'holder_type' => 'orang', 'holder_nik' => $this->emp->nik])->assertSessionHas('success');
        $a->refresh();
        $this->assertSame([$this->emp->id, null], [$a->holder_id, $a->holder_org_id]);

        // Tarik ke gudang.
        $this->actingAs($this->it)->post(route('assets.move', $a), ['type' => 'tarik'])->assertSessionHas('success');
        $a->refresh();
        $this->assertSame([null, null, 'tersedia'], [$a->holder_id, $a->holder_org_id, $a->status]);
        $this->actingAs($this->it)->post(route('assets.move', $a), ['type' => 'tarik'])->assertSessionHasErrors('type');
    }

    public function test_status_rules_and_inactive_department(): void
    {
        $a = $this->create(['holder_type' => 'bidang', 'holder_org_id' => $this->dept->id]);
        $this->actingAs($this->it)->patch(route('assets.update', $a), [
            'category_id' => $a->category_id, 'code' => $a->code, 'name' => $a->name, 'status' => 'tersedia', 'condition' => 'baik',
        ])->assertSessionHasErrors('status');

        $this->dept->update(['is_active' => false]);
        $this->actingAs($this->it)->get(route('dashboard'))->assertSee('Bidang Akuntansi yang sudah nonaktif');
        $this->actingAs($this->it)->get(route('assets.index', ['unit' => $this->unitIt->id, 'perhatian' => 'pemegang']))->assertSee('PRN-01');
        $this->actingAs($this->it)->post(route('assets.move', $a), ['type' => 'pinjam', 'holder_type' => 'bidang', 'holder_org_id' => $this->dept->id, 'due_back_at' => now()->addDay()->toDateString()])
            ->assertSessionHasErrors('holder_org_id');
    }

    public function test_api_lists_items_of_own_department_and_division(): void
    {
        $dept = $this->create(['holder_type' => 'bidang', 'holder_org_id' => $this->dept->id]);
        $div = $this->create(['code' => 'PRJ-01', 'name' => 'Proyektor', 'holder_type' => 'divisi', 'holder_org_id' => $this->div->id, 'location_id' => null]);
        $other = OrgUnit::create(['type' => 'bidang', 'name' => 'SDM']);
        $hr = $this->create(['code' => 'PRN-09', 'holder_type' => 'bidang', 'holder_org_id' => $other->id, 'location_id' => null]);

        $api = ['Authorization' => 'Bearer test-token-0123456789abcdef', 'Accept' => 'application/json'];
        $ids = fn ($q) => collect($this->getJson('/api/v1/assets?'.http_build_query($q), $api)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$dept->id, $div->id], $ids(['unit' => 'it', 'holder_nik' => $this->emp->nik]));
        // Nama bidang dari HaloDek (lebih baru) diutamakan.
        $this->assertSame([$hr->id], $ids(['unit' => 'it', 'holder_nik' => $this->emp->nik, 'holder_department' => 'SDM', 'holder_division' => 'Lainnya']));

        $this->getJson('/api/v1/assets/'.$dept->id, $api)->assertJsonPath('data.holder', ['type' => 'bidang', 'nik' => null, 'name' => 'Bidang Akuntansi']);
        $this->get($dept->verifyUrl())->assertOk()->assertSee('Bidang Akuntansi');
    }

    public function test_csv_import_accepts_department_or_division_holder(): void
    {
        $csv = "category,code,name,status,condition,holder_nik,holder_org,location\n"
            ."Laptop & PC,IMP-1,Printer A,tersedia,baik,,Bidang Akuntansi,Ruang Finance\n"
            ."Laptop & PC,IMP-2,Printer B,tersedia,baik,,Divisi Keuangan,\n"
            ."Laptop & PC,IMP-3,Printer C,tersedia,baik,,Bidang Tidak Ada,\n";
        $this->actingAs($this->it)->post(route('assets.import'), ['unit_id' => $this->unitIt->id, 'file' => UploadedFile::fake()->createWithContent('a.csv', $csv)])
            ->assertSessionHas('success', '2 aset berhasil diimpor. 1 baris dilewati.');
        $this->assertSame([$this->dept->id, 'digunakan'], [Asset::where('code', 'IMP-1')->value('holder_org_id'), Asset::where('code', 'IMP-1')->value('status')]);
        $this->assertSame($this->div->id, Asset::where('code', 'IMP-2')->value('holder_org_id'));
    }

    public function test_structure_is_synced_from_halodek_by_id(): void
    {
        $this->dept->update(['halodek_id' => 7]);
        Http::fake([
            'halodek.test/api/v1/employees' => Http::response(['app' => 'HaloDek', 'data' => [['nik' => $this->emp->nik, 'name' => 'Emp', 'active' => true]]]),
            'halodek.test/api/v1/organization' => Http::response(['app' => 'HaloDek', 'data' => [
                'divisions' => [['id' => 3, 'name' => 'Keuangan', 'sort_order' => 1], ['id' => 4, 'name' => 'SDM, Umum & K3', 'sort_order' => 2]],
                'departments' => [['id' => 7, 'division_id' => 4, 'name' => 'Akuntansi & Pajak', 'sort_order' => 1], ['id' => 8, 'division_id' => 4, 'name' => 'Umum & IT', 'sort_order' => 2]],
            ]]),
        ]);
        $admin = $this->user(User::ROLE_SUPERADMIN);
        $this->actingAs($admin)->post(route('users.sync'))->assertSessionHas('success');

        // Bidang id 7 diganti nama & pindah divisi; divisi "Keuangan" lokal diadopsi (id 3).
        $this->dept->refresh();
        $this->assertSame(['Akuntansi & Pajak', true], [$this->dept->name, $this->dept->is_active]);
        $this->assertSame('SDM, Umum & K3', $this->dept->parent->name);
        $this->assertSame(3, $this->div->fresh()->halodek_id);
        $this->assertSame(4, OrgUnit::where('type', 'bidang')->where('is_active', true)->count() + OrgUnit::where('type', 'divisi')->where('is_active', true)->count());

        // Yang hilang dari HaloDek dinonaktifkan (tidak dihapus).
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['halodek.test/api/v1/employees' => Http::response(['app' => 'HaloDek', 'data' => [['nik' => $this->emp->nik, 'name' => 'Emp', 'active' => true]]]),
            'halodek.test/api/v1/organization' => Http::response(['app' => 'HaloDek', 'data' => ['divisions' => [['id' => 3, 'name' => 'Keuangan']], 'departments' => []]])]);
        $this->actingAs($admin)->post(route('users.sync'));
        $this->assertFalse($this->dept->fresh()->is_active);
        $this->assertTrue($this->div->fresh()->is_active);
    }

    public function test_structure_from_people_when_halodek_has_no_organization_api(): void
    {
        Http::fake([
            'halodek.test/api/v1/employees' => Http::response(['app' => 'HaloDek', 'data' => [['nik' => '90001', 'name' => 'Baru', 'division' => 'Operasi', 'department' => 'Produksi', 'active' => true]]]),
            'halodek.test/api/v1/organization' => Http::response(['message' => 'Not Found'], 404),
        ]);
        $this->actingAs($this->user(User::ROLE_SUPERADMIN))->post(route('users.sync'))->assertSessionHas('success');
        $prod = OrgUnit::where('type', 'bidang')->where('name', 'Produksi')->first();
        $this->assertSame('Operasi', $prod?->parent?->name);
    }

    public function test_wording_uses_regu_and_long_dashes_become_normal(): void
    {
        $this->actingAs($this->it)->post(route('locations.store'), ['name' => "Ruang Arsip \u{2014} Lt. 3"])->assertSessionHasNoErrors();
        $this->assertTrue(Location::where('name', 'Ruang Arsip - Lt. 3')->exists());

        $admin = $this->user(User::ROLE_SUPERADMIN);
        $this->actingAs($admin)->get(route('users.index'))->assertSee('Regu kelolaan')->assertDontSee('Unit kelolaan')->assertDontSee('<th>Unit</th>', false);
        $this->actingAs($admin)->get(route('dashboard'))->assertSee('Per Regu');
        $this->assertStringContainsString('nik,role,regu', $this->actingAs($admin)->get(route('users.template'))->streamedContent());
    }
}
