<?php

namespace App\Models;

class Notification extends TenantModel
{
    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
