<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Global permission catalogue (not tenant data). */
class Permission extends Model
{
    protected $fillable = ['key', 'group', 'description'];
}
