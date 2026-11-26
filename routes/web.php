<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ProfileController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ConsumableController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocationController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

// ===== CEK ASET dari stiker QR: terbuka tanpa login, alamat bertoken per aset =====
Route::middleware('throttle:60,1')->group(function () {
    Route::get('/cek/{asset}/{token}', [\App\Http\Controllers\AssetCheckController::class, 'show'])->whereNumber('asset')->where('token', '[a-f0-9]{20}')->name('assets.check');
    Route::get('/cek/{asset}/{token}/foto/{photo}', [\App\Http\Controllers\AssetCheckController::class, 'photo'])->whereNumber('asset')->where('token', '[a-f0-9]{20}')->name('assets.check.photo');
});

// ===== MASUK: NIK + password (Superadmin, Manajer, Kepala Regu, Staf) =====
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login');
});
Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'account', 'throttle:writes'])->group(function () {

    Route::get('/beranda', DashboardController::class)->name('dashboard');

    Route::get('/profil', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profil', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profil/password', [ProfileController::class, 'password'])->middleware('throttle:password')->name('profile.password');

    // ===== LIHAT (semua yang punya akun: Superadmin, Manajer, Kepala Regu, Staf) =====
    Route::middleware('role:superadmin,manajer,kepala_regu,staf')->group(function () {
        Route::get('/aset', [AssetController::class, 'index'])->name('assets.index');
        Route::get('/aset/{asset}', [AssetController::class, 'show'])->whereNumber('asset')->name('assets.show');
        Route::get('/aset/foto/{photo}', [AssetController::class, 'photo'])->name('assets.photo');
        Route::get('/aset/qr', [AssetController::class, 'qr'])->name('assets.qr');
        Route::get('/aset/ekspor', [AssetController::class, 'export'])->middleware('throttle:bulk')->name('assets.export');

        Route::get('/stok', [ConsumableController::class, 'index'])->name('consumables.index');
        Route::get('/stok/ekspor', [ConsumableController::class, 'export'])->middleware('throttle:bulk')->name('consumables.export');
        Route::get('/stok/{consumable}', [ConsumableController::class, 'show'])->name('consumables.show');

        Route::get('/kategori', [CategoryController::class, 'index'])->name('categories.index');
        Route::get('/lokasi', [LocationController::class, 'index'])->name('locations.index');
    });

    // ===== PEKERJAAN HARIAN (Superadmin, Kepala Regu, Staf; Manajer tidak) =====
    Route::middleware('role:superadmin,kepala_regu,staf')->group(function () {
        Route::post('/aset', [AssetController::class, 'store'])->middleware('throttle:submit')->name('assets.store');
        Route::patch('/aset/{asset}', [AssetController::class, 'update'])->name('assets.update');
        Route::post('/aset/{asset}/foto', [AssetController::class, 'addPhotos'])->middleware('throttle:submit')->name('assets.photos.store');
        Route::delete('/aset/foto/{photo}', [AssetController::class, 'deletePhoto'])->name('assets.photos.destroy');
        Route::post('/aset/{asset}/mutasi', [AssetController::class, 'move'])->name('assets.move');
        Route::post('/aset/{asset}/servis', [AssetController::class, 'addService'])->name('assets.services.store');
        Route::patch('/servis/{service}', [AssetController::class, 'updateService'])->name('assets.services.update');

        Route::post('/stok', [ConsumableController::class, 'store'])->name('consumables.store');
        Route::patch('/stok/{consumable}', [ConsumableController::class, 'update'])->name('consumables.update');
        Route::post('/stok/{consumable}/mutasi', [ConsumableController::class, 'adjust'])->name('consumables.adjust');
    });

    // ===== DATA INDUK, HAPUS & IMPOR (Superadmin & Kepala Regu) =====
    Route::middleware('role:superadmin,kepala_regu')->group(function () {
        Route::get('/aset/template', [AssetController::class, 'template'])->name('assets.template');
        Route::post('/aset/impor', [AssetController::class, 'import'])->middleware('throttle:bulk')->name('assets.import');
        Route::delete('/aset/{asset}', [AssetController::class, 'destroy'])->name('assets.destroy');
        Route::delete('/stok/{consumable}', [ConsumableController::class, 'destroy'])->name('consumables.destroy');

        Route::post('/kategori', [CategoryController::class, 'store'])->name('categories.store');
        Route::patch('/kategori/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('/kategori/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

        Route::get('/lokasi/template', [LocationController::class, 'template'])->name('locations.template');
        Route::post('/lokasi/impor', [LocationController::class, 'import'])->middleware('throttle:bulk')->name('locations.import');
        Route::post('/lokasi', [LocationController::class, 'store'])->name('locations.store');
    });

    Route::get('/log', [AuditLogController::class, 'index'])->middleware('role:superadmin,manajer')->name('audit.index');

    // ===== SUPERADMIN =====
    Route::middleware('role:superadmin')->group(function () {
        // Lokasi dipakai bersama semua unit (dan HaloDek): mengubah/menghapus hanya Superadmin.
        Route::patch('/lokasi/{location}', [LocationController::class, 'update'])->name('locations.update');
        Route::delete('/lokasi/{location}', [LocationController::class, 'destroy'])->name('locations.destroy');

        Route::get('/pengguna', [UserController::class, 'index'])->name('users.index');
        Route::get('/pengguna/template', [UserController::class, 'template'])->name('users.template');
        Route::get('/pengguna/ekspor', [UserController::class, 'export'])->middleware('throttle:bulk')->name('users.export');
        Route::post('/pengguna/impor', [UserController::class, 'import'])->middleware('throttle:bulk')->name('users.import');
        Route::post('/pengguna/sinkron-karyawan', [UserController::class, 'syncEmployees'])->middleware('throttle:bulk')->name('users.sync');
        Route::post('/pengguna', [UserController::class, 'store'])->name('users.store');
        Route::patch('/pengguna/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('/pengguna/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
        Route::patch('/pengguna/{user}/status', [UserController::class, 'toggle'])->name('users.toggle');
        Route::delete('/pengguna/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    });
});
