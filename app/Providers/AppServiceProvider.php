<?php

namespace App\Providers;

use Carbon\Carbon;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Carbon::setLocale('id');
        setlocale(LC_TIME, 'id_ID.UTF-8', 'id_ID', 'Indonesian');

        // @manage ... @endmanage: hanya untuk peran yang boleh mengubah data (bukan manajer supervisi).
        \Illuminate\Support\Facades\Blade::if('manage', fn () => (bool) auth()->user()?->canManage());
        // @lead ... @endlead: kategori, lokasi, hapus, impor (Superadmin & Kepala Regu).
        \Illuminate\Support\Facades\Blade::if('lead', fn () => (bool) auth()->user()?->canLead());

        Paginator::defaultView('pagination.app');
        Paginator::defaultSimpleView('pagination.app');

    }
}
