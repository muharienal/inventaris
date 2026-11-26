<?php

namespace App\Http\Middleware;

use App\Support\Audit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API untuk HaloDek: wajib header "Authorization: Bearer <INVENTARIS_API_TOKEN>".
 * Token kosong di .env = API dimatikan.
 */
class ApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('inventaris.api_token');
        $given = (string) $request->bearerToken();

        if ($expected === '' || strlen($expected) < 16) {
            return response()->json(['message' => 'API belum diaktifkan. Isi INVENTARIS_API_TOKEN (min. 16 karakter) di .env.'], 503);
        }
        if ($given === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Token API tidak valid.'], 401);
        }

        return $next($request);
    }
}
