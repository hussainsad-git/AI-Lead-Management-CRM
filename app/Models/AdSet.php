<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdSet extends TenantModel
{
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
