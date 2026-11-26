<?php

namespace App\Console\Commands;

use App\Services\EmployeeSync;
use Illuminate\Console\Command;

class SyncEmployees extends Command
{
    protected $signature = 'inventaris:sinkron-karyawan';

    protected $description = 'Ambil data karyawan (pemegang aset) dari HaloDek lewat API';

    public function handle(EmployeeSync $sync): int
    {
        $result = $sync->run();
        $result['ok'] ? $this->info($result['message']) : $this->error($result['message']);

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
