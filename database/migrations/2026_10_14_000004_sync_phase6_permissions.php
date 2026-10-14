<?php

use App\Services\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(PermissionCatalog::class)->sync();
    }

    public function down(): void {}
};
