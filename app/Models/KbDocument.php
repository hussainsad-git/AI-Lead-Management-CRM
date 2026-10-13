<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KbDocument extends TenantModel
{
    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(KbChunk::class)->orderBy('position');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
