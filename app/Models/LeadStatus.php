<?php

namespace App\Models;

use App\Enums\StatusSemantic;

class LeadStatus extends TenantModel
{
    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean', 'semantic' => StatusSemantic::class];
    }

    public function leads(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function rank(): int
    {
        return $this->semantic->rank();
    }

    public function isClosed(): bool
    {
        return $this->category !== 'open';
    }
}
