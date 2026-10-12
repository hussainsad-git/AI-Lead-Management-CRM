<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Activity extends TenantModel
{
    protected function casts(): array
    {
        return ['meta' => 'array', 'occurred_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
