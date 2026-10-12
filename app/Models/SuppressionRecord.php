<?php

namespace App\Models;

class SuppressionRecord extends TenantModel
{
    protected function casts(): array
    {
        return ['lifted_at' => 'datetime'];
    }
}
