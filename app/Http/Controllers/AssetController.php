<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesCsvImport;
use App\Models\Asset;
use App\Models\AssetPhoto;
use App\Models\AssetService;
use App\Models\Category;
use App\Models\Location;
use App\Models\OrgUnit;
use App\Models\Unit;
use App\Models\User;
use App\Services\AssetManager;
use App\Support\Audit;
use App\Support\Csv;
use App\Support\Xlsx;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Aset (peralatan, perangkat, kendaraan, fasilitas) per unit.
 *  - Pengelola: kelola aset unitnya. Superadmin: semua unit.
 *  - Pemegang: satu karyawan, satu bidang, atau satu divisi (struktur dari HaloDek).
 * Pemegang & lokasi TIDAK diubah lewat form edit, tetapi lewat mutasi agar riwayatnya tercatat.
 */
class AssetController extends Controller
{
    use HandlesCsvImport;

    private const CSV_HEADER = [
        'category', 'code', 'name', 'brand', 'model', 'serial_number', 'status', 'condition', 'holder_nik', 'holder_org', 'location',
        'purchase_date', 'vendor', 'due_date', 'next_service_at', 'service_interval_months', 'specs', 'notes',
    ];

    public function __construct(private AssetManager $manager) {}

    // ----------------------------------------------------------------- daftar

    public function index(Request $request): View
    {
        $unit = $this->currentUnit($request->integer('unit'));
        $f = $this->filters($request);

        $assets = $this->query($unit, $f)
            ->with(['category', 'holder', 'holderOrg', 'location'])
            ->orderBy('code')
            ->paginate(config('inventaris.per_page'))
            ->withQueryString();

        $statusCounts = Asset::where('unit_id', $unit->id)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return view('assets.index', [
            'units' => $this->managedUnits(),
            'unit' => $unit,
            'f' => $f,
            'assets' => $assets,
            'statusCounts' => $statusCounts,
            'categories' => $unit->categories()->where('type', 'aset')->withCount('assets')->get(),
            'locations' => Location::orderBy('name')->get(['id', 'name', 'building', 'floor']),
            'org' => OrgUnit::choices(),
        ]);
    }

    public function show(Request $request, Asset $asset): View
    {
        $user = $request->user();
        $this->authorizeUnit($asset->unit_id);

        $asset->load(['unit', 'category', 'holder', 'holderOrg', 'location', 'photos']);

        // Riwayat gabungan: mutasi + servis + laporan kendala HaloDek, terbaru di atas.
        $timeline = collect()
            ->merge($asset->movements()->with(['user', 'fromLocation', 'toLocation'])->get()->map(fn ($m) => ['kind' => 'move', 'at' => $m->created_at, 'item' => $m]))
            ->merge($asset->services()->with(['user', 'assetReport'])->get()->map(fn ($s) => ['kind' => 'service', 'at' => $s->completed_at ?? $s->scheduled_at ?? $s->created_at, 'item' => $s]))
            // Laporan yang sudah menjadi catatan perbaikan cukup tampil sekali (sebagai perbaikan).
            ->merge($asset->reports()->whereDoesntHave('service')->get()->map(fn ($r) => ['kind' => 'report', 'at' => $r->reported_at ?? $r->created_at, 'item' => $r]))
            ->sortByDesc(fn ($e) => $e['at']?->getTimestamp() ?? 0)->values();

        return view('assets.show', [
            'asset' => $asset,
            'canManage' => $user->canManage() && $user->managesUnit($asset->unit_id),
            'timeline' => $timeline,
            'services' => $asset->services()->with('assetReport')->orderByRaw("CASE status WHEN 'dijadwalkan' THEN 0 ELSE 1 END")->latest('scheduled_at')->latest('id')->get(),
            'loan' => $asset->openLoan(),
            'openReports' => $asset->reports()->whereIn('status', ['menunggu', 'diproses'])->count(),
            'locations' => Location::orderBy('name')->get(['id', 'name', 'building', 'floor']),
            'categories' => Category::where('unit_id', $asset->unit_id)->where('type', 'aset')->orderBy('name')->get(),
            'org' => OrgUnit::choices(),
        ]);
    }

