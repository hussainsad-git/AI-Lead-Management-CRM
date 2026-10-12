<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only consent ledger. Latest record per channel wins. */
class ConsentRecord extends TenantModel
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
