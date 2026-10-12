<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportJob extends TenantModel
{
    protected function casts(): array
    {
        return ['headers' => 'array', 'mapping' => 'array', 'options' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
