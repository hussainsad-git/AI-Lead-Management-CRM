<?php

namespace App\Models;

class SavedView extends TenantModel
{
    protected function casts(): array
    {
        return ['filters' => 'array', 'columns' => 'array'];
    }
}
