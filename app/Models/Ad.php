<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ad extends TenantModel
{
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function adSet(): BelongsTo
    {
        return $this->belongsTo(AdSet::class);
    }
}
