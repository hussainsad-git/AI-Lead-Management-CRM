<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

abstract class TenantModel extends Model
{
    use BelongsToOrganization;

    /** organization_id is never mass-assignable; it is stamped from the bound context. */
    protected $guarded = ['id', 'organization_id'];
}
