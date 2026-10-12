<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignSpend extends TenantModel
{
    protected $table = 'campaign_spend';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2']; // spend_date kept as a plain Y-m-d string (DATE column)
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