    // ------------------------------------------------------------ tambah/ubah

    public function store(Request $request): RedirectResponse
    {
        $unit = $this->currentUnit($request->integer('unit_id'));
        $data = $this->validated($request, $unit);
        [$person, $org] = $this->holderFromInput($request->only(['holder_type', 'holder_nik', 'holder_org_id']));
        $held = $person || $org;
        if ($held && $data['status'] === 'nonaktif') {
            throw ValidationException::withMessages(['status' => 'Aset nonaktif tidak bisa langsung punya pemegang. Kosongkan pemegang atau pilih status lain.']);
        }

        $asset = DB::transaction(function () use ($unit, $data, $person, $org, $held, $request) {
            $asset = Asset::create(array_merge($data, [
                'unit_id' => $unit->id,
                'holder_id' => $person?->id,
                'holder_org_id' => $org?->id,
                'location_id' => $request->integer('location_id') ?: null,
                // Diisi pemegang saat didaftarkan -> otomatis "Digunakan".
                'status' => $held && $data['status'] === 'tersedia' ? 'digunakan' : $data['status'],
            ]));
            // Catatan awal agar riwayat pemegang dimulai sejak aset didaftarkan.
            $asset->movements()->create([
                'type' => $held ? 'serah_terima' : 'pindah',
                'to_holder_id' => $person?->id, 'to_holder_org_id' => $org?->id, 'to_holder_name' => $person?->name ?? $org?->label(),
                'to_location_id' => $asset->location_id, 'note' => 'Pencatatan awal aset.', 'user_id' => $request->user()->id,
            ]);

            return $asset;
        });

        $this->storePhotos($request, $asset);
        Audit::log('asset.create', "Menambah aset {$asset->code} ({$asset->name}) di regu {$unit->name}.", $asset);

        return redirect()->route('assets.show', $asset)->with('success', "Aset {$asset->code} ditambahkan.");
    }

    public function update(Request $request, Asset $asset): RedirectResponse
    {
        $this->authorizeUnit($asset->unit_id);
        $data = $this->validated($request, $asset->unit, $asset);

        // Status "Dipinjam" hanya lewat mutasi; status lain boleh diubah langsung.
        if ($asset->status === 'dipinjam' && $data['status'] !== 'dipinjam') {
            unset($data['status']);
        }
        // Status harus selaras dengan pemegang: pemegang hanya berubah lewat mutasi agar riwayatnya tercatat.
        if (isset($data['status']) && $data['status'] !== $asset->status) {
            if ($asset->hasHolder() && in_array($data['status'], ['tersedia', 'nonaktif'], true)) {
                throw ValidationException::withMessages(['status' => 'Aset masih dipegang '.($asset->holderName() ?? 'pemegang lain').'. Pakai tombol "Tarik ke Gudang" dulu, lalu ubah statusnya.']);
            }
            if (! $asset->hasHolder() && $data['status'] === 'digunakan' && ! $asset->location_id) {
                throw ValidationException::withMessages(['status' => 'Pakai tombol "Alihkan" untuk mencatat pemakainya (karyawan, bidang, atau divisi), atau isi lokasi aset bila dipakai bersama.']);
            }
        }
        $asset->update($data);
        Audit::log('asset.update', "Mengubah data aset {$asset->code}.", $asset);

        return back()->with('success', 'Data aset diperbarui.');
    }

    public function destroy(Asset $asset): RedirectResponse
    {
        $this->authorizeUnit($asset->unit_id);

        $label = $asset->label();
        DB::transaction(function () use ($asset) {
            $asset->photos->each->delete();   // berkas foto ikut terhapus
            $asset->delete();
        });
        Audit::log('asset.delete', "Menghapus aset {$label}.");

        return redirect()->route('assets.index', ['unit' => $asset->unit_id])->with('success', "Aset {$label} dihapus.");
    }

