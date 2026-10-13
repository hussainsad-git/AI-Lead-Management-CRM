<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationExecution extends TenantModel
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['results' => 'array', 'created_at' => 'datetime'];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'automation_rule_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
