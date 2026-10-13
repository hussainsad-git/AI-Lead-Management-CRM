<?php

use App\Services\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;

/** Grants permissions introduced in this release to existing system roles. */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionCatalog::class)->sync();
    }

    public function down(): void {}
};