    // ------------------------------------------------------------------- foto

    public function addPhotos(Request $request, Asset $asset): RedirectResponse
    {
        $this->authorizeUnit($asset->unit_id);
        $max = config('inventaris.photos.max_files');
        $left = $max - $asset->photos()->count();
        if ($left <= 0) {
            return back()->with('error', "Maksimal {$max} foto per aset. Hapus foto lama dulu.");
        }
        $request->validate([
            'photos' => ['required', 'array', 'max:'.$left],
            'photos.*' => ['image', 'mimes:'.implode(',', config('inventaris.photos.mimes')), 'max:'.config('inventaris.photos.max_kb')],
        ], ['photos.max' => "Sisa slot foto: {$left}.", 'photos.required' => 'Pilih foto terlebih dahulu.']);
        $n = $this->storePhotos($request, $asset);

        return back()->with('success', "{$n} foto ditambahkan.");
    }

    public function deletePhoto(AssetPhoto $photo): RedirectResponse
    {
        $this->authorizeUnit($photo->asset->unit_id);
        $photo->delete();

        return back()->with('success', 'Foto dihapus.');
    }

    public function photo(Request $request, AssetPhoto $photo)
    {
        $asset = $photo->asset;
        $user = $request->user();
        abort_unless($user->managesUnit($asset->unit_id), 403);
        abort_unless(Storage::disk('local')->exists($photo->path), 404);

        return response()->file(Storage::disk('local')->path($photo->path), [
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // ----------------------------------------------------------------- mutasi

    public function move(Request $request, Asset $asset): RedirectResponse
    {
        $this->authorizeUnit($asset->unit_id);
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(config('inventaris.movements')))],
            'holder_type' => ['nullable', Rule::in(['orang', 'bidang', 'divisi'])],
            'holder_nik' => ['nullable', 'string', 'max:80'],
            'holder_org_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')],
            'due_back_at' => ['nullable', 'date', 'after_or_equal:today'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], ['due_back_at.after_or_equal' => 'Batas pengembalian tidak boleh sebelum hari ini.']);

        [$person, $org] = in_array($data['type'], ['serah_terima', 'pinjam'], true) ? $this->holderFromInput($data, true) : [null, null];
        $movement = $this->manager->move($asset, $request->user(), $data['type'], ['holder_id' => $person?->id, 'holder_org_id' => $org?->id] + $data);

        return back()->with('success', "{$movement->typeLabel()} tercatat.");
    }

    // ----------------------------------------------------------------- servis

    public function addService(Request $request, Asset $asset): RedirectResponse
    {
        $this->authorizeUnit($asset->unit_id);
        $data = $this->serviceData($request, true);
        $service = $this->manager->addService($asset, $request->user(), $data);

        return back()->with('success', $service->status === 'selesai' ? 'Riwayat servis dicatat.' : 'Servis dijadwalkan.');
    }

    public function updateService(Request $request, AssetService $service): RedirectResponse
    {
        $this->authorizeUnit($service->asset->unit_id);
        if ($service->isFromHaloDek()) {
            return back()->with('error', 'Perbaikan ini mengikuti laporan '.$service->report_number.' di HaloDek dan diperbarui otomatis dari sana.');
        }
        $data = $this->serviceData($request, false);
        $this->manager->updateService($service, $request->user(), $data);

        return back()->with('success', 'Servis diperbarui.');
    }

    // --------------------------------------------------------- QR & ekspor/impor

    /** Lembar stiker QR. QR membuka HaloDek (form lapor terisi otomatis); bila HaloDek belum diatur, membuka halaman aset ini. */
    public function qr(Request $request): View
    {
        if ($id = $request->integer('asset')) {
            $asset = Asset::with(['location', 'unit'])->findOrFail($id);
            $this->authorizeUnit($asset->unit_id);
            $assets = collect([$asset]);
            $title = $asset->label();
        } else {
            $unit = $this->currentUnit($request->integer('unit'));
            $assets = $this->query($unit, $this->filters($request))->active()->with(['location', 'unit'])->orderBy('code')->limit(300)->get();
            $title = 'Regu '.$unit->name;
        }

        return view('assets.qr', ['assets' => $assets, 'title' => $title, 'halodek' => (bool) config('inventaris.halodek_url')]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $unit = $this->currentUnit($request->integer('unit'));
        $h = fn (string $t) => ['v' => $t, 's' => 'head'];
        $rows = [array_map($h, ['Kode', 'Nama', 'Kategori', 'Merek', 'Model', 'No. Seri', 'Status', 'Kondisi', 'Pemegang', 'NIK Pemegang', 'Lokasi',
            'Tgl Beli', 'Vendor', 'Garansi/Lisensi s.d.', 'Servis Berikutnya', 'Spesifikasi', 'Catatan'])];

        foreach ($this->query($unit, $this->filters($request))->with(['category', 'holder', 'holderOrg', 'location'])->orderBy('code')->cursor() as $a) {
            $rows[] = [
                $a->code, $a->name, $a->category->name ?? '', (string) $a->brand, (string) $a->model, (string) $a->serial_number,
                $a->statusLabel(), $a->conditionLabel(), $a->holderName() ?? '', $a->holder_id ? ($a->holder->nik ?? '') : '', $a->location?->fullName() ?? '',
                $a->purchase_date?->format('Y-m-d') ?? '', (string) $a->vendor,
                $a->due_date?->format('Y-m-d') ?? '', $a->next_service_at?->format('Y-m-d') ?? '', (string) $a->specs, (string) $a->notes,
            ];
        }

        $path = Xlsx::build([[
            'name' => 'Aset '.$unit->name,
            'widths' => [14, 32, 18, 14, 18, 18, 12, 13, 22, 12, 24, 12, 18, 16, 16, 40, 30],
            'rows' => $rows,
        ]]);

        return response()->download($path, 'aset-'.$unit->code.'-'.now()->format('Ymd').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    public function template(): StreamedResponse
    {
        return Csv::download('template-impor-aset.csv', self::CSV_HEADER, [
            ['Laptop & PC', 'AST-0147', 'Lenovo ThinkPad E14', 'Lenovo', 'ThinkPad E14 Gen 5', 'PF4ABC12', 'digunakan', 'baik', '00004', '', 'Ruang Finance',
                '2026-01-15', 'PT Sumber Komputer', '2028-01-15', '2026-07-15', '6', 'i5-1335U, 16GB, SSD 512GB', ''],
            ['AC', 'AC-012', 'AC Split 1 PK Ruang Rapat', 'Daikin', 'FTKC25', '', 'digunakan', 'baik', '', 'Bidang Umum & IT', 'Ruang Rapat Utama',
                '2025-06-01', 'CV Sejuk', '', '2026-12-01', '3', '', 'Cuci AC tiap 3 bulan'],
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        $unit = $this->currentUnit($request->integer('unit_id'));
        abort_unless($request->user()->managesUnit($unit), 403);

        $result = $this->runImport($request, ['category', 'code', 'name'], function (array $d) use ($unit, $request) {
            $d['status'] = ($d['status'] ?? '') ?: 'tersedia';
            $d['condition'] = ($d['condition'] ?? '') ?: 'baik';
            $v = Validator::make($d, [
                'category' => ['required', 'string', 'max:100'],
                'code' => ['required', 'string', 'max:50', Rule::unique('assets', 'code')->where('unit_id', $unit->id)],
                'name' => ['required', 'string', 'max:150'],
                'status' => ['required', Rule::in(array_diff(array_keys(config('inventaris.statuses')), ['dipinjam']))],
                'condition' => ['required', Rule::in(array_keys(config('inventaris.conditions')))],
                'service_interval_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            ], [], ['code' => 'kode'])->validate();

            [$person, $org] = $this->holderFromInput(['holder_nik' => $d['holder_nik'] ?? null, 'holder_org' => $d['holder_org'] ?? null]);
            $held = $person || $org;
            $category = Category::firstOrCreate(['unit_id' => $unit->id, 'type' => 'aset', 'name' => $v['category']]);
            $location = ! empty($d['location']) ? Location::firstOrCreate(['name' => mb_substr(trim($d['location']), 0, 120)]) : null;
            $opt = fn ($k, $len = 255) => ($x = trim((string) ($d[$k] ?? ''))) === '' ? null : mb_substr($x, 0, $len);

            $asset = Asset::create([
                'unit_id' => $unit->id, 'category_id' => $category->id, 'code' => $v['code'], 'name' => $v['name'],
                'brand' => $opt('brand', 80), 'model' => $opt('model', 120), 'serial_number' => $opt('serial_number', 120),
                'status' => $held && $v['status'] === 'tersedia' ? 'digunakan' : $v['status'], 'condition' => $v['condition'],
                'holder_id' => $person?->id, 'holder_org_id' => $org?->id, 'location_id' => $location?->id,
                'purchase_date' => $this->parseDate($d['purchase_date'] ?? null, 'purchase_date'),
                'vendor' => $opt('vendor', 120),
                'due_date' => $this->parseDate($d['due_date'] ?? null, 'due_date'),
                'next_service_at' => $this->parseDate($d['next_service_at'] ?? null, 'next_service_at'),
                'service_interval_months' => $opt('service_interval_months'),
                'specs' => $opt('specs', 3000), 'notes' => $opt('notes', 3000),
            ]);
            $asset->movements()->create([
                'type' => $held ? 'serah_terima' : 'pindah', 'to_holder_id' => $person?->id, 'to_holder_org_id' => $org?->id,
                'to_holder_name' => $person?->name ?? $org?->label(),
                'to_location_id' => $asset->location_id, 'note' => 'Impor CSV.', 'user_id' => $request->user()->id,
            ]);
        });

        Audit::log('asset.import', "Impor aset regu {$unit->name}: {$result['ok']} berhasil.");
        $back = redirect()->route('assets.index', ['unit' => $unit->id]);
        if ($result['fatal']) {
            return $back->with('error', $result['fatal']);
        }

        return $back->with('success', "{$result['ok']} aset berhasil diimpor.".($result['errors'] ? ' '.count($result['errors']).' baris dilewati.' : ''))
            ->with('importErrors', $result['errors']);
    }

    // ------------------------------------------------------------------- util

    /** @return array<string,mixed> */
    private function filters(Request $request): array
    {
        return [
            'status' => $request->query('status', 'aktif'),
            'category' => $request->integer('category') ?: null,
            'q' => trim((string) $request->query('q')),
            'perhatian' => $request->query('perhatian'), // servis | garansi | rusak | pemegang
        ];
    }

    private function query(Unit $unit, array $f): Builder
    {
        $days = config('inventaris.due_soon_days');

        return Asset::query()->where('unit_id', $unit->id)
            ->when($f['status'] === 'aktif', fn ($q) => $q->active())
            ->when(! in_array($f['status'], ['aktif', 'semua'], true), fn ($q) => $q->where('status', $f['status']))
            ->when($f['category'], fn ($q) => $q->where('category_id', $f['category']))
            ->when($f['perhatian'] === 'servis', fn ($q) => $q->serviceDue($days))
            ->when($f['perhatian'] === 'garansi', fn ($q) => $q->dueSoon($days))
            ->when($f['perhatian'] === 'rusak', fn ($q) => $q->where('condition', '!=', 'baik'))
            ->when($f['perhatian'] === 'pemegang', fn ($q) => $q->inactiveHolder())
            ->when($f['q'] !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('code', 'like', "%{$f['q']}%")
                ->orWhere('name', 'like', "%{$f['q']}%")
                ->orWhere('serial_number', 'like', "%{$f['q']}%")
                ->orWhere('brand', 'like', "%{$f['q']}%")
                ->orWhereHas('holder', fn ($h) => $h->where('name', 'like', "%{$f['q']}%")->orWhere('nik', 'like', "%{$f['q']}%"))
                ->orWhereHas('holderOrg', fn ($o) => $o->where('name', 'like', "%{$f['q']}%"))
                ->orWhereHas('location', fn ($l) => $l->where('name', 'like', "%{$f['q']}%"))));
    }

    private function validated(Request $request, Unit $unit, ?Asset $asset = null): array
    {
        $statuses = array_keys(config('inventaris.statuses'));

        $data = $request->validate([
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('unit_id', $unit->id)->where('type', 'aset')],
            'code' => ['required', 'string', 'max:50', Rule::unique('assets', 'code')->where('unit_id', $unit->id)->ignore($asset?->id)],
            'name' => ['required', 'string', 'max:150'],
            'brand' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:120'],
            'serial_number' => ['nullable', 'string', 'max:120'],
            'specs' => ['nullable', 'string', 'max:3000'],
            'status' => ['required', Rule::in($statuses)],
            'condition' => ['required', Rule::in(array_keys(config('inventaris.conditions')))],
            'purchase_date' => ['nullable', 'date', 'before_or_equal:today'],
            'vendor' => ['nullable', 'string', 'max:120'],
            'due_date' => ['nullable', 'date'],
            'next_service_at' => ['nullable', 'date'],
            'service_interval_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')],
            'holder_type' => ['nullable', Rule::in(['orang', 'bidang', 'divisi'])],
            'holder_nik' => ['nullable', 'string', 'max:80'],
            'holder_org_id' => ['nullable', 'integer'],
            'photos' => ['nullable', 'array', 'max:'.config('inventaris.photos.max_files')],
            'photos.*' => ['image', 'mimes:'.implode(',', config('inventaris.photos.mimes')), 'max:'.config('inventaris.photos.max_kb')],
        ], [
            'code.unique' => 'Kode aset sudah dipakai di regu ini.',
            'category_id.required' => 'Pilih kategori aset.',
            'category_id.exists' => 'Kategori tidak sesuai dengan regu.',
            'purchase_date.before_or_equal' => 'Tanggal beli tidak boleh di masa depan.',
        ]);

        // "Dipinjam" hanya boleh lewat mutasi pinjam.
        if ($data['status'] === 'dipinjam' && $asset?->status !== 'dipinjam') {
            throw ValidationException::withMessages(['status' => 'Gunakan tombol Pinjamkan agar tanggal kembali tercatat.']);
        }

        unset($data['location_id'], $data['holder_type'], $data['holder_nik'], $data['holder_org_id'], $data['photos']);

        return $data;
    }

    private function serviceData(Request $request, bool $creating): array
    {
        return $request->validate([
            'type' => [$creating ? 'required' : 'sometimes', Rule::in(array_keys(config('inventaris.service_types')))],
            'status' => ['required', Rule::in(['dijadwalkan', 'selesai', 'batal'])],
            'scheduled_at' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date', 'before_or_equal:today'],
            'vendor' => ['nullable', 'string', 'max:120'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'description' => ['nullable', 'string', 'max:2000'],
            'result' => ['nullable', 'string', 'max:2000'],
            'report_number' => ['nullable', 'string', 'max:40'],
        ], ['completed_at.before_or_equal' => 'Tanggal selesai tidak boleh di masa depan.']);
    }

    /**
     * Pemegang dari isian form / CSV: [karyawan, null], [null, bidang/divisi], atau [null, null].
     *  - Form: holder_type = orang | bidang | divisi, dengan holder_nik atau holder_org_id.
     *  - CSV : holder_nik, atau holder_org berisi "Bidang Umum & IT" / "Divisi Keuangan".
     *
     * @return array{0: ?User, 1: ?OrgUnit}
     */
    private function holderFromInput(array $d, bool $required = false): array
    {
        $type = $d['holder_type'] ?? null;
        $nik = trim((string) ($d['holder_nik'] ?? ''));
        $orgId = (int) ($d['holder_org_id'] ?? 0);
        $orgText = trim((string) ($d['holder_org'] ?? ''));
        $type ??= $nik !== '' ? 'orang' : ($orgId || $orgText !== '' ? 'org' : null);

        if ($type === 'orang' || ($type === null && $nik !== '')) {
            if ($nik === '' && $required) {
                throw ValidationException::withMessages(['holder_nik' => 'Isi NIK atau nama karyawan.']);
            }

            return [$this->holderFromNik($nik), null];
        }
        if ($type === 'bidang' || $type === 'divisi' || $type === 'org') {
            $org = $orgId ? OrgUnit::find($orgId) : ($orgText !== '' ? $this->orgFromText($orgText) : null);
            if (! $org || ($type !== 'org' && $org->type !== $type)) {
                $what = $type === 'divisi' ? 'divisi' : ($type === 'bidang' ? 'bidang' : 'bidang/divisi');
                throw ValidationException::withMessages(['holder_org_id' => $orgText !== '' ? "\"{$orgText}\" tidak ditemukan. Tulis \"Bidang <nama>\" atau \"Divisi <nama>\" sesuai HaloDek." : "Pilih {$what} pemegang."]);
            }
            if (! $org->is_active) {
                throw ValidationException::withMessages(['holder_org_id' => "{$org->label()} sudah tidak aktif di HaloDek, tidak bisa menerima aset."]);
            }

            return [null, $org];
        }
        if ($required) {
            throw ValidationException::withMessages(['holder_type' => 'Pilih penerima: karyawan, bidang, atau divisi.']);
        }

        return [null, null];
    }

    /** "Bidang Umum & IT" / "Divisi Keuangan" / "Umum & IT" (dicari di bidang dulu, lalu divisi). */
    private function orgFromText(string $text): ?OrgUnit
    {
        $type = null;
        if (preg_match('/^(bidang|divisi)\s*[:\-]?\s+(.+)$/iu', $text, $m)) {
            [$type, $text] = [mb_strtolower($m[1]), trim($m[2])];
        }
        $find = fn (string $t) => OrgUnit::where('type', $t)->whereRaw('LOWER(name) = ?', [mb_strtolower($text)])->orderByDesc('is_active')->first();

        return $type ? $find($type) : ($find(OrgUnit::BIDANG) ?? $find(OrgUnit::DIVISI));
    }

    private function holderFromNik(?string $nik): ?User
    {
        $nik = trim((string) $nik);
        if ($nik === '') {
            return null;
        }
        // Datalist mengirim "NIK - Nama"; ambil NIK-nya saja.
        $nik = trim(explode(' - ', $nik)[0]);
        $user = User::where('nik', $nik)->first();
        // NIK belum dikenal: mungkin karyawan baru di HaloDek. Ambil ulang datanya sekali, lalu cari lagi.
        if (! $user && app(\App\Services\EmployeeSync::class)->refreshForUnknownNik()) {
            $user = User::where('nik', $nik)->first();
        }
        if (! $user) {
            throw ValidationException::withMessages(['holder_nik' => "NIK \"{$nik}\" tidak ditemukan. Pastikan karyawan sudah terdaftar di HaloDek."]);
        }
        if (! $user->is_active) {
            throw ValidationException::withMessages(['holder_nik' => "{$user->name} ({$nik}) sudah nonaktif, tidak bisa menerima aset."]);
        }

        return $user;
    }

    private function storePhotos(Request $request, Asset $asset): int
    {
        $n = 0;
        foreach ((array) $request->file('photos', []) as $file) {
            if (! $file || ! $file->isValid()) {
                continue;
            }
            $asset->photos()->create([
                'path' => $file->store('aset/'.now()->format('Y/m'), 'local'),
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 200),
                'size' => (int) $file->getSize(),
            ]);
            $n++;
        }

        return $n;
    }
}
