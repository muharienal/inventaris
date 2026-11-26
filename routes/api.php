<?php

use App\Http\Controllers\Api\InventoryApiController as Api;
use Illuminate\Support\Facades\Route;

// Dipakai HaloDek. Semua rute wajib token (lihat App\Http\Middleware\ApiToken).
Route::get('/ping', [Api::class, 'ping'])->name('ping');
Route::get('/units', [Api::class, 'units'])->name('units');
Route::get('/locations', [Api::class, 'locations'])->name('locations');
Route::get('/assets', [Api::class, 'assets'])->name('assets');
Route::get('/assets/{id}', [Api::class, 'show'])->whereNumber('id')->name('assets.show');
Route::post('/report-events', [Api::class, 'reportEvent'])->name('report-events');
