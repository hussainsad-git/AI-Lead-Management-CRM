<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRule extends TenantModel
{
    protected function casts(): array
    {
        return ['conditions' => 'array', 'actions' => 'array', 'is_active' => 'boolean', 'last_run_at' => 'datetime'];
    }

    public function executions(): HasMany
    {
        return $this->hasMany(AutomationExecution::class);
    }
}
