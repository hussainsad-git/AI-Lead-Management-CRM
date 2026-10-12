<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallTask extends TenantModel
{
    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function outcome(): BelongsTo
    {
        return $this->belongsTo(CallOutcome::class, 'call_outcome_id');
    }
}
