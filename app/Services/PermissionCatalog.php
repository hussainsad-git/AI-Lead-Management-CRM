<?php

namespace App\Services;

use App\Models\Permission;

class PermissionCatalog
{
    public function sync(): void
    {
        foreach (config('leadflow.permissions') as $key => [$group, $description]) {
            Permission::updateOrCreate(['key' => $key], ['group' => $group, 'description' => $description]);
        }
    }
}
