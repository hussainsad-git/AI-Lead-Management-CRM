<?php

namespace App\Models;

class MessageTemplate extends TenantModel
{
    protected function casts(): array
    {
        return ['components' => 'array', 'synced_at' => 'datetime'];
    }
}
