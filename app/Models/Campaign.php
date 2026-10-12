<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends TenantModel
{
    protected function casts(): array
    {
        return ['last_synced_at' => 'datetime']; // starts_on/ends_on are plain Y-m-d strings
    }

    public function adSets(): HasMany
    {
        return $this->hasMany(AdSet::class);
    }

    public function ads(): HasMany
    {
        return $this->hasMany(Ad::class);
    }

    public function spend(): HasMany
    {
        return $this->hasMany(CampaignSpend::class);
    }
}
