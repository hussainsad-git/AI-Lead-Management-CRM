<?php

namespace App\Models;

class LeadSource extends TenantModel
{
    protected function casts(): array
    {
        return ['is_paid' => 'boolean', 'is_active' => 'boolean'];
    }
}
