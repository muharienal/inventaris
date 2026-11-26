<?php

namespace App\Http\Middleware;

use App\Services\EmployeeSync;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Tugas berkala tanpa Task Scheduler: dijalankan SETELAH respons terkirim ke browser.
 * Saat ini: mengambil data karyawan dari HaloDek paling sering sekali per 5 menit.
 */
class RunBackgroundTasks
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        // Permintaan API (dari HaloDek) tidak ikut menjalankan sinkron, agar kedua aplikasi
        // tidak saling menunggu bila berjalan di satu Apache.
        if (app()->runningUnitTests() || $request->is('api/*')) {
            return;
        }

        try {
            EmployeeSync::tick();
        } catch (Throwable $e) {
            Log::warning('Tugas latar gagal', ['error' => $e->getMessage()]);
        }
    }
}
