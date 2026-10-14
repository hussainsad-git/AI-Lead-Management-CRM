<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class AudienceSegment extends TenantModel
{
    protected function casts(): array
    {
        return ['definition' => 'array', 'exclusion_segment_ids' => 'array', 'auto_sync' => 'boolean',
            'last_counted_at' => 'datetime', 'last_synced_at' => 'datetime'];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(AudienceSegmentMembership::class);
    }

    public function syncJobs(): HasMany
    {
        return $this->hasMany(AudienceSyncJob::class);
    }

    public function isExclusion(): bool
    {
        return $this->kind === 'exclusion';
    }
}
