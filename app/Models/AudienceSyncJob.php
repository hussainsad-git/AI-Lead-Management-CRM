<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AudienceSyncJob extends TenantModel
{
    protected function casts(): array
    {
        return ['skipped' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(AudienceSegment::class, 'audience_segment_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
