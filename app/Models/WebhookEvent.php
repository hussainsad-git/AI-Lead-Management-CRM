<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Written before the tenant is known (public endpoint), so not tenant-scoped; always filter by organization_id. */
class WebhookEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'processed_at' => 'datetime'];
    }
}
