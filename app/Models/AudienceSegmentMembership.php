<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AudienceSegmentMembership extends TenantModel
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['eligible' => 'boolean', 'first_included_at' => 'datetime', 'last_seen_at' => 'datetime', 'removed_at' => 'datetime'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
