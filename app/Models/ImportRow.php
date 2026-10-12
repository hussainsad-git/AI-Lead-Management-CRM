<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Always accessed through an org-scoped ImportJob. */
class ImportRow extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['raw' => 'array', 'normalized' => 'array', 'messages' => 'array'];
    }
}
