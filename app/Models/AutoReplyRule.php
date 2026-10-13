<?php

namespace App\Models;

class AutoReplyRule extends TenantModel
{
    protected function casts(): array
    {
        return ['keywords' => 'array', 'stop_ai' => 'boolean', 'is_active' => 'boolean'];
    }
}
