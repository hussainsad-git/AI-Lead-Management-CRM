<?php

namespace App\Models;

class CallOutcome extends TenantModel
{
    protected function casts(): array
    {
        return ['is_connected' => 'boolean', 'creates_callback' => 'boolean', 'is_active' => 'boolean'];
    }
}
