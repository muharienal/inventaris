<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Daftar Divisi -> Bidang dan Jabatan dari HaloDek (diatur Superadmin HaloDek di Struktur Organisasi),
 * dipakai sebagai pilihan di form akun Inventaris. Disimpan sementara 5 menit.
 */
class OrgStructure
{
    public const CACHE_KEY = 'halodek:struktur';

    /** @return array{divisions: array<int, array{name:string, departments: array<int,string>}>, others: array<int,string>, positions: array<int,string>}|null */
    public function get(): ?array
    {
        $base = rtrim((string) config('inventaris.halodek_url'), '/');
        $token = (string) config('inventaris.api_token');
        if ($base === '' || mb_strlen($token) < 16) {
            return null;
        }

        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached['data'];
        }

        $data = null;
        try {
            $res = Http::withToken($token)->acceptJson()->timeout(5)->connectTimeout(3)->get($base.'/api/v1/organization');
            $j = $res->json();
            if ($res->successful() && is_array($j) && ($j['app'] ?? null) === 'HaloDek') {
                $str = fn ($v) => is_scalar($v) && trim((string) $v) !== '' ? mb_substr(trim((string) $v), 0, 100) : null;
                $data = [
                    'divisions' => collect($j['divisions'] ?? [])->filter(fn ($d) => is_array($d) && $str($d['name'] ?? null))
                        ->map(fn ($d) => ['name' => $str($d['name']), 'departments' => collect($d['departments'] ?? [])->map($str)->filter()->values()->all()])->values()->all(),
                    'others' => collect($j['departments_without_division'] ?? [])->map($str)->filter()->values()->all(),
                    'positions' => collect($j['positions'] ?? [])->map($str)->filter()->values()->all(),
                ];
            }
        } catch (Throwable $e) {
            Log::info('Struktur organisasi HaloDek tidak bisa diambil: '.$e->getMessage());
        }

        // Gagal juga diingat sebentar (1 menit) agar form tidak menunggu HaloDek berulang kali.
        Cache::put(self::CACHE_KEY, ['data' => $data], $data ? now()->addMinutes(5) : now()->addMinute());

        return $data;
    }
}
