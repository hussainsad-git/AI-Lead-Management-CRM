<?php

namespace App\Models;

class LeadForm extends TenantModel
{
    protected function casts(): array
    {
        return ['last_lead_at' => 'datetime', 'last_synced_at' => 'datetime'];
    }
}
