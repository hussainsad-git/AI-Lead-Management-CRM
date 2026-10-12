<?php

namespace Database\Seeders;

use App\Services\PermissionCatalog;
use Illuminate\Database\Seeder;

/** Production-safe: only the global permission catalogue. Create organizations with `php artisan leadflow:install`. */
class DatabaseSeeder extends Seeder
{
    public function run(PermissionCatalog $catalog): void
    {
        $catalog->sync();
    }
}
