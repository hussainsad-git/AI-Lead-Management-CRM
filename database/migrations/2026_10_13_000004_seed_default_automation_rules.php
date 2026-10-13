<?php

use App\Models\Organization;
use App\Services\Automation\DefaultRules;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Organization::query()->each(fn ($org) => app(CurrentOrganization::class)->runAs($org, fn () => app(DefaultRules::class)->seed()));
    }

    public function down(): void {}
};
