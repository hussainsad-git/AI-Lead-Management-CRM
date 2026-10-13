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

    /**
     * Spend that counts: Meta API rows win over manual/imported rows for the same campaign and day,
     * so connecting the API after weeks of manual entry never double-counts.
     */
    public function scopeEffective(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->where(fn ($w) => $w->where('campaign_spend.data_source', 'meta_api')
            ->orWhereNotExists(fn ($s) => $s->selectRaw('1')->from('campaign_spend as api')
                ->whereColumn('api.campaign_id', 'campaign_spend.campaign_id')
                ->whereColumn('api.spend_date', 'campaign_spend.spend_date')
                ->where('api.data_source', 'meta_api')));
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
